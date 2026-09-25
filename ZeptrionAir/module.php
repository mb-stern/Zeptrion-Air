<?php

declare(strict_types=1);

class ZeptrionAir extends IPSModuleStrict
{
    private const CHANNEL_TYPES = ['unused', 'light', 'dimmer', 'shutter'];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('DeviceName', '');
        $this->RegisterPropertyString('DeviceType', '');
        $this->RegisterPropertyString('SerialNumber', '');
        $this->RegisterPropertyInteger('Channels', 2);
        $this->RegisterPropertyInteger('PollInterval', 5);
        $this->RegisterTimer('PollTimer', 0, 'ZEPA_Poll($_IPS[\'TARGET\']);');
        // chnotify-Test automatisch ausführen, damit die RAW-Antwort direkt im
        // Instanz-Debug sichtbar wird. Der Aufruf selbst ist ein Long-Poll.
        $this->RegisterTimer('NotifyTestTimer', 0, 'ZEPA_TestChannelNotify($_IPS[\'TARGET\']);');

        for ($channel = 1; $channel <= 4; $channel++) {
            $this->RegisterPropertyString('Channel' . $channel . 'Type', 'unused');
            $this->RegisterPropertyString('Channel' . $channel . 'Name', 'Kanal ' . $channel);
            $this->RegisterPropertyBoolean('Channel' . $channel . 'Scenes', false);
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();
        $this->ApplyChannelVariables();

        if (trim($this->ReadPropertyString('Host')) === '') {
            $this->SetTimerInterval('PollTimer', 0);
            $this->SetTimerInterval('NotifyTestTimer', 0);
            $this->SetStatus(201);
            return;
        }

        $interval = max(1, $this->ReadPropertyInteger('PollInterval'));
        $this->SetTimerInterval('PollTimer', $interval * 1000);
        // Nur zum Test: chnotify automatisch starten. Nach jeder Rückkehr wird
        // nach einer kurzen Pause erneut gewartet.
        $this->SetTimerInterval('NotifyTestTimer', 1000);
        $this->SetStatus(102);

        // Beim Übernehmen sofort einen ersten Status einlesen.
        $this->Poll();
    }

