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
        // Sichtbarkeit aller erzeugten Variablen ist pro Geräteinstanz konfigurierbar.
        $this->RegisterPropertyBoolean('ShowOnline', true);
        $this->RegisterPropertyBoolean('ShowIPAddress', false);
        $this->RegisterPropertyBoolean('ShowDeviceTypeInfo', false);
        $this->RegisterPropertyBoolean('ShowSerialNumberInfo', false);
        $this->RegisterPropertyBoolean('ShowSoftwareInfo', false);
        $this->RegisterPropertyBoolean('ShowRSSI', true);
        $this->RegisterPropertyBoolean('ShowChannelActualValues', false);
        $this->RegisterPropertyBoolean('ShowScenes', false); // Migration: wird nicht mehr für neue Szenenauswahl verwendet.
        $this->RegisterTimer('PollTimer', 0, 'ZEPA_Poll($_IPS[\'TARGET\']);');
        $this->RegisterTimer('InfoTimer', 0, 'ZEPA_RefreshDeviceInfo($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SceneResetTimer', 0, ''); // Migration: Szenenwert bleibt nun stehen.
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
            $this->RegisterPropertyInteger('Channel' . $channel . 'UpTimeMs', 4000);
            $this->RegisterPropertyInteger('Channel' . $channel . 'DownTimeMs', 4000);
            $this->RegisterPropertyInteger('Channel' . $channel . 'StepPercent', 10);
            $this->RegisterPropertyInteger('Channel' . $channel . 'LamellaTimeMs', 350);
            for ($scene = 1; $scene <= 4; $scene++) {
                $this->RegisterPropertyString('Channel' . $channel . 'Scene' . $scene . 'Name', 'Szene ' . $scene);
                $this->RegisterPropertyBoolean('Channel' . $channel . 'Scene' . $scene . 'Visible', false);
            }
        }
    }

    public function GetConfigurationForm(): string
    {
        $path = __DIR__ . '/form.json';
        $form = json_decode((string)file_get_contents($path), true);
        if (!is_array($form)) {
            return '{}';
        }

        // form.json enthält die Grundstruktur für Kanal 1/2. Bei 4-Kanal-Geräten
        // werden Kanal 3/4 daraus erzeugt. Nicht vorhandene Kanäle erscheinen
        // überhaupt nicht in der Konfiguration.
        $channelTemplates = [];
        $otherElements = [];
        foreach ($form['elements'] ?? [] as $element) {
            if (($element['type'] ?? '') === 'ExpansionPanel'
                && preg_match('/^Kanal ([1-4])$/', (string)($element['caption'] ?? ''), $match)) {
                $channelTemplates[(int)$match[1]] = $element;
            } else {
                $otherElements[] = $element;
            }
        }

        $maxChannels = max(1, min(4, $this->ReadPropertyInteger('Channels')));
        $baseTemplate = $channelTemplates[2] ?? ($channelTemplates[1] ?? null);
        $channelElements = [];

        for ($channel = 1; $channel <= $maxChannels; $channel++) {
            if (isset($channelTemplates[$channel])) {
                $element = $channelTemplates[$channel];
            } elseif ($baseTemplate !== null) {
                $element = $this->CloneChannelFormElement($baseTemplate, 2, $channel);
            } else {
                continue;
            }

            $type = strtolower($this->ReadPropertyString('Channel' . $channel . 'Type'));
            if (isset($element['items']) && is_array($element['items'])) {
                // Dimmer und Rollo verwenden teilweise dieselben Properties für
                // Fahr-/Dimmzeiten. Deshalb darf im Formular immer nur der zum
                // Kanaltyp passende Konfigurationsblock existieren. Zwei Controls
                // mit demselben Property-Namen können sich beim Speichern gegenseitig
                // überschreiben, auch wenn eines davon nur unsichtbar ist.
                $element['items'] = array_values(array_filter(
                    $element['items'],
                    static function (array $item) use ($channel, $type): bool {
                        $name = (string)($item['name'] ?? '');
                        if ($name === 'Channel' . $channel . 'DimmerConfig') {
                            return $type === 'dimmer';
                        }
                        if ($name === 'Channel' . $channel . 'ShutterConfig') {
                            return $type === 'shutter';
                        }
                        return true;
                    }
                ));
                foreach ($element['items'] as &$item) {
                    $name = (string)($item['name'] ?? '');
                    if ($name === 'Channel' . $channel . 'DimmerConfig' ||
                        $name === 'Channel' . $channel . 'ShutterConfig') {
                        $item['visible'] = true;
                    }
                }
                unset($item);
            }
            $channelElements[] = $element;
        }

        // Kanäle zuerst, danach die allgemeinen Geräte-/Variableneinstellungen.
        $prefix = [];
        while ($otherElements !== [] && in_array(($otherElements[0]['name'] ?? ''), ['Host'], true)) {
            $prefix[] = array_shift($otherElements);
        }
        // Der Hinweis direkt nach Host gehört ebenfalls nach oben.
        if ($otherElements !== [] && ($otherElements[0]['type'] ?? '') === 'Label') {
            $prefix[] = array_shift($otherElements);
        }
        $form['elements'] = array_merge($prefix, $channelElements, $otherElements);

        return json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function CloneChannelFormElement(array $value, int $fromChannel, int $toChannel): array
    {
        $replace = static function ($item) use (&$replace, $fromChannel, $toChannel) {
            if (is_array($item)) {
                foreach ($item as $key => $child) {
                    $item[$key] = $replace($child);
                }
                return $item;
            }
            if (!is_string($item)) {
                return $item;
            }

            $item = str_replace('Channel' . $fromChannel, 'Channel' . $toChannel, $item);
            $item = str_replace('Kanal ' . $fromChannel, 'Kanal ' . $toChannel, $item);
            // Szenen-Buttons enthalten den Kanal als Funktionsargument.
            $item = str_replace(', ' . $fromChannel . ', ', ', ' . $toChannel . ', ', $item);
            return $item;
        };

        return $replace($value);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SendDebug('Lifecycle', 'ApplyChanges gestartet', 0);

        // Alte Long-Poll/SSE-Experimente bleiben deaktiviert.
        $this->SetTimerInterval('NotifyTimer', 0);
        $this->SetTimerInterval('NotifyStartTimer', 0);
        $this->SetTimerInterval('SceneResetTimer', 0);

        $this->RegisterProfiles();
        $this->ApplyChannelVariables();
        $this->ApplyInfoVariables();

        if (trim($this->ReadPropertyString('Host')) === '') {
            $this->SetTimerInterval('PollTimer', 0);
            $this->SetTimerInterval('InfoTimer', 0);
            $this->SetTimerInterval('NotifyStartTimer', 0);
            $this->SetStatus(201);
            return;
        }

        $interval = max(1, $this->ReadPropertyInteger('PollInterval'));
        $this->SetTimerInterval('PollTimer', $interval * 1000);
        $this->SetTimerInterval('InfoTimer', 60000);
        $this->SetStatus(102);

        // Anfangszustand und Geräteinformationen sofort einlesen.
        $this->Poll();
        $this->RefreshDeviceInfo();
    }

    public function Poll(): void
    {
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

    public function RebootDevice(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return 'Neustart nicht möglich: Kein Host konfiguriert.';
        }

        // Laut zrap-API startet cmd=reboot nur das WLAN-Gerät neu und behält
        // dessen Konfiguration. Factory-/Network-Reset werden hier bewusst
        // NICHT angeboten.
        $url = 'http://' . $host . '/zrap/sys';
        $curl = curl_init();
        if ($curl === false) {
            return 'Neustart nicht möglich: cURL konnte nicht initialisiert werden.';
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT_MS => 3000,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => http_build_query(['cmd' => 'reboot']),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($response === false || $error !== '' || $httpCode < 200 || $httpCode >= 400) {
            $this->SendDebug('Geräte-Neustart', 'Nicht gesendet / HTTP ' . $httpCode . ($error !== '' ? ' / ' . $error : ''), 0);
            return 'Neustart konnte nicht ausgelöst werden. Das Gerät antwortet nicht auf die API.';
        }

        $this->SendDebug('Geräte-Neustart', 'Befehl akzeptiert / HTTP ' . $httpCode, 0);

        // Ein erfolgreicher POST bestätigt zunächst nur die Annahme des Befehls.
        // Für eine echte Erfolgsmeldung warten wir, bis /zrap/id nach dem Neustart
        // wieder erreichbar ist.
        usleep(1500000);
        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $id = $this->HttpXmlGet('/zrap/id');
            if ($id !== null) {
                $this->SetStatus(102);
                $this->Poll();
                $this->RefreshDeviceInfo();
                $this->SendDebug('Geräte-Neustart', 'Gerät wieder erreichbar', 0);
                return 'Neustart erfolgreich: Das zeptrionAIR-Gerät ist wieder erreichbar.';
            }
            usleep(1000000);
        }

        return 'Neustart wurde ausgelöst, aber das Gerät war nach ca. 14 Sekunden noch nicht wieder erreichbar.';
    }

    public function RefreshDeviceInfo(): void
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return;
        }

        $ip = gethostbyname($host);
        if ($ip === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
            $ip = '';
        }

        $id = $this->HttpXmlGet('/zrap/id');
        $rssi = $this->HttpXmlGet('/zrap/rssi');

        $online = $id !== null || $rssi !== null;
        if ($this->ReadPropertyBoolean('ShowOnline')) {
            $this->SetValueIfChanged('Online', $online);
        }
        if ($this->ReadPropertyBoolean('ShowIPAddress')) {
            $this->SetValueIfChanged('IPAddress', $ip);
        }

        if ($id !== null) {
            if ($this->ReadPropertyBoolean('ShowDeviceTypeInfo')) {
                $this->SetValueIfChanged('DeviceTypeInfo', (string)($id['type'] ?? $this->ReadPropertyString('DeviceType')));
            }
            if ($this->ReadPropertyBoolean('ShowSerialNumberInfo')) {
                $this->SetValueIfChanged('SerialNumberInfo', (string)($id['sn'] ?? $this->ReadPropertyString('SerialNumber')));
            }
            if ($this->ReadPropertyBoolean('ShowSoftwareInfo')) {
                $this->SetValueIfChanged('SoftwareInfo', (string)($id['sw'] ?? ''));
            }
        }

        if ($rssi !== null) {
            $value = $this->FindNumericValue($rssi, ['rssi', 'val', 'value']);
            if ($value !== null) {
                if ($this->ReadPropertyBoolean('ShowRSSI')) {
                    $this->SetValueIfChanged('RSSI', (int)round($value));
                }
            }
        }
    }

    public function RequestAction($Ident, $Value): void
    {
        if (!preg_match('/^Ch([1-4])(Switch|DimmerSwitch|Level|Position|Command|Scene)$/', (string)$Ident, $m)) {
            throw new Exception('Ungültiger Ident: ' . $Ident);
        }

        $channel = (int)$m[1];
        $action = $m[2];
        $this->ValidateChannel($channel);

        switch ($action) {
            case 'DimmerSwitch':
                $switchIdent = 'Ch' . $channel . 'DimmerSwitch';
                $levelIdent = 'Ch' . $channel . 'Level';

                if (!(bool)$Value) {
                    // Ausschalten verändert die zuletzt gewählte Dimmstufe nicht.
                    if ($this->SendCommand($channel, 'off')) {
                        $this->SetValueIfChanged($switchIdent, false);
                    }
                    return;
                }

                // Beim Einschalten bewusst von AUS/0 hochdimmen, damit die Lampe
                // nicht kurz mit voller Helligkeit aufblitzt. Die Helligkeits-
                // variable enthält weiterhin die zuletzt gewählte Dimmstufe.
                $levelID = @$this->GetIDForIdent($levelIdent);
                $target = $levelID > 0 ? (int)GetValue($levelID) : 0;
                if ($target <= 0) {
                    // Für eine noch nie gesetzte Dimmstufe einen kleinen,
                    // sicheren Startwert verwenden.
                    $target = max(1, min(100, $this->ReadPropertyInteger('Channel' . $channel . 'StepPercent')));
                    $this->SetValueIfChanged($levelIdent, $target);
                }
                $time = (int)round($target * $this->ReadPropertyInteger('Channel' . $channel . 'UpTimeMs') / 100);
                $time = max(100, min(32000, $time));
                if ($this->SendCommand($channel, 'dim_up_' . $time)) {
                    $this->SetValueIfChanged($switchIdent, true);
                }
                return;

            case 'Level':
                // Die Helligkeitsvariable bestimmt nur die Dimmstufe. Ein/Aus
                // wird ausschließlich über ChXDimmerSwitch bedient.
                $target = max(0, min(100, (int)$Value));
                $step = max(1, min(100, $this->ReadPropertyInteger('Channel' . $channel . 'StepPercent')));
                $target = max($step, min(100, (int)round($target / $step) * $step));
                $ident = 'Ch' . $channel . 'Level';
                $switchIdent = 'Ch' . $channel . 'DimmerSwitch';
                $id = @$this->GetIDForIdent($ident);
                $current = $id > 0 ? (int)GetValue($id) : $step;

                $switchID = @$this->GetIDForIdent($switchIdent);
                $isOn = $switchID > 0 ? (bool)GetValue($switchID) : false;

                if (!$isOn) {
                    // Im ausgeschalteten Zustand nur die gewünschte Dimmstufe
                    // speichern. Die Lampe bleibt aus.
                    $this->SetValueIfChanged($ident, $target);
                    return;
                }

                if ($target === $current) {
                    return;
                }
                $time = $target > $current
                    ? (int)round(($target - $current) * $this->ReadPropertyInteger('Channel' . $channel . 'UpTimeMs') / 100)
                    : (int)round(($current - $target) * $this->ReadPropertyInteger('Channel' . $channel . 'DownTimeMs') / 100);
                $time = max(100, min(32000, $time));
                $command = $target > $current ? 'dim_up_' . $time : 'dim_down_' . $time;
                if ($this->SendCommand($channel, $command)) {
                    $this->SetValueIfChanged($ident, $target);
                }
                return;

            case 'Position':
                // Rollo: 0 % = ganz offen, 100 % = ganz geschlossen.
                $target = max(0, min(100, (int)$Value));
                $step = max(1, min(100, $this->ReadPropertyInteger('Channel' . $channel . 'StepPercent')));
                $target = max(0, min(100, (int)round($target / $step) * $step));
                $ident = 'Ch' . $channel . 'Position';
                $id = @$this->GetIDForIdent($ident);
                $current = $id > 0 ? (int)GetValue($id) : 0;
                if ($target === $current) {
                    return;
                }
                $time = $target > $current
                    ? (int)round(($target - $current) * $this->ReadPropertyInteger('Channel' . $channel . 'DownTimeMs') / 100)
                    : (int)round(($current - $target) * $this->ReadPropertyInteger('Channel' . $channel . 'UpTimeMs') / 100);
                $time = max(100, min(32000, $time));
                $command = $target > $current ? 'move_close_' . $time : 'move_open_' . $time;
                if ($this->SendCommand($channel, $command)) {
                    $this->SetValueIfChanged($ident, $target);
                }
                return;

            case 'Switch':
                $command = (bool)$Value ? 'on' : 'off';
                if ($this->SendCommand($channel, $command)) {
                    $this->SetValue($Ident, (bool)$Value);
                }
                return;

            case 'Command':
                $commands = [
                    0 => 'open',
                    1 => 'move_open_' . max(100, min(32000, $this->ReadPropertyInteger('Channel' . $channel . 'LamellaTimeMs'))),
                    2 => 'stop',
                    3 => 'move_close_' . max(100, min(32000, $this->ReadPropertyInteger('Channel' . $channel . 'LamellaTimeMs'))),
                    4 => 'close'
                ];
                $value = (int)$Value;
                if (!isset($commands[$value])) {
                    throw new InvalidArgumentException('Unbekannter Store-Befehl');
                }
                if ($this->SendCommand($channel, $commands[$value])) {
                    $this->SetValue($Ident, $value);
                    // Nur die Endlagen sind ohne Positionsrückmeldung sicher bekannt.
                    if ($value === 0) {
                        $this->SetValueIfChanged('Ch' . $channel . 'Position', 0);
                    } elseif ($value === 4) {
                        $this->SetValueIfChanged('Ch' . $channel . 'Position', 100);
                    }
                }
                return;

            case 'Scene':
                $scene = (int)$Value;
                if ($scene === 0) {
                    $this->SetValueIfChanged((string)$Ident, 0);
                    return;
                }
                if ($scene < 1 || $scene > 4) {
                    throw new InvalidArgumentException('Szene muss zwischen 1 und 4 liegen');
                }
                if ($this->RecallScene($channel, $scene)) {
                    $this->SetValueIfChanged((string)$Ident, $scene);
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

    public function ResetSceneVariables(): void
    {
        $this->SetTimerInterval('SceneResetTimer', 0);
        for ($channel = 1; $channel <= 4; $channel++) {
            $ident = 'Ch' . $channel . 'Scene';
            $id = @$this->GetIDForIdent($ident);
            if ($id > 0) {
                $this->SetValueIfChanged($ident, 0);
            }
        }
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
        // Dimmerprofile pro Instanz/Kanal, damit Minimum und Schrittweite der
        // jeweiligen Kanalkonfiguration entsprechen. 0 % ist nicht auswählbar;
        // Ein/Aus wird ausschließlich über ChXDimmerSwitch bedient.
        for ($channel = 1; $channel <= 4; $channel++) {
            $profile = 'ZEPA.Dimmer.' . $this->InstanceID . '.' . $channel;
            if (!IPS_VariableProfileExists($profile)) {
                IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
            }
            // Helligkeit fest von 10 bis 100 % in echten 10-%-Schritten.
            // Ein/Aus ist separat und 0 % gehört deshalb nicht in dieses Profil.
            IPS_SetVariableProfileValues($profile, 10, 100, 10);
            IPS_SetVariableProfileText($profile, '', ' %');
        }

        // Für Store/Rollo verwenden wir die Symcon-Standardprofile:
        // ~Shutter: Position 0 % offen bis 100 % geschlossen.
        // ~ShutterMoveStep: Auf / Schritt auf / Stopp / Schritt zu / Ab.
        // Dadurch erkennt die Visualisierung die Rollo-/Lamellenbedienung nativ.

        // Szenennamen sind pro Geräteinstanz/Kanal unterschiedlich.
        // Daher werden die Profile instanz- und kanalspezifisch angelegt.
        for ($channel = 1; $channel <= 4; $channel++) {
            $profile = 'ZEPA.Scene.' . $this->InstanceID . '.' . $channel;
            if (!IPS_VariableProfileExists($profile)) {
                IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
            }
            // Nur tatsächlich vorhandene Zuordnungen entfernen. Bei einer neuen
            // Instanz ist das Profil leer; ein Löschversuch auf nicht vorhandene
            // Werte erzeugt in Symcon eine Warnung und bricht die Erstellung ab.
            $profileData = IPS_GetVariableProfile($profile);
            foreach ($profileData['Associations'] ?? [] as $association) {
                IPS_SetVariableProfileAssociation($profile, (int)$association['Value'], '', '', -1);
            }
            for ($scene = 1; $scene <= 4; $scene++) {
                if (!$this->ReadPropertyBoolean('Channel' . $channel . 'Scene' . $scene . 'Visible')) {
                    continue;
                }
                $sceneName = trim($this->ReadPropertyString('Channel' . $channel . 'Scene' . $scene . 'Name'));
                if ($sceneName === '') {
                    $sceneName = 'Szene ' . $scene;
                }
                IPS_SetVariableProfileAssociation($profile, $scene, $sceneName, '', -1);
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

            // Rohwert aus /zrap/chscan für jeden vorhandenen Kanal als Istwert.
            $rawValue = $this->FindNumericValue($state, ['val', 'value', 'state']);
            if ($rawValue !== null && $this->ReadPropertyBoolean('ShowChannelActualValues')) {
                $rawIdent = 'Ch' . $channel . 'ActualValue';
                $this->SetValueIfChanged($rawIdent, $rawValue);
            }

            // Für den DALI-Test den gelieferten Rohwert separat sichtbar machen.
            if ($type === 'dimmer') {
                $this->SendDebug(
                    'Dimmer Status',
                    $source . ' / ch' . $channel . ' => ' .
                    json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    0
                );
            }

            if ($type === 'light') {
                $ident = 'Ch' . $channel . 'Switch';
                $variableID = @$this->GetIDForIdent($ident);
                if ($variableID > 0) {
                    $value = $this->StateToBool($state);
                    if ($value !== null && GetValue($variableID) !== $value) {
                        $this->SetValue($ident, $value);
                    }
                }
            } elseif ($type === 'dimmer' && $rawValue !== null) {
                $rawPercent = max(0, min(100, (int)round($rawValue)));
                $this->SendDebug(
                    'Dimmer Rohwert',
                    'ch' . $channel . ' / ' . $source . ' / val=' . $rawPercent .
                    ' (0=Aus, 100=Ein; kein Dimmwert)',
                    0
                );

                // chscan liefert beim Dimmer nur den Schaltzustand.
                // Deshalb aktualisieren wir ausschließlich Ein/Aus. Die zuletzt
                // gewählte Dimmstufe bleibt auch bei 0=Aus unverändert erhalten.
                if ($rawPercent === 0) {
                    $this->SetValueIfChanged('Ch' . $channel . 'DimmerSwitch', false);
                } elseif ($rawPercent === 100) {
                    $this->SetValueIfChanged('Ch' . $channel . 'DimmerSwitch', true);
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

    private function FindNumericValue(mixed $data, array $preferredKeys): ?float
    {
        if (is_numeric($data)) {
            return (float)$data;
        }
        if (!is_array($data)) {
            return null;
        }
        foreach ($preferredKeys as $key) {
            if (array_key_exists($key, $data) && is_numeric($data[$key])) {
                return (float)$data[$key];
            }
        }
        foreach ($data as $value) {
            $found = $this->FindNumericValue($value, $preferredKeys);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    private function SetValueIfChanged(string $ident, mixed $value): void
    {
        $variableID = @$this->GetIDForIdent($ident);
        if ($variableID <= 0) {
            return;
        }
        if (GetValue($variableID) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function SetVariableName(string $ident, string $name): void
    {
        $variableID = @$this->GetIDForIdent($ident);
        if ($variableID > 0 && IPS_GetName($variableID) !== $name) {
            IPS_SetName($variableID, $name);
        }
    }

    private function ApplyInfoVariables(): void
    {
        $variables = [
            ['ShowOnline', 'Online', VARIABLETYPE_BOOLEAN, 'Erreichbar', '~Switch', 1000],
            ['ShowIPAddress', 'IPAddress', VARIABLETYPE_STRING, 'IP-Adresse', '', 1010],
            ['ShowDeviceTypeInfo', 'DeviceTypeInfo', VARIABLETYPE_STRING, 'Gerätetyp', '', 1020],
            ['ShowSerialNumberInfo', 'SerialNumberInfo', VARIABLETYPE_STRING, 'Seriennummer', '', 1030],
            ['ShowSoftwareInfo', 'SoftwareInfo', VARIABLETYPE_STRING, 'Software / Firmware', '', 1040],
            ['ShowRSSI', 'RSSI', VARIABLETYPE_INTEGER, 'WLAN RSSI', '', 1050]
        ];

        foreach ($variables as [$property, $ident, $type, $name, $profile, $position]) {
            if (!$this->ReadPropertyBoolean($property)) {
                $id = @$this->GetIDForIdent($ident);
                if ($id > 0) {
                    $this->UnregisterVariable($ident);
                }
                continue;
            }
            if ($type === VARIABLETYPE_BOOLEAN) {
                $this->RegisterVariableBoolean($ident, $name, $profile, $position);
            } elseif ($type === VARIABLETYPE_INTEGER) {
                $this->RegisterVariableInteger($ident, $name, $profile, $position);
            } else {
                $this->RegisterVariableString($ident, $name, $profile, $position);
            }
        }

        for ($channel = 1; $channel <= 4; $channel++) {
            $ident = 'Ch' . $channel . 'ActualValue';
            $enabled = $this->ReadPropertyBoolean('ShowChannelActualValues')
                && $channel <= max(1, min(4, $this->ReadPropertyInteger('Channels')))
                && strtolower($this->ReadPropertyString('Channel' . $channel . 'Type')) !== 'unused';
            if ($enabled) {
                $name = trim($this->ReadPropertyString('Channel' . $channel . 'Name'));
                $this->RegisterVariableFloat($ident, ($name !== '' ? $name : 'Kanal ' . $channel) . ' Istwert', '', $channel * 10 + 8);
            } else {
                $id = @$this->GetIDForIdent($ident);
                if ($id > 0) {
                    $this->UnregisterVariable($ident);
                }
            }
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

            if ($active && $type === 'light') {
                $ident = 'Ch' . $channel . 'Switch';
                $this->RegisterVariableBoolean($ident, $name, '~Switch', $channel * 10);
                $this->SetVariableName($ident, $name);
                $this->EnableAction($ident);
            } elseif ($active && $type === 'dimmer') {
                $switchIdent = 'Ch' . $channel . 'DimmerSwitch';
                $this->RegisterVariableBoolean($switchIdent, $name, '~Switch', $channel * 10);
                $this->SetVariableName($switchIdent, $name);
                $this->EnableAction($switchIdent);

                $ident = 'Ch' . $channel . 'Level';
                $this->RegisterVariableInteger($ident, $name . ' Helligkeit', 'ZEPA.Dimmer.' . $this->InstanceID . '.' . $channel, $channel * 10 + 1);
                $this->SetVariableName($ident, $name . ' Helligkeit');
                $this->EnableAction($ident);

                // ChXLevel ist ausschließlich unser eigener Soll-/Merkwert.
                // Die 0/100 aus chscan werden nur für ChXDimmerSwitch verwendet
                // und dürfen die Helligkeit nie verändern. Eine neu angelegte
                // Integer-Variable startet in Symcon mit 0; diesen ungültigen
                // Startwert einmalig auf die konfigurierte Mindeststufe anheben.
                $levelID = @$this->GetIDForIdent($ident);
                $step = max(1, min(100, $this->ReadPropertyInteger('Channel' . $channel . 'StepPercent')));
                if ($levelID > 0 && (int)GetValue($levelID) < $step) {
                    $this->SetValueIfChanged($ident, $step);
                }
            } elseif ($active && $type === 'shutter') {
                $positionIdent = 'Ch' . $channel . 'Position';
                $this->RegisterVariableInteger($positionIdent, $name . ' Position', '~Shutter', $channel * 10);
                $this->SetVariableName($positionIdent, $name . ' Position');
                $this->EnableAction($positionIdent);

                $ident = 'Ch' . $channel . 'Command';
                $this->RegisterVariableInteger($ident, $name . ' Bedienung', '~ShutterMoveStep', $channel * 10 + 1);
                $this->SetVariableName($ident, $name . ' Bedienung');
                $this->EnableAction($ident);
            }

            // Nicht mehr zum Kanaltyp passende alte Steuervariablen entfernen.
            foreach ([
                'Switch' => $active && $type === 'light',
                'DimmerSwitch' => $active && $type === 'dimmer',
                'Level' => $active && $type === 'dimmer',
                'Position' => $active && $type === 'shutter',
                'Command' => $active && $type === 'shutter'
            ] as $suffix => $needed) {
                if ($needed) {
                    continue;
                }
                $oldIdent = 'Ch' . $channel . $suffix;
                if (@$this->GetIDForIdent($oldIdent) > 0) {
                    $this->UnregisterVariable($oldIdent);
                }
            }

            $showSceneVariable = false;
            for ($scene = 1; $scene <= 4; $scene++) {
                if ($this->ReadPropertyBoolean('Channel' . $channel . 'Scene' . $scene . 'Visible')) {
                    $showSceneVariable = true;
                    break;
                }
            }

            $sceneIdent = 'Ch' . $channel . 'Scene';
            if ($active && $showSceneVariable) {
                $sceneName = $name . ' Szenen';
                $this->RegisterVariableInteger($sceneIdent, $sceneName, 'ZEPA.Scene.' . $this->InstanceID . '.' . $channel, $channel * 10 + 5);
                $this->SetVariableName($sceneIdent, $sceneName);
                $this->EnableAction($sceneIdent);
            } else {
                $sceneID = @$this->GetIDForIdent($sceneIdent);
                if ($sceneID > 0) {
                    $this->UnregisterVariable($sceneIdent);
                }
            }
        }
    }
}
