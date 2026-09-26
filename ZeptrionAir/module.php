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

        $this->RegisterTimer('InfoTimer', 0, 'ZEPA_RefreshRuntimeInfo($_IPS[\'TARGET\']);');

        $this->RegisterTimer('SceneResetTimer', 0, ''); // Migration: Szenenwert bleibt nun stehen.
        $this->RegisterAttributeInteger('CommunicationFailures', 0);
        $this->RegisterAttributeBoolean('LastRequestSkipped', false);



        for ($channel = 1; $channel <= 4; $channel++) {

            $this->RegisterPropertyString('Channel' . $channel . 'Type', 'unused');

            $this->RegisterPropertyString('Channel' . $channel . 'Name', 'Kanal ' . $channel);

            $this->RegisterPropertyBoolean('Channel' . $channel . 'Scenes', false);

            $this->RegisterPropertyInteger('Channel' . $channel . 'UpTimeMs', 27000);

            $this->RegisterPropertyInteger('Channel' . $channel . 'DownTimeMs', 27000);

            $this->RegisterPropertyInteger('Channel' . $channel . 'StepPercent', 10);

            $this->RegisterPropertyInteger('Channel' . $channel . 'LamellaTimeMs', 1000);

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
        // Das Pollintervall wird automatisch geregelt (5/10/30/60 s)
        // und ist deshalb nicht mehr konfigurierbar.
        if (isset($form['elements']) && is_array($form['elements'])) {
            $form['elements'] = array_values(array_filter(
                $form['elements'],
                static fn(array $element): bool => (string)($element['name'] ?? '') !== 'PollInterval'
            ));
        }

        if (!is_array($form)) {

            return '{}';

        }

        // Den alten Erklärungstext "Die Kanäle entsprechen ..." in allen
        // Konfigurationsbereichen entfernen.
        $removeChannelHint = static function (array &$items) use (&$removeChannelHint): void {
            $items = array_values(array_filter($items, static function (array $item): bool {
                $text = trim((string)($item['caption'] ?? $item['label'] ?? ''));
                return stripos($text, 'Die Kanäle entsprechen') !== 0;
            }));
            foreach ($items as &$item) {
                if (isset($item['items']) && is_array($item['items'])) {
                    $removeChannelHint($item['items']);
                }
            }
            unset($item);
        };
        if (isset($form['elements']) && is_array($form['elements'])) {
            $removeChannelHint($form['elements']);
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



        // Host-Eingabe und anklickbaren Geräte-Link in derselben Zeile anzeigen.

        $prefix = [];

        if ($otherElements !== [] && (($otherElements[0]['name'] ?? '') === 'Host')) {

            $hostElement = array_shift($otherElements);

            $host = trim($this->ReadPropertyString('Host'));

            $rowItems = [$hostElement];

            if ($host !== '') {

                $ip = gethostbyname($host);

                if ($ip === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {

                    $ip = '';

                }

                $caption = 'Gerät öffnen: http://' . $host . '/';

                if ($ip !== '' && $ip !== $host) {

                    $caption .= '  (IP: ' . $ip . ')';

                }

                $rowItems[] = [

                    'type' => 'Label',

                    'caption' => $caption,

                    'link' => true

                ];

            }

            $prefix[] = [

                'type' => 'RowLayout',

                'items' => $rowItems

            ];

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

        $this->SetTimerInterval('SceneResetTimer', 0);



        $this->ApplyChannelVariables();

        $this->ApplyInfoVariables();



        if (trim($this->ReadPropertyString('Host')) === '') {

            $this->SetTimerInterval('PollTimer', 0);

            $this->SetTimerInterval('InfoTimer', 0);

            $this->SetStatus(201);

            return;

        }



        // Polling wird vollständig automatisch geregelt:
        // normal 5 s, bei Fehlern 10 s -> 30 s -> 60 s.
        $this->WriteAttributeInteger('CommunicationFailures', 0);
        $this->SetTimerInterval('InfoTimer', 60000);
        $this->SetStatus(102);

        if ($this->IsMotorOnlyDevice()) {
            $this->SetTimerInterval('PollTimer', 0);
            $this->SendDebug('Polling', 'Motoraktor erkannt – chscan-Dauerpolling deaktiviert; RSSI-Kommunikationstest alle 60 s', 0);
            $this->RefreshDeviceInfo();
        } else {
            $this->SetTimerInterval('PollTimer', 5000);
            $this->Poll();
            if ($this->ReadAttributeInteger('CommunicationFailures') === 0) {
                $this->RefreshDeviceInfo();
            }
        }

    }



    private function IsMotorOnlyDevice(): bool
    {
        $found = false;
        for ($channel = 1; $channel <= 4; $channel++) {
            $type = strtolower(trim($this->ReadPropertyString('Channel' . $channel . 'Type')));
            if ($type === '' || $type === 'unused') {
                continue;
            }
            $found = true;
            if ($type !== 'shutter') {
                return false;
            }
        }
        return $found;
    }

    public function Poll(): void
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return;
        }

        // Reine Motoraktoren liefern über chscan keine verwertbare Position.
        if ($this->IsMotorOnlyDevice()) {
            $this->SetTimerInterval('PollTimer', 0);
            return;
        }

        $data = $this->HttpXmlGet('/zrap/chscan');
        if ($this->ReadAttributeBoolean('LastRequestSkipped')) {
            $this->SendDebug('Poll', 'Übersprungen – Gerätekommunikation läuft bereits', 0);
            return;
        }

        if ($data === null) {
            $failures = $this->ReadAttributeInteger('CommunicationFailures') + 1;
            $this->WriteAttributeInteger('CommunicationFailures', $failures);

            $nextInterval = match ($failures) {
                1 => 10,
                2 => 30,
                default => 60
            };
            $this->SetTimerInterval('PollTimer', $nextInterval * 1000);

            $this->SendDebug(
                'Poll Fehler',
                'Keine Antwort (' . $failures . ') – nächster Status-Poll in ' . $nextInterval . ' s',
                0
            );

            if ($failures >= 3) {
                if ($this->ReadPropertyBoolean('ShowOnline')) {
                    $this->SetValueIfChanged('Online', false);
                }

                // Beim dritten Fehler wechselt die Instanz auf Status 202.
                // Nur beim eigentlichen Statuswechsel eine deutliche Meldung ausgeben,
                // damit der Debug bei weiteren 60-s-Fehlern nicht unnötig vollgeschrieben wird.
                if ($failures === 3) {
                    $message = 'Gerät nicht erreichbar – Kommunikation nach 3 aufeinanderfolgenden Poll-Fehlern abgebrochen';
                    $this->SendDebug('Instanzstatus', $message, 0);
                    $this->LogMessage('Kommunikation zum Gerät abgebrochen', KL_ERROR);
                }
                $this->SetStatus(202);
            }
            return;
        }

        $failures = $this->ReadAttributeInteger('CommunicationFailures');
        if ($failures > 0) {
            $this->WriteAttributeInteger('CommunicationFailures', 0);
            $this->SetTimerInterval('PollTimer', 5000);
            $message = 'Gerät wieder erreichbar – Kommunikation wiederhergestellt, Pollintervall zurück auf 5 s';
            $this->SendDebug('Poll', $message, 0);
            $this->LogMessage('Kommunikation wiederhergestellt', KL_MESSAGE);
        } else {
            // Sicherstellen, dass im gesunden Zustand immer 5 s aktiv sind.
            $this->SetTimerInterval('PollTimer', 5000);
        }

        $this->SendDebug(
            'Poll',
            'Erfolgreich – nächster Poll in 5 s | ' .
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        $this->ApplyChannelStates($data, 'chscan');

        if ($this->ReadPropertyBoolean('ShowOnline')) {
            $this->SetValueIfChanged('Online', true);
        }
        $this->SetStatus(102);
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



    public function RefreshRuntimeInfo(): void
    {
        if ($this->IsMotorOnlyDevice()) {
            $this->RefreshMotorCommunication();
            return;
        }

        $failures = $this->ReadAttributeInteger('CommunicationFailures');
        if ($failures > 0) {
            $this->SendDebug('Info', 'Übersprungen – Status-Poll befindet sich in Fehler-/Erholungsphase (' . $failures . ')', 0);
            return;
        }

        $this->SendDebug('Info', '60-s-Infoabfrage: RSSI', 0);
        $this->RefreshRSSI();
    }

    private function RefreshMotorCommunication(): void
    {
        $this->SendDebug('Info', '60-s-Kommunikationstest Motoraktor: RSSI', 0);
        $rssi = $this->HttpXmlGet('/zrap/rssi');

        if ($this->ReadAttributeBoolean('LastRequestSkipped')) {
            $this->SendDebug('Info', 'RSSI-Test übersprungen – Gerätekommunikation läuft bereits', 0);
            return;
        }

        if ($rssi === null) {
            $failures = $this->ReadAttributeInteger('CommunicationFailures') + 1;
            $this->WriteAttributeInteger('CommunicationFailures', $failures);
            // Motoraktoren werden bewusst nur einmal pro Minute geprüft.
            // Auch bei einem Fehler wird die Abfrage nicht beschleunigt.
            $this->SetTimerInterval('InfoTimer', 60000);
            $this->SendDebug(
                'Kommunikation Fehler',
                'Keine Antwort (' . $failures . ') – nächster Kommunikationstest in 60 s',
                0
            );

            if ($failures >= 3) {
                if ($this->ReadPropertyBoolean('ShowOnline')) {
                    $this->SetValueIfChanged('Online', false);
                }
                if ($failures === 3) {
                    $this->SendDebug('Instanzstatus', 'Gerät nicht erreichbar – 3 aufeinanderfolgende Kommunikationsfehler', 0);
                    $this->LogMessage('Kommunikation zum Gerät abgebrochen', KL_ERROR);
                }
                $this->SetStatus(202);
            }
            return;
        }

        $failures = $this->ReadAttributeInteger('CommunicationFailures');
        if ($failures > 0) {
            $this->WriteAttributeInteger('CommunicationFailures', 0);
            $this->SetTimerInterval('InfoTimer', 60000);
            $this->SendDebug('Kommunikation', 'Gerät wieder erreichbar – Kommunikationstest zurück auf 60 s', 0);
            $this->LogMessage('Kommunikation wiederhergestellt', KL_MESSAGE);
        } else {
            $this->SetTimerInterval('InfoTimer', 60000);
        }

        $value = $this->FindNumericValue($rssi, ['rssi', 'val', 'value']);
        if ($value !== null) {
            $rounded = (int)round($value);
            $this->SendDebug('RSSI', 'Erfolgreich: ' . $rounded . ' dBm', 0);
            if ($this->ReadPropertyBoolean('ShowRSSI')) {
                $this->SetValueIfChanged('RSSI', $rounded);
            }
        }

        if ($this->ReadPropertyBoolean('ShowOnline')) {
            $this->SetValueIfChanged('Online', true);
        }
        $this->SetStatus(102);
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

        // /zrap/id nur beim Start bzw. ApplyChanges lesen. Diese Daten ändern
        // sich im laufenden Betrieb nicht.
        $id = $this->HttpXmlGet('/zrap/id');
        if (!$this->ReadAttributeBoolean('LastRequestSkipped') && $id !== null) {
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

        if ($this->ReadPropertyBoolean('ShowIPAddress')) {
            $this->SetValueIfChanged('IPAddress', $ip);
        }

        $this->RefreshRSSI();
    }

    private function RefreshRSSI(): void
    {
        $rssi = $this->HttpXmlGet('/zrap/rssi');
        if ($this->ReadAttributeBoolean('LastRequestSkipped')) {
            $this->SendDebug('RSSI', 'Übersprungen – Gerätekommunikation läuft bereits', 0);
            return;
        }

        // Ein RSSI-Fehler zählt bewusst nicht als Geräteausfall.
        if ($rssi === null) {
            $this->SendDebug('RSSI', 'Keine Antwort – wird beim nächsten Info-Slot erneut versucht', 0);
            return;
        }

        $value = $this->FindNumericValue($rssi, ['rssi', 'val', 'value']);
        if ($value !== null) {
            $rounded = (int)round($value);
            $this->SendDebug('RSSI', 'Erfolgreich: ' . $rounded . ' dBm', 0);
            if ($this->ReadPropertyBoolean('ShowRSSI')) {
                $this->SetValueIfChanged('RSSI', $rounded);
            }
        } else {
            $this->SendDebug('RSSI', 'Antwort erhalten, aber kein RSSI-Wert gefunden', 0);
        }
        if ($this->ReadPropertyBoolean('ShowOnline')) {
            $this->SetValueIfChanged('Online', true);
        }
    }

    public function RequestAction($Ident, $Value): void

    {

        if (!preg_match('/^Ch([1-4])(Switch|DimmerSwitch|Level|Position|Lamella|Command|Scene)$/', (string)$Ident, $m)) {

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

                    // Bei einer Fahrt ist die Lamellen-Endstellung aus der
                    // Fahrtrichtung ableitbar: hoch = offen, tief = geschlossen.
                    $this->SetValueIfChanged('Ch' . $channel . 'Lamella', $target < $current ? 100 : 0);

                }

                return;


            case 'Lamella':

                // Berechnete Lamellenstellung: 0 % = geschlossen, 100 % = offen.
                // Die konfigurierte Lamellenzeit beschreibt die komplette Fahrt
                // von geschlossen nach offen (Standard 1000 ms).
                $target = max(0, min(100, (int)$Value));
                $ident = 'Ch' . $channel . 'Lamella';
                $id = @$this->GetIDForIdent($ident);
                $current = $id > 0 ? (int)GetValue($id) : 0;

                if ($target === $current) {
                    return;
                }

                $fullTime = max(100, min(32000, $this->ReadPropertyInteger('Channel' . $channel . 'LamellaTimeMs')));
                $time = (int)round(abs($target - $current) * $fullTime / 100);
                $time = max(100, min(32000, $time));
                $command = $target > $current ? 'move_open_' . $time : 'move_close_' . $time;

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

                $lamellaFullTime = max(100, min(32000, $this->ReadPropertyInteger('Channel' . $channel . 'LamellaTimeMs')));
                $lamellaStepTime = max(100, min(32000, (int)round($lamellaFullTime / 3)));

                $commands = [

                    0 => 'open',

                    1 => 'move_open_' . $lamellaStepTime,

                    2 => 'stop',

                    3 => 'move_close_' . $lamellaStepTime,

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
                        $this->SetValueIfChanged('Ch' . $channel . 'Lamella', 100);

                    } elseif ($value === 1) {

                        $lamellaID = @$this->GetIDForIdent('Ch' . $channel . 'Lamella');
                        $currentLamella = $lamellaID > 0 ? (int)GetValue($lamellaID) : 0;
                        $this->SetValueIfChanged('Ch' . $channel . 'Lamella', min(100, $currentLamella + 33));

                    } elseif ($value === 3) {

                        $lamellaID = @$this->GetIDForIdent('Ch' . $channel . 'Lamella');
                        $currentLamella = $lamellaID > 0 ? (int)GetValue($lamellaID) : 100;
                        $this->SetValueIfChanged('Ch' . $channel . 'Lamella', max(0, $currentLamella - 33));

                    } elseif ($value === 4) {

                        $this->SetValueIfChanged('Ch' . $channel . 'Position', 100);
                        $this->SetValueIfChanged('Ch' . $channel . 'Lamella', 0);

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

        $lockName = 'ZEPA_HTTP_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lockName, 3000)) {
            $this->SendDebug('SendCommand', 'Nicht gesendet – Gerätekommunikation ist belegt', 0);
            return false;
        }

        try {
            $url = 'http://' . $host . '/zrap/chctrl/ch' . $Channel;
            $this->SendDebug('SendCommand', 'POST ' . $url . ' cmd=' . $Command, 0);

            $curl = curl_init();
            if ($curl === false) {
                throw new RuntimeException('cURL konnte nicht initialisiert werden');
            }

            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => http_build_query(['cmd' => $Command]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Connection: close']
            ]);

            $response = curl_exec($curl);
            $error = curl_error($curl);
            $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            $success = $response !== false && $httpCode >= 200 && $httpCode < 400;
            $this->SendDebug(
                'SendCommand',
                'HTTP ' . $httpCode . ($error !== '' ? ' / ' . $error : '') . ' / Antwort: ' . (string)$response,
                0
            );

            if (!$success) {
                return false;
            }

            return true;
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
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



    private function HttpXmlGet(string $path): ?array
    {
        $this->WriteAttributeBoolean('LastRequestSkipped', false);
        $lockName = 'ZEPA_HTTP_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lockName, 0)) {
            $this->WriteAttributeBoolean('LastRequestSkipped', true);
            return null;
        }

        try {
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
                $this->SendDebug('HTTP Fehler', $url . ' / HTTP ' . $httpCode . ($error !== '' ? ' / ' . $error : ''), 0);
                return null;
            }

            libxml_use_internal_errors(true);
            $xml = simplexml_load_string((string)$response, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xml === false) {
                libxml_clear_errors();
                $this->SendDebug('HTTP Fehler', 'Ungültiges XML von ' . $url, 0);
                return null;
            }

            $json = json_encode($xml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $data = json_decode((string)$json, true);
            return is_array($data) ? $data : null;
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
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

            ['ShowOnline', 'Online', VARIABLETYPE_BOOLEAN, 'Erreichbar', ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 1000],

            ['ShowIPAddress', 'IPAddress', VARIABLETYPE_STRING, 'IP-Adresse', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 1010],

            ['ShowDeviceTypeInfo', 'DeviceTypeInfo', VARIABLETYPE_STRING, 'Gerätetyp', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 1020],

            ['ShowSerialNumberInfo', 'SerialNumberInfo', VARIABLETYPE_STRING, 'Seriennummer', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 1030],

            ['ShowSoftwareInfo', 'SoftwareInfo', VARIABLETYPE_STRING, 'Software / Firmware', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 1040],

            ['ShowRSSI', 'RSSI', VARIABLETYPE_INTEGER, 'WLAN RSSI', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' dBm'], 1050]

        ];



        foreach ($variables as [$property, $ident, $type, $name, $presentation, $position]) {

            if (!$this->ReadPropertyBoolean($property)) {

                $id = @$this->GetIDForIdent($ident);

                if ($id > 0) {

                    $this->UnregisterVariable($ident);

                }

                continue;

            }

            if ($type === VARIABLETYPE_BOOLEAN) {

                $this->RegisterVariableBoolean($ident, $name, $presentation, $position);

            } elseif ($type === VARIABLETYPE_INTEGER) {

                $this->RegisterVariableInteger($ident, $name, $presentation, $position);

            } else {

                $this->RegisterVariableString($ident, $name, $presentation, $position);

            }

        }



        for ($channel = 1; $channel <= 4; $channel++) {

            $ident = 'Ch' . $channel . 'ActualValue';

            $enabled = $this->ReadPropertyBoolean('ShowChannelActualValues')

                && $channel <= max(1, min(4, $this->ReadPropertyInteger('Channels')))

                && strtolower($this->ReadPropertyString('Channel' . $channel . 'Type')) !== 'unused';

            if ($enabled) {

                $name = trim($this->ReadPropertyString('Channel' . $channel . 'Name'));

                $this->RegisterVariableFloat($ident, ($name !== '' ? $name : 'Kanal ' . $channel) . ' Istwert', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], $channel * 10 + 8);

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

                $this->RegisterVariableBoolean($ident, $name, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], $channel * 10);

                $this->SetVariableName($ident, $name);

                $this->EnableAction($ident);

            } elseif ($active && $type === 'dimmer') {

                $switchIdent = 'Ch' . $channel . 'DimmerSwitch';

                $this->RegisterVariableBoolean($switchIdent, $name, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], $channel * 10);

                $this->SetVariableName($switchIdent, $name);

                $this->EnableAction($switchIdent);



                $ident = 'Ch' . $channel . 'Level';

                // Native Symcon-Darstellung statt Legacy-%-Profil: Bei einem

                // Legacy-Profil mit Suffix "%" skaliert Symcon Min..Max immer

                // auf 0..100 %. Dadurch wurden 10..100 als 0,11,22,...100

                // angezeigt. Der absolute Slider zeigt die echten Werte.

                $this->RegisterVariableInteger($ident, $name . ' Helligkeit', [

                    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,

                    'MIN' => 10,

                    'MAX' => 100,

                    'STEP_SIZE' => 10,

                    'PERCENTAGE' => false,

                    'SUFFIX' => ' %'

                ], $channel * 10 + 1);

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

                // Die native Rollladen-Darstellung ist genau die gewünschte

                // Kachel: Hauptregler = Position, Pfeile = ganz Auf/Zu und

                // Lamellen-Tasten = kurzer Schritt Auf/Zu. Alle Aktionen laufen

                // deshalb über dieselbe Positionsvariable.

                $this->RegisterVariableInteger($positionIdent, $name . ' Position', [

                    'PRESENTATION' => VARIABLE_PRESENTATION_SHUTTER,

                    'USAGE_TYPE' => 0,

                    'OPEN_OUTSIDE_VALUE' => 0,

                    'CLOSE_INSIDE_VALUE' => 100

                ], $channel * 10);

                $this->SetVariableName($positionIdent, $name . ' Position');

                $this->EnableAction($positionIdent);

                // Berechnete Lamellenstellung. zeptrion liefert dafür keine
                // Rückmeldung; 0 % = geschlossen, 100 % = offen.
                $lamellaIdent = 'Ch' . $channel . 'Lamella';
                $this->RegisterVariableInteger($lamellaIdent, $name . ' Lamellen', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                    'MIN' => 0,
                    'MAX' => 100,
                    'STEP_SIZE' => 1,
                    'PERCENTAGE' => false,
                    'SUFFIX' => ' %'
                ], $channel * 10 + 1);
                $this->SetVariableName($lamellaIdent, $name . ' Lamellen');
                $this->EnableAction($lamellaIdent);



                $commandIdent = 'Ch' . $channel . 'Command';

                $this->RegisterVariableInteger($commandIdent, $name . ' Bedienung', [

                    'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,

                    'LAYOUT' => 1,

                    'DISPLAY' => 0,

                    'OPTIONS' => json_encode([

                        ['Value' => 0, 'Caption' => 'Hoch'],

                        ['Value' => 1, 'Caption' => 'Lamellen auf'],

                        ['Value' => 2, 'Caption' => 'Stopp'],

                        ['Value' => 3, 'Caption' => 'Lamellen zu'],

                        ['Value' => 4, 'Caption' => 'Tief']

                    ], JSON_UNESCAPED_UNICODE)

                ], $channel * 10 + 2);

                $this->SetVariableName($commandIdent, $name . ' Bedienung');

                $this->EnableAction($commandIdent);



            }



            // Nicht mehr zum Kanaltyp passende alte Steuervariablen entfernen.

            foreach ([

                'Switch' => $active && $type === 'light',

                'DimmerSwitch' => $active && $type === 'dimmer',

                'Level' => $active && $type === 'dimmer',

                'Position' => $active && $type === 'shutter',

                'Lamella' => false,

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

                $sceneOptions = [];

                for ($scene = 1; $scene <= 4; $scene++) {

                    if (!$this->ReadPropertyBoolean('Channel' . $channel . 'Scene' . $scene . 'Visible')) {

                        continue;

                    }

                    $caption = trim($this->ReadPropertyString('Channel' . $channel . 'Scene' . $scene . 'Name'));

                    $sceneOptions[] = [

                        'Value' => $scene,

                        'Caption' => $caption !== '' ? $caption : 'Szene ' . $scene

                    ];

                }

                $this->RegisterVariableInteger($sceneIdent, $sceneName, [

                    'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,

                    'LAYOUT' => 1,

                    'DISPLAY' => 0,

                    'OPTIONS' => json_encode($sceneOptions, JSON_UNESCAPED_UNICODE)

                ], $channel * 10 + 5);

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