    public function Poll(): void
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return;
        }

        $data = $this->HttpXmlGet('/zrap/chscan');
        if ($data === null) {
            $this->SetStatus(202);
            return;
        }

        $this->SendDebug('Poll', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        $max = max(1, min(4, $this->ReadPropertyInteger('Channels')));
        for ($channel = 1; $channel <= $max; $channel++) {
            $type = strtolower($this->ReadPropertyString('Channel' . $channel . 'Type'));
            $state = $this->ExtractChannelState($data, $channel);
            if ($state === null) {
                continue;
            }

            if (in_array($type, ['light', 'dimmer'], true)) {
                $ident = 'Ch' . $channel . 'Switch';
                $variableID = @$this->GetIDForIdent($ident);
                if ($variableID > 0) {
                    $value = $this->StateToBool($state);
                    if ($value !== null && GetValue($variableID) !== $value) {
                        $this->SetValue($ident, $value);
                    }
                }
            }
            // Store/Markise liefert über chscan keine verlässliche absolute Position.
            // Diese wird später separat über Fahrzeit/chnotify nachgeführt.
        }

        $this->SetStatus(102);
    }

    public function TestChannelNotify(): void
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return;
        }

        $url = 'http://' . $host . '/zrap/chnotify';
        $curl = curl_init();
        if ($curl === false) {
            return;
        }

        $this->SendDebug('chnotify Test', 'Warte auf ' . $url, 0);
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT_MS => 35000,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Connection: close']
        ]);

        $started = microtime(true);
        $response = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $elapsed = round(microtime(true) - $started, 3);

        $this->SendDebug(
            'chnotify RAW',
            'HTTP ' . $httpCode . ' / ' . $elapsed . ' s / ' .
            ($error !== '' ? 'Fehler: ' . $error . ' / ' : '') .
            (string)$response,
            0
        );
    }

    public function RequestAction($Ident, $Value): void
    {
        if (!preg_match('/^Ch([1-4])(Switch|Command|Scene)$/', (string)$Ident, $m)) {
            throw new Exception('Ungültiger Ident: ' . $Ident);
        }

        $channel = (int)$m[1];
        $action = $m[2];
        $this->ValidateChannel($channel);

        switch ($action) {
            case 'Switch':
                $command = (bool)$Value ? 'on' : 'off';
                if ($this->SendCommand($channel, $command)) {
                    $this->SetValue($Ident, (bool)$Value);
                }
                return;

            case 'Command':
                $commands = [
                    0 => 'open',
                    1 => 'move_open_350',
                    2 => 'stop',
                    3 => 'move_close_350',
                    4 => 'close'
                ];
                $value = (int)$Value;
                if (!isset($commands[$value])) {
                    throw new InvalidArgumentException('Unbekannter Store-Befehl');
                }
                if ($this->SendCommand($channel, $commands[$value])) {
                    $this->SetValue($Ident, $value);
                }
                return;

            case 'Scene':
                $scene = (int)$Value;
                if ($scene < 1 || $scene > 4) {
                    throw new InvalidArgumentException('Szene muss zwischen 1 und 4 liegen');
                }
                if ($this->RecallScene($channel, $scene)) {
                    $this->SetValue($Ident, $scene);
                }
                return;
        }
    }

    public function SendCommand(int $Channel, string $Command): bool
    {
        $this->ValidateChannel($Channel);
        $this->ValidateCommand($Command);

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            throw new RuntimeException('Kein Host konfiguriert');
        }

        $url = 'http://' . $host . '/zrap/chctrl/ch' . $Channel;
        $this->SendDebug('SendCommand', 'POST ' . $url . ' cmd=' . $Command, 0);

        $curl = curl_init();
        if ($curl === false) {
            throw new RuntimeException('cURL konnte nicht initialisiert werden');
        }

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => http_build_query(['cmd' => $Command]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded']
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        // chctrl kann laut zrap-API mit Redirect/302 ohne Nutzdaten antworten.
        $success = $response !== false && $httpCode >= 200 && $httpCode < 400;

        $this->SendDebug(
            'SendCommand',
            'HTTP ' . $httpCode . ($error !== '' ? ' / ' . $error : '') . ' / Antwort: ' . (string)$response,
            0
        );

        if (!$success) {
            $this->SetStatus(202);
            return false;
        }

        $this->SetStatus(102);
        return true;
    }

    public function SwitchLight(int $Channel, bool $State): bool
    {
        return $this->SendCommand($Channel, $State ? 'on' : 'off');
    }

    public function ToggleLight(int $Channel): bool
    {
        return $this->SendCommand($Channel, 'toggle');
    }

    public function DimUp(int $Channel): bool
    {
        return $this->SendCommand($Channel, 'dim_up');
    }

    public function DimDown(int $Channel): bool
    {
        return $this->SendCommand($Channel, 'dim_down');
    }

    public function Stop(int $Channel): bool
    {
        return $this->SendCommand($Channel, 'stop');
    }

    public function Open(int $Channel): bool
    {
        return $this->SendCommand($Channel, 'open');
    }

    public function Close(int $Channel): bool
    {
        return $this->SendCommand($Channel, 'close');
    }

    public function MoveOpen(int $Channel, int $Milliseconds = 0): bool
    {
        return $this->SendCommand($Channel, $this->TimedCommand('move_open', $Milliseconds));
    }

    public function MoveClose(int $Channel, int $Milliseconds = 0): bool
    {
        return $this->SendCommand($Channel, $this->TimedCommand('move_close', $Milliseconds));
    }

    public function Dim(int $Channel, string $Direction, int $Milliseconds = 0): bool
    {
        $direction = strtolower(trim($Direction));
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new InvalidArgumentException('Direction muss "up" oder "down" sein');
        }

        return $this->SendCommand(
            $Channel,
            $this->TimedCommand($direction === 'up' ? 'dim_up' : 'dim_down', $Milliseconds)
        );
    }

    public function RecallScene(int $Channel, int $Scene): bool
    {
        return $this->SceneCommand($Channel, 'recall', $Scene);
    }

    public function StoreScene(int $Channel, int $Scene): bool
    {
        return $this->SceneCommand($Channel, 'store', $Scene);
    }

    public function DeleteScene(int $Channel, int $Scene): bool
    {
        return $this->SceneCommand($Channel, 'delete', $Scene);
    }

    private function SceneCommand(int $channel, string $action, int $scene): bool
    {
        if ($scene < 1 || $scene > 4) {
            throw new InvalidArgumentException('Szene muss zwischen 1 und 4 liegen');
        }
        return $this->SendCommand($channel, $action . '_s' . $scene);
    }

    private function TimedCommand(string $command, int $milliseconds): string
    {
        if ($milliseconds === 0) {
            return $command;
        }
        if ($milliseconds < 100 || $milliseconds > 32000) {
            throw new InvalidArgumentException('Zeit muss zwischen 100 und 32000 ms liegen');
        }
        return $command . '_' . $milliseconds;
    }

    private function ValidateCommand(string $command): void
    {
        $simple = ['stop', 'on', 'off', 'toggle', 'dim_up', 'dim_down', 'close', 'open', 'move_close', 'move_open'];
        if (in_array($command, $simple, true)) {
            return;
        }

        if (preg_match('/^(recall|store|delete)_s[1-4]$/', $command)) {
            return;
        }

        if (preg_match('/^(dim_up|dim_down|move_open|move_close)_(\d{3,5})$/', $command, $m)) {
            $time = (int)$m[2];
            if ($time >= 100 && $time <= 32000) {
                return;
            }
        }

        throw new InvalidArgumentException('Nicht unterstützter zeptrionAIR-Befehl: ' . $command);
    }

    private function ValidateChannel(int $channel): void
    {
        $max = max(1, min(4, $this->ReadPropertyInteger('Channels')));
        if ($channel < 1 || $channel > $max) {
            throw new InvalidArgumentException('Kanal ' . $channel . ' ist bei diesem Gerät nicht vorhanden');
        }
    }

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('ZEPA.ShutterCommand')) {
            IPS_CreateVariableProfile('ZEPA.ShutterCommand', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('ZEPA.ShutterCommand', 0, 'Auf', '', -1);
            IPS_SetVariableProfileAssociation('ZEPA.ShutterCommand', 1, 'Lamelle auf', '', -1);
            IPS_SetVariableProfileAssociation('ZEPA.ShutterCommand', 2, 'Stopp', '', -1);
            IPS_SetVariableProfileAssociation('ZEPA.ShutterCommand', 3, 'Lamelle zu', '', -1);
            IPS_SetVariableProfileAssociation('ZEPA.ShutterCommand', 4, 'Ab', '', -1);
        }

        if (!IPS_VariableProfileExists('ZEPA.Scene')) {
            IPS_CreateVariableProfile('ZEPA.Scene', VARIABLETYPE_INTEGER);
            for ($scene = 1; $scene <= 4; $scene++) {
                IPS_SetVariableProfileAssociation('ZEPA.Scene', $scene, 'Szene ' . $scene, '', -1);
            }
        }
    }

    private function HttpXmlGet(string $path): ?array
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return null;
        }

        $url = 'http://' . $host . $path;
        $curl = curl_init();
        if ($curl === false) {
            return null;
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT_MS => 2500,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Connection: close']
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($response === false || $error !== '' || $httpCode < 200 || $httpCode >= 400 || trim((string)$response) === '') {
            $this->SendDebug('Poll Fehler', $url . ' / HTTP ' . $httpCode . ($error !== '' ? ' / ' . $error : ''), 0);
            return null;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string)$response, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            libxml_clear_errors();
            $this->SendDebug('Poll Fehler', 'Ungültiges XML von ' . $url, 0);
            return null;
        }

        $json = json_encode($xml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $data = json_decode((string)$json, true);
        return is_array($data) ? $data : null;
    }

    private function ExtractChannelState(array $data, int $channel): mixed
    {
        $key = 'ch' . $channel;
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entryChannel = (string)($entry['ch'] ?? $entry['channel'] ?? $entry['id'] ?? '');
            if ($entryChannel === (string)$channel || strtolower($entryChannel) === $key) {
                return $entry;
            }
        }

        return null;
    }

    private function StateToBool(mixed $state): ?bool
    {
        if (is_array($state)) {
            foreach (['val', 'value', 'state'] as $key) {
                if (array_key_exists($key, $state)) {
                    return $this->StateToBool($state[$key]);
                }
            }
            return null;
        }

        if (is_bool($state)) {
            return $state;
        }

        $value = strtolower(trim((string)$state));
        if (in_array($value, ['1', 'true', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'off'], true)) {
            return false;
        }

        if (is_numeric($state)) {
            return (float)$state > 0;
        }

        return null;
    }

    private function SetVariableName(string $ident, string $name): void
    {
        $variableID = @$this->GetIDForIdent($ident);
        if ($variableID > 0 && IPS_GetName($variableID) !== $name) {
            IPS_SetName($variableID, $name);
        }
    }

    private function ApplyChannelVariables(): void
    {
        $max = max(1, min(4, $this->ReadPropertyInteger('Channels')));

        for ($channel = 1; $channel <= 4; $channel++) {
            $type = strtolower($this->ReadPropertyString('Channel' . $channel . 'Type'));
            if (!in_array($type, self::CHANNEL_TYPES, true)) {
                $type = 'unused';
            }

            $name = trim($this->ReadPropertyString('Channel' . $channel . 'Name'));
            if ($name === '') {
                $name = 'Kanal ' . $channel;
            }

            $active = $channel <= $max && $type !== 'unused';

            if ($active && in_array($type, ['light', 'dimmer'], true)) {
                $ident = 'Ch' . $channel . 'Switch';
                $this->RegisterVariableBoolean($ident, $name, '~Switch', $channel * 10);
                // RegisterVariable* ändert den Namen einer bereits vorhandenen Variable nicht.
                // Der aus /zrap/chdes gelesene Kanalname soll aber auch nachträglich übernommen werden.
                $this->SetVariableName($ident, $name);
                $this->EnableAction($ident);
            } elseif ($active && $type === 'shutter') {
                $ident = 'Ch' . $channel . 'Command';
                $this->RegisterVariableInteger($ident, $name, 'ZEPA.ShutterCommand', $channel * 10);
                $this->SetVariableName($ident, $name);
                $this->EnableAction($ident);
            }

            if ($active && $this->ReadPropertyBoolean('Channel' . $channel . 'Scenes')) {
                $ident = 'Ch' . $channel . 'Scene';
                $sceneName = $name . ' Szenen';
                $this->RegisterVariableInteger($ident, $sceneName, 'ZEPA.Scene', $channel * 10 + 5);
                $this->SetVariableName($ident, $sceneName);
                $this->EnableAction($ident);
            }
        }
    }
}
