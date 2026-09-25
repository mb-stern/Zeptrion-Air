<?php

declare(strict_types=1);

class ZeptrionAir extends IPSModuleStrict
{
    private const CHANNEL_TYPES = ['unused', 'light', 'dimmer', 'shutter'];

    public function GetCompatibleParents(): string
    {
        // IPSModuleStrict: Die kompatiblen I/O-Parents werden ausschließlich
        // über moduleIDs angegeben. Symcon erstellt daraus die vollständige
        // Parent-Kette und bevorzugt bei nur einem Eintrag den Client Socket.
        return json_encode([
            'moduleIDs' => [
                '{4CB91589-CE01-4700-906F-26320EFCF6C4}'
            ]
        ], JSON_THROW_ON_ERROR);
    }

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
        // Migrationsbereinigung: Dieser Timer existierte kurzzeitig in einer
        // Entwicklungsversion. Registrieren mit 0 deaktiviert einen eventuell
        // noch in bestehenden Instanzen gespeicherten NotifyTimer zuverlässig.
        $this->RegisterTimer('NotifyTimer', 0, '');
        $this->RegisterTimer('NotifyStartTimer', 0, 'ZEPA_StartChannelNotify($_IPS[\'TARGET\']);');
        $this->SetBuffer('NotifyRx', '');
        $this->SetBuffer('NotifyPending', '0');

        for ($channel = 1; $channel <= 4; $channel++) {
            $this->RegisterPropertyString('Channel' . $channel . 'Type', 'unused');
            $this->RegisterPropertyString('Channel' . $channel . 'Name', 'Kanal ' . $channel);
            $this->RegisterPropertyBoolean('Channel' . $channel . 'Scenes', false);
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Auch bei bereits vorhandenen Instanzen einen alten NotifyTimer sofort
        // stilllegen. Damit blockiert ein neuer Long-Poll kein Modulupdate mehr.
        $this->SetTimerInterval('NotifyTimer', 0);
        $this->SetTimerInterval('NotifyStartTimer', 0);

        $this->RegisterProfiles();
        $this->ApplyChannelVariables();

        if (trim($this->ReadPropertyString('Host')) === '') {
            $this->SetTimerInterval('PollTimer', 0);
            $this->SetTimerInterval('NotifyStartTimer', 0);
            $this->SetStatus(201);
            return;
        }

        // Kein zyklisches 5-Sekunden-Polling mehr. chscan wird nur einmal beim
        // Start/Übernehmen gelesen. chnotify testen wir anschließend gezielt,
        // bevor wir die dauerhafte Ereignisverarbeitung implementieren.
        $this->SetTimerInterval('PollTimer', 0);
        $this->SetBuffer('NotifyRx', '');
        $this->SetBuffer('NotifyPending', '0');
        $this->SetStatus(102);

        // Einmaliger Anfangszustand; danach ausschließlich chnotify.
        $this->Poll();

        // Client Socket kurz Zeit zum Verbinden geben. Danach startet der erste
        // HTTP-Long-Poll; weitere Requests werden direkt aus ReceiveData gestartet.
        $this->SetTimerInterval('NotifyStartTimer', 1000);
    }

    public function GetConfigurationForParent(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        return json_encode([
            'URL' => $host !== '' ? 'http://' . $host . '/zrap/chnotify' : '',
            'Interval' => 0,
            'Active' => false
        ], JSON_UNESCAPED_SLASHES);
    }

    public function StartChannelNotify(): void
    {
        $this->SetTimerInterval('NotifyStartTimer', 0);

        if ($this->GetBuffer('NotifyPending') === '1') {
            return;
        }

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '' || !$this->HasActiveParent()) {
            $this->SetTimerInterval('NotifyStartTimer', 2000);
            return;
        }

        $url = 'http://' . $host . '/zrap/chnotify';
        $this->SendDebug('chnotify', 'HTTP-Long-Poll gestartet: ' . $url, 0);
        $this->SetBuffer('NotifyPending', '1');

        // Offizielles Symcon-Datenpaket "Erweitert (HTTP Request)".
        // Die Antwort kommt asynchron über ReceiveData zurück.
        $this->SendDataToParent(json_encode([
            'DataID' => '{D4C1D08F-CD3B-494B-BE18-B36EF73B8F43}',
            'RequestMethod' => 'GET',
            'RequestURL' => $url,
            'RequestData' => '',
            'Timeout' => 35000
        ], JSON_UNESCAPED_SLASHES));
    }

    public function ReceiveData(string $JSONString): string
    {
        $packet = json_decode($JSONString, true);
        if (!is_array($packet) || !array_key_exists('Buffer', $packet)) {
            return '';
        }

        $this->SetBuffer('NotifyPending', '0');
        $body = trim((string)$packet['Buffer']);
        $this->SendDebug('chnotify RAW', $body, 0);

        if ($body !== '') {
            $this->ProcessNotifyXml($body);
        }

        // Jede HTTP-Antwort beendet genau einen Long-Poll. Danach sofort neu starten.
        $this->SetTimerInterval('NotifyStartTimer', 100);
        return '';
    }

    public function Poll(): void
    {
        // Alte PollTimer-Ereignisse können nach einem Modulupdate noch einmal aus
        // dem TimerPool eintreffen. In diesem Fall nichts mehr ausführen.
        if (!$this->HasActiveParent() && !IPS_InstanceExists($this->InstanceID)) {
            return;
        }

        $hostValue = $this->ReadPropertyString('Host');
        if (!is_string($hostValue)) {
            return;
        }
        $host = trim($hostValue);
        if ($host === '') {
            return;
        }

        $data = $this->HttpXmlGet('/zrap/chscan');
        if ($data === null) {
            $this->SetStatus(202);
            return;
        }

        $this->SendDebug('Poll', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        $this->ApplyChannelStates($data, 'chscan');
        $this->SetStatus(102);
    }

    public function TestChannelNotify(): void
    {
        // Bewusst nur manueller Diagnoseaufruf: /zrap/chnotify blockiert bis zu
        // rund 30 Sekunden. Ein zyklischer Modultimer würde dabei PHP-Slots
        // belegen und kann beim Neuladen/Löschen der Instanz seine
        // InstanceInterface verlieren.
        $hostValue = $this->ReadPropertyString('Host');
        if (!is_string($hostValue)) {
            return;
        }
        $host = trim($hostValue);
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

        // Für den manuellen Test die Antwort direkt im Instanz-Debug anzeigen.
        // TestChannelNotify wird nicht automatisch per Timer gestartet, daher gibt
        // es hier keinen dauerhaft blockierenden Long-Poll.
        $message =
            'HTTP ' . $httpCode . ' / ' . $elapsed . ' s / ' .
            ($error !== '' ? 'Fehler: ' . $error . ' / ' : '') . (string)$response;
        $this->SendDebug('chnotify RAW', $message, 0);

        if ($response === false || $error !== '' || $httpCode < 200 || $httpCode >= 400 || trim((string)$response) === '') {
            return;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string)$response, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            libxml_clear_errors();
            $this->SendDebug('chnotify Parse', 'Ungültiges XML', 0);
            return;
        }

        $json = json_encode($xml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $data = json_decode((string)$json, true);
        if (!is_array($data)) {
            return;
        }

        // Alle gelieferten Kanalwerte zusätzlich kompakt ausgeben. Damit sehen
        // wir beim normalen Schalter und anschließend beim DALI-Dimmer sofort,
        // ob nur 0/100 oder auch Zwischenwerte gemeldet werden.
        foreach ($data as $channel => $state) {
            $this->SendDebug(
                'chnotify Wert',
                (string)$channel . ' => ' . json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                0
            );
        }
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

    private function ExtractHttpResponse(string $buffer): ?array
    {
        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            return null;
        }

        $header = substr($buffer, 0, $headerEnd);
        $bodyStart = $headerEnd + 4;

        if (preg_match('/\\r\\nContent-Length:\\s*(\\d+)/i', "\r\n" . $header, $m)) {
            $length = (int)$m[1];
            if (strlen($buffer) < $bodyStart + $length) {
                return null;
            }
            return [
                'body' => substr($buffer, $bodyStart, $length),
                'remaining' => substr($buffer, $bodyStart + $length)
            ];
        }

        // zeptrionAIR liefert normalerweise Content-Length. Chunked wird für den
        // Test ebenfalls unterstützt.
        if (stripos($header, 'Transfer-Encoding: chunked') !== false) {
            $chunked = substr($buffer, $bodyStart);
            $decoded = '';
            $offset = 0;
            while (true) {
                $lineEnd = strpos($chunked, "\r\n", $offset);
                if ($lineEnd === false) {
                    return null;
                }
                $sizeHex = trim(substr($chunked, $offset, $lineEnd - $offset));
                if ($sizeHex === '' || !ctype_xdigit($sizeHex)) {
                    return null;
                }
                $size = hexdec($sizeHex);
                $offset = $lineEnd + 2;
                if ($size === 0) {
                    if (strlen($chunked) < $offset + 2) {
                        return null;
                    }
                    $offset += 2;
                    return [
                        'body' => $decoded,
                        'remaining' => substr($chunked, $offset)
                    ];
                }
                if (strlen($chunked) < $offset + $size + 2) {
                    return null;
                }
                $decoded .= substr($chunked, $offset, $size);
                $offset += $size + 2;
            }
        }

        return null;
    }

    private function ProcessNotifyXml(string $response): void
    {
        if ($response === '') {
            return;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            libxml_clear_errors();
            $this->SendDebug('chnotify Parse', 'Ungültiges XML: ' . $response, 0);
            return;
        }

        $json = json_encode($xml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $data = json_decode((string)$json, true);
        if (!is_array($data)) {
            return;
        }

        foreach ($data as $channel => $state) {
            $this->SendDebug(
                'chnotify Wert',
                (string)$channel . ' => ' . json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                0
            );
        }

        $this->ApplyChannelStates($data, 'chnotify');
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

    private function ApplyChannelStates(array $data, string $source): void
    {
        $max = max(1, min(4, $this->ReadPropertyInteger('Channels')));
        for ($channel = 1; $channel <= $max; $channel++) {
            $state = $this->ExtractChannelState($data, $channel);
            if ($state === null) {
                continue;
            }

            $type = strtolower($this->ReadPropertyString('Channel' . $channel . 'Type'));

            // Für den DALI-Test den gelieferten Rohwert separat sichtbar machen.
            if ($type === 'dimmer') {
                $this->SendDebug(
                    'Dimmer Status',
                    $source . ' / ch' . $channel . ' => ' .
                    json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    0
                );
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
            // Store/Markise wird später separat über chnotify/Fahrzeit ausgewertet.
        }
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
