<?php
declare(strict_types=1);
class ZeptrionAir extends IPSModuleStrict
{
    private const CHANNEL_TYPES = ['unused', 'light', 'dimmer', 'shutter', 'awning'];
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
        $elements = [];
        $host = trim($this->ReadPropertyString('Host'));
        $hostItems = [
            [
                'type' => 'ValidationTextBox',
                'name' => 'Host',
                'caption' => 'IP-Adresse / Hostname'
            ]
        ];
        if ($host !== '') {
            $ip = gethostbyname($host);
            if ($ip === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
                $ip = '';
            }
            $caption = 'Gerät öffnen: http://' . $host . '/';
            if ($ip !== '' && $ip !== $host) {
                $caption .= '  (IP: ' . $ip . ')';
            }
            $hostItems[] = [
                'type' => 'Label',
                'caption' => $caption,
                'link' => true
            ];
        }
        $elements[] = ['type' => 'RowLayout', 'items' => $hostItems];
        $maxChannels = max(1, min(4, $this->ReadPropertyInteger('Channels')));
        $smartButtonAssignments = [];
        $splitterIDs = IPS_GetInstanceListByModuleID('{C7B836D4-9DA7-4C88-9AA0-0E8D4A5B52A1}');
        if ($splitterIDs !== []) {
            try {
                $decodedAssignments = json_decode(ZEPAS_GetSmartButtonAssignments($splitterIDs[0], $this->InstanceID), true);
                if (is_array($decodedAssignments)) {
                    $smartButtonAssignments = array_slice($decodedAssignments, 0, 2);
                }
            } catch (Throwable $e) {
                $this->SendDebug('Smart-Taster Anzeige', $e->getMessage(), 0);
            }
        }
        $smartButtonIndex = 0;
        for ($channel = 1; $channel <= $maxChannels; $channel++) {
            $typeLabel = match (strtolower($this->ReadPropertyString('Channel' . $channel . 'Type'))) {
                'light' => 'Licht',
                'dimmer' => 'Dimmer',
                'shutter' => 'Rollo',
                'awning' => 'Markise',
                default => 'Nicht verwendet'
            };
            if ($typeLabel === 'Nicht verwendet' && isset($smartButtonAssignments[$smartButtonIndex])) {
                $assignment = $smartButtonAssignments[$smartButtonIndex++];
                $items = [
                    [
                        'type' => 'Label',
                        'caption' => 'Art: Smart-Taster'
                    ],
                    [
                        'type' => 'Label',
                        'caption' => 'Szene: ' . (string)($assignment['name'] ?? 'Smart-Taster')
                    ]
                ];
                $targets = is_array($assignment['targets'] ?? null) ? $assignment['targets'] : [];
                foreach ($targets as $target) {
                    $items[] = [
                        'type' => 'Label',
                        'caption' => '→ ' . (string)$target
                    ];
                }
                $items[] = [
                    'type' => 'Button',
                    'caption' => 'Smart-Taster konfigurieren',
                    'link' => true,
                    'onClick' => "echo '/hook/zeptrionair';"
                ];
            } else {
                $items = [
                    [
                        'type' => 'ValidationTextBox',
                        'name' => 'Channel' . $channel . 'Name',
                        'caption' => 'Name'
                    ],
                    [
                        'type' => 'Label',
                        'caption' => 'Art: ' . $typeLabel
                    ]
                ];
                if ($typeLabel === 'Nicht verwendet') {
                    $items[] = [
                        'type' => 'Button',
                        'caption' => 'Smart-Taster konfigurieren',
                        'link' => true,
                        'onClick' => "echo '/hook/zeptrionair';"
                    ];
                }
            }
            $sceneItems = [];
            if ($typeLabel !== 'Nicht verwendet') {
            for ($scene = 1; $scene <= 4; $scene++) {
                $sceneItems[] = [
                    'type' => 'CheckBox',
                    'name' => 'Channel' . $channel . 'Scene' . $scene . 'Visible',
                    'caption' => 'Szene ' . $scene . ' als Variable anzeigen'
                ];
                $sceneItems[] = [
                    'type' => 'ValidationTextBox',
                    'name' => 'Channel' . $channel . 'Scene' . $scene . 'Name',
                    'caption' => 'Szene ' . $scene . ' – Name'
                ];
                $sceneItems[] = [
                    'type' => 'Button',
                    'caption' => 'Neu speichern',
                    'onClick' => 'ZEPA_StoreScene($id, ' . $channel . ', ' . $scene . ');'
                ];
                $sceneItems[] = [
                    'type' => 'Button',
                    'caption' => 'Löschen',
                    'onClick' => 'ZEPA_DeleteScene($id, ' . $channel . ', ' . $scene . ');'
                ];
            }
            $items[] = [
                'type' => 'ExpansionPanel',
                'caption' => 'Szenen verwalten',
                'expanded' => false,
                'items' => $sceneItems
            ];
            }
            $type = strtolower($this->ReadPropertyString('Channel' . $channel . 'Type'));
            if ($type === 'dimmer') {
                $items[] = [
                    'type' => 'ExpansionPanel',
                    'name' => 'Channel' . $channel . 'DimmerConfig',
                    'caption' => 'Dimmer konfigurieren',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'Channel' . $channel . 'UpTimeMs', 'caption' => 'Zeit 0 → 100 % (ms)', 'minimum' => 100, 'maximum' => 32000],
                        ['type' => 'NumberSpinner', 'name' => 'Channel' . $channel . 'DownTimeMs', 'caption' => 'Zeit 100 → 0 % (ms)', 'minimum' => 100, 'maximum' => 32000],
                        ['type' => 'NumberSpinner', 'name' => 'Channel' . $channel . 'StepPercent', 'caption' => 'Schrittweite (%)', 'minimum' => 1, 'maximum' => 100]
                    ]
                ];
            } elseif ($type === 'shutter') {
                $items[] = [
                    'type' => 'ExpansionPanel',
                    'name' => 'Channel' . $channel . 'ShutterConfig',
                    'caption' => 'Rollo konfigurieren',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'Channel' . $channel . 'UpTimeMs', 'caption' => 'Fahrzeit ganz zu → ganz auf (ms)', 'minimum' => 100],
                        ['type' => 'NumberSpinner', 'name' => 'Channel' . $channel . 'DownTimeMs', 'caption' => 'Fahrzeit ganz auf → ganz zu (ms)', 'minimum' => 100],
                        ['type' => 'NumberSpinner', 'name' => 'Channel' . $channel . 'StepPercent', 'caption' => 'Schrittweite Position (%)', 'minimum' => 1, 'maximum' => 100],
                        ['type' => 'NumberSpinner', 'name' => 'Channel' . $channel . 'LamellaTimeMs', 'caption' => 'Lamellenzeit geschlossen → offen (ms)', 'minimum' => 100]
                    ]
                ];
            } elseif ($type === 'awning') {
                $items[] = [
                    'type' => 'ExpansionPanel',
                    'name' => 'Channel' . $channel . 'AwningConfig',
                    'caption' => 'Markise konfigurieren',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'Channel' . $channel . 'UpTimeMs', 'caption' => 'Fahrzeit ganz eingefahren → ganz ausgefahren (ms)', 'minimum' => 100],
                        ['type' => 'NumberSpinner', 'name' => 'Channel' . $channel . 'DownTimeMs', 'caption' => 'Fahrzeit ganz ausgefahren → ganz eingefahren (ms)', 'minimum' => 100],
                        ['type' => 'NumberSpinner', 'name' => 'Channel' . $channel . 'StepPercent', 'caption' => 'Schrittweite Position (%)', 'minimum' => 1, 'maximum' => 100]
                    ]
                ];
            }
            $elements[] = [
                'type' => 'ExpansionPanel',
                'caption' => 'Kanal ' . $channel,
                'items' => $items
            ];
        }
        $elements[] = [
            'type' => 'ExpansionPanel',
            'caption' => 'Variablen',
            'expanded' => true,
            'items' => [
                ['type' => 'CheckBox', 'name' => 'ShowOnline', 'caption' => 'Erreichbar'],
                ['type' => 'CheckBox', 'name' => 'ShowIPAddress', 'caption' => 'IP-Adresse'],
                ['type' => 'CheckBox', 'name' => 'ShowDeviceTypeInfo', 'caption' => 'Gerätetyp'],
                ['type' => 'CheckBox', 'name' => 'ShowSerialNumberInfo', 'caption' => 'Seriennummer'],
                ['type' => 'CheckBox', 'name' => 'ShowSoftwareInfo', 'caption' => 'Software / Firmware'],
                ['type' => 'CheckBox', 'name' => 'ShowRSSI', 'caption' => 'WLAN RSSI'],
                ['type' => 'CheckBox', 'name' => 'ShowChannelActualValues', 'caption' => 'Kanal-Istwerte']
            ]
        ];
        $form = [
            'elements' => $elements,
            'actions' => [
                [
                    'type' => 'Button',
                    'caption' => 'zeptrionAIR neu starten',
                    'onClick' => 'echo ZEPA_RebootDevice($id);'
                ],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
                ['code' => 201, 'icon' => 'inactive', 'caption' => 'IP-Adresse / Hostname fehlt'],
                ['code' => 202, 'icon' => 'error', 'caption' => 'Kommunikationsfehler']
            ]
        ];
        return json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    public function GetCompatibleParents(): string
    {
        return '{"type":"connect","moduleIDs":["{C7B836D4-9DA7-4C88-9AA0-0E8D4A5B52A1}"]}';
    }
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SendDebug('Lifecycle', 'ApplyChanges gestartet', 0);
        $this->SetTimerInterval('SceneResetTimer', 0);
        $this->ApplyChannelVariables();
        $this->ApplyInfoVariables();
        $host = trim($this->ReadPropertyString('Host'));
        $instance = IPS_GetInstance($this->InstanceID);
        if ($host === '') {
            $this->SetTimerInterval('PollTimer', 0);
            $this->SetTimerInterval('InfoTimer', 0);
            $this->SetStatus(201);
            return;
        }
        if ((int)($instance['ConnectionID'] ?? 0) <= 0) {
            $this->SetTimerInterval('PollTimer', 0);
            $this->SetTimerInterval('InfoTimer', 0);
            return;
        }
        $this->WriteAttributeInteger('CommunicationFailures', 0);
        $this->SetStatus(102);
        $this->SetTimerInterval('InfoTimer', 60000);
        if ($this->IsMotorOnlyDevice()) {
            $this->SetTimerInterval('PollTimer', 0);
            $this->SendDebug('Polling', 'Motoraktor erkannt – chscan-Dauerpolling deaktiviert; RSSI-Kommunikationstest alle 60 s', 0);
        } else {
            $this->SetTimerInterval('PollTimer', 5000);
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
            if (!in_array($type, ['shutter', 'awning'], true)) {
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
        $instance = IPS_GetInstance($this->InstanceID);
        if ((int)($instance['ConnectionID'] ?? 0) <= 0) {
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
        $result = $this->SendHttpRequest('POST', '/zrap/sys', ['cmd' => 'reboot'], 3000);
        if (!$result['success']) {
            $this->SendDebug('Geräte-Neustart', 'Nicht gesendet / HTTP ' . $result['httpCode'] . ($result['error'] !== '' ? ' / ' . $result['error'] : ''), 0);
            return 'Neustart konnte nicht ausgelöst werden. Das Gerät antwortet nicht auf die API.';
        }
        $this->SendDebug('Geräte-Neustart', 'Befehl akzeptiert / HTTP ' . $result['httpCode'], 0);
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
                $levelID = $this->FindManagedVariableID($levelIdent);
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
                // Lichtkachel: 0 % bedeutet AUS, 1..100 % bedeutet EIN mit
                // entsprechender Intensität. Der Slider darf deshalb bis 0 gehen.
                $target = max(0, min(100, (int)$Value));
                $step = max(1, min(100, $this->ReadPropertyInteger('Channel' . $channel . 'StepPercent')));
                if ($target > 0) {
                    $target = max($step, min(100, (int)round($target / $step) * $step));
                }
                $ident = 'Ch' . $channel . 'Level';
                $switchIdent = 'Ch' . $channel . 'DimmerSwitch';
                // Die Dimmer-Variablen liegen für die kombinierte Lichtdarstellung
                // unter einem Dummy. Deshalb nicht GetIDForIdent() direkt auf der
                // Modulinstanz verwenden.
                $id = $this->FindManagedVariableID($ident);
                $current = $id > 0 ? (int)GetValue($id) : 0;
                $switchID = $this->FindManagedVariableID($switchIdent);
                $isOn = $switchID > 0 ? (bool)GetValue($switchID) : false;
                // 0 % ist ein echter AUS-Befehl und wird auch als Status 0/false
                // zurückgemeldet.
                if ($target === 0) {
                    if ($this->SendCommand($channel, 'off')) {
                        $this->SetValueIfChanged($ident, 0);
                        $this->SetValueIfChanged($switchIdent, false);
                    }
                    return;
                }
                // Wird die Intensität bei ausgeschaltetem Licht auf >0 gesetzt,
                // fährt der Dimmer aus AUS/0 direkt auf den gewünschten Wert.
                if (!$isOn) {
                    $time = (int)round($target * $this->ReadPropertyInteger('Channel' . $channel . 'UpTimeMs') / 100);
                    $time = max(100, min(32000, $time));
                    if ($this->SendCommand($channel, 'dim_up_' . $time)) {
                        $this->SetValueIfChanged($ident, $target);
                        $this->SetValueIfChanged($switchIdent, true);
                    }
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
                    $this->SetValueIfChanged($switchIdent, true);
                }
                return;
            case 'Position':
                // Rollo: 0 % = ganz offen, 100 % = ganz geschlossen.
                $target = max(0, min(100, (int)$Value));
                $step = max(1, min(100, $this->ReadPropertyInteger('Channel' . $channel . 'StepPercent')));
                $target = max(0, min(100, (int)round($target / $step) * $step));
                $ident = 'Ch' . $channel . 'Position';
                $id = $this->FindManagedVariableID($ident);
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
                    // Nur das Rollo besitzt eine Lamellen-/Drehgradvariable.
                    // Die Markise verwendet dieselbe zeitbasierte Positionsfahrt,
                    // jedoch ohne Dummy und ohne Drehgrad.
                    $channelType = strtolower($this->ReadPropertyString('Channel' . $channel . 'Type'));
                    if ($channelType === 'shutter') {
                        // Bei einer Fahrt ist die Lamellen-Endstellung aus der
                        // Fahrtrichtung ableitbar: hoch = offen, tief = geschlossen.
                        $this->SetValueIfChanged('Ch' . $channel . 'Lamella', $target < $current ? 100 : 0);
                    }
                }
                return;
            case 'Lamella':
                // Berechnete Lamellenstellung: 0 % = geschlossen, 100 % = offen.
                // Die konfigurierte Lamellenzeit beschreibt die komplette Fahrt
                // von geschlossen nach offen (Standard 1000 ms).
                $target = max(0, min(100, (int)$Value));
                $ident = 'Ch' . $channel . 'Lamella';
                $id = $this->FindManagedVariableID($ident);
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
                        $lamellaID = $this->FindManagedVariableID('Ch' . $channel . 'Lamella');
                        $currentLamella = $lamellaID > 0 ? (int)GetValue($lamellaID) : 0;
                        $this->SetValueIfChanged('Ch' . $channel . 'Lamella', min(100, $currentLamella + 33));
                    } elseif ($value === 3) {
                        $lamellaID = $this->FindManagedVariableID('Ch' . $channel . 'Lamella');
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
            $path = '/zrap/chctrl/ch' . $Channel;
            $this->SendDebug('SendCommand', 'POST http://' . $host . $path . ' cmd=' . $Command, 0);
            $result = $this->SendHttpRequest('POST', $path, ['cmd' => $Command], 10000);
            $this->SendDebug('SendCommand', 'HTTP ' . $result['httpCode'] . ($result['error'] !== '' ? ' / ' . $result['error'] : '') . ' / Antwort: ' . $result['raw'], 0);
            return $result['success'];
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
            $result = $this->SendHttpRequest('GET', $path, null, 2500);
            if (!$result['success'] || trim($result['raw']) === '') {
                $this->SendDebug('HTTP Fehler', 'http://' . $host . $path . ' / HTTP ' . $result['httpCode'] . ($result['error'] !== '' ? ' / ' . $result['error'] : ''), 0);
                return null;
            }
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($result['raw'], 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xml === false) {
                libxml_clear_errors();
                $this->SendDebug('HTTP Fehler', 'Ungültiges XML von http://' . $host . $path, 0);
                return null;
            }
            $json = json_encode($xml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $data = json_decode((string)$json, true);
            return is_array($data) ? $data : null;
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
    }
    private function SendHttpRequest(string $method, string $path, ?array $formData, int $timeoutMs): array
    {
        $instance = IPS_GetInstance($this->InstanceID);
        if ((int)($instance['ConnectionID'] ?? 0) <= 0) {
            $this->SendDebug('Splitter', 'Noch keine übergeordnete Instanz verbunden – Anfrage wird übersprungen', 0);
            return ['success' => false, 'raw' => '', 'httpCode' => 0, 'error' => 'Noch kein zeptrionAIR-Splitter verbunden'];
        }
        $payload = json_encode([
            'DataID' => '{8D8D7A31-3A9E-4D8C-B19A-7B4D0E76A201}',
            'Host' => trim($this->ReadPropertyString('Host')),
            'Method' => strtoupper($method),
            'Path' => $path,
            'FormData' => $formData,
            'TimeoutMs' => $timeoutMs
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $parentID = (int)($instance['ConnectionID'] ?? 0);
        if (!IPS_IsInstanceCompatible($this->InstanceID, $parentID)) {
            $this->WriteAttributeBoolean('LastRequestSkipped', true);
            $this->SendDebug('Splitter', 'Parent-Interface ist noch nicht verfügbar – Anfrage wird übersprungen', 0);
            return ['success' => false, 'raw' => '', 'httpCode' => 0, 'error' => 'Parent-Interface noch nicht verfügbar'];
        }
        $response = $this->SendDataToParent((string)$payload);
        $result = json_decode((string)$response, true);
        if (!is_array($result)) {
            return ['success' => false, 'raw' => '', 'httpCode' => 0, 'error' => 'Ungültige Antwort vom zeptrionAIR-Splitter'];
        }
        $error = (string)($result['error'] ?? '');
        if ($error === 'Gerätekommunikation ist belegt') {
            $this->WriteAttributeBoolean('LastRequestSkipped', true);
        }
        return [
            'success' => (bool)($result['success'] ?? false),
            'raw' => (string)($result['raw'] ?? ''),
            'httpCode' => (int)($result['httpCode'] ?? 0),
            'error' => $error
        ];
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
        $variableID = $this->FindManagedVariableID($ident);
        if ($variableID > 0 && GetValue($variableID) !== $value) {
            // RegisterVariable-Statusvariablen wurden für die gemeinsame
            // Jalousie-Darstellung unter die Dummy-Instanz verschoben.
            // SetValue($ident, ...) sucht nur direkt unter der Modulinstanz und
            // findet sie dort nicht mehr. Daher den Modul-internen Wert direkt
            // über die Variablen-ID aktualisieren.
            $this->SetValueByID($variableID, $value);
        }
    }
    private function SetValueByID(int $variableID, mixed $value): void
    {
        // IPS_RequestAction would trigger the actuator again. We only need to
        // update the calculated status here. Temporarily move the registered
        // variable back to its owning module, let IPSModule::SetValue update it,
        // and restore the Dummy parent immediately afterwards.
        $object = IPS_GetObject($variableID);
        $parentID = (int)$object['ParentID'];
        $ident = (string)$object['ObjectIdent'];
        if ($parentID === $this->InstanceID) {
            $this->SetValue($ident, $value);
            return;
        }
        IPS_SetParent($variableID, $this->InstanceID);
        try {
            $this->SetValue($ident, $value);
        } finally {
            IPS_SetParent($variableID, $parentID);
        }
    }
    private function FindManagedVariableID(string $ident): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id !== false && IPS_VariableExists($id)) {
            return $id;
        }
        // Statusvariablen für zusammengefasste Darstellungen können unter einer
        // Dummy-Instanz liegen. Alle direkten Child-Instanzen durchsuchen, damit
        // Dimmer und Rollo unabhängig vom Parent zuverlässig gefunden werden.
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            if (!IPS_InstanceExists($childID)) {
                continue;
            }
            $id = @IPS_GetObjectIDByIdent($ident, $childID);
            if ($id !== false && IPS_VariableExists($id)) {
                return $id;
            }
        }
        return 0;
    }
    private function EnsureDimmerDummy(int $channel, string $name): int
    {
        $ident = 'Ch' . $channel . 'Dimmer';
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id === false || !IPS_InstanceExists($id)) {
            $id = IPS_CreateInstance('{485D0419-BE97-4548-AA9C-C083EB82E61E}');
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, $ident);
            IPS_ApplyChanges($id);
        }
        IPS_SetName($id, $name);
        IPS_SetPosition($id, $channel * 10);
        return $id;
    }
    private function EnsureDimmerVariable(int $dummyID, string $ident, string $name, int $type, array $presentation, int $position): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $dummyID);
        if ($id === false || !IPS_VariableExists($id)) {
            // Bestehende Modulvariable (auch aus einem manuell angelegten Dummy)
            // übernehmen, damit beim Update keine zweite Variable entsteht.
            $existingID = $this->FindManagedVariableID($ident);
            if ($existingID > 0) {
                $id = $existingID;
            } else {
                if ($type === VARIABLETYPE_BOOLEAN) {
                    $this->RegisterVariableBoolean($ident, $name, '', $position);
                } else {
                    $this->RegisterVariableInteger($ident, $name, '', $position);
                }
                $this->EnableAction($ident);
                $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            }
            if ($id !== false && IPS_VariableExists($id)) {
                IPS_SetParent($id, $dummyID);
            }
        }
        if ($id === false || !IPS_VariableExists($id)) {
            throw new Exception('Dimmer-Variable konnte nicht angelegt werden: ' . $ident);
        }
        IPS_SetName($id, $name);
        IPS_SetPosition($id, $position);
        IPS_SetVariableCustomPresentation($id, $presentation);
        return $id;
    }
    private function RemoveDimmerDummy(int $channel): void
    {
        $id = @IPS_GetObjectIDByIdent('Ch' . $channel . 'Dimmer', $this->InstanceID);
        if ($id === false || !IPS_InstanceExists($id)) {
            return;
        }
        foreach (IPS_GetChildrenIDs($id) as $childID) {
            if (IPS_VariableExists($childID)) {
                IPS_DeleteVariable($childID);
            }
        }
        IPS_DeleteInstance($id);
    }
    private function EnsureShutterDummy(int $channel, string $name): int
    {
        $ident = 'Ch' . $channel . 'Shutter';
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id === false || !IPS_InstanceExists($id)) {
            $id = IPS_CreateInstance('{485D0419-BE97-4548-AA9C-C083EB82E61E}');
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, $ident);
            IPS_ApplyChanges($id);
        }
        IPS_SetName($id, $name);
        IPS_SetPosition($id, $channel * 10);
        return $id;
    }
    private function EnsureShutterVariable(int $dummyID, string $ident, string $name, array $presentation, int $position): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $dummyID);
        if ($id === false || !IPS_VariableExists($id)) {
            // Die Variable zuerst als echte Modulvariable registrieren und mit
            // EnableAction() an RequestAction() anbinden. Keine CustomAction.
            $oldID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($oldID !== false && IPS_VariableExists($oldID)) {
                $id = $oldID;
                $this->EnableAction($ident);
            } else {
                $this->RegisterVariableInteger($ident, $name, '', $position);
                $this->EnableAction($ident);
                $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            }
            if ($id !== false && IPS_VariableExists($id)) {
                IPS_SetParent($id, $dummyID);
            }
        }
        if ($id === false || !IPS_VariableExists($id)) {
            throw new Exception('Rollo-Variable konnte nicht angelegt werden: ' . $ident);
        }
        IPS_SetName($id, $name);
        IPS_SetPosition($id, $position);
        IPS_SetVariableCustomPresentation($id, $presentation);
        return $id;
    }
    private function RemoveShutterDummy(int $channel): void
    {
        $id = @IPS_GetObjectIDByIdent('Ch' . $channel . 'Shutter', $this->InstanceID);
        if ($id === false || !IPS_InstanceExists($id)) {
            return;
        }
        foreach (IPS_GetChildrenIDs($id) as $childID) {
            if (IPS_VariableExists($childID)) {
                IPS_DeleteVariable($childID);
            }
        }
        IPS_DeleteInstance($id);
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
                // Für die native Symcon-Lichtdarstellung müssen Status und
                // Intensität gemeinsam unter einer Instanz liegen.
                $dummyID = $this->EnsureDimmerDummy($channel, $name);
                $switchIdent = 'Ch' . $channel . 'DimmerSwitch';
                $this->EnsureDimmerVariable($dummyID, $switchIdent, $name, VARIABLETYPE_BOOLEAN, [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
                    'USAGE_TYPE' => 0
                ], 10);
                $ident = 'Ch' . $channel . 'Level';
                $this->EnsureDimmerVariable($dummyID, $ident, $name . ' Helligkeit', VARIABLETYPE_INTEGER, [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                    'MIN' => 0,
                    'MAX' => 100,
                    'STEP_SIZE' => 10,
                    'USAGE_TYPE' => 2,
                    'PERCENTAGE' => false,
                    'SUFFIX' => ' %'
                ], 20);
            } elseif ($active && $type === 'awning') {
                // Markise: nur eine Positionsvariable direkt unter der Geräteinstanz.
                // Kein Dummy nötig, da es keinen Drehgrad / keine Lamellen gibt.
                $this->RemoveShutterDummy($channel);
                $positionIdent = 'Ch' . $channel . 'Position';
                $this->RegisterVariableInteger($positionIdent, $name, [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SHUTTER,
                    'USAGE_TYPE' => 0,
                    'OPEN_OUTSIDE_VALUE' => 0,
                    'CLOSE_INSIDE_VALUE' => 100,
                    'SUN_POSITION' => 1
                ], $channel * 10);
                $this->SetVariableName($positionIdent, $name);
                $this->EnableAction($positionIdent);
            } elseif ($active && $type === 'shutter') {
                $dummyID = $this->EnsureShutterDummy($channel, $name);
                $positionIdent = 'Ch' . $channel . 'Position';
                $this->EnsureShutterVariable($dummyID, $positionIdent, 'Position', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SHUTTER,
                    'USAGE_TYPE' => 0,
                    'OPEN_OUTSIDE_VALUE' => 0,
                    'CLOSE_INSIDE_VALUE' => 100,
                    'SUN_POSITION' => 1
                ], 10);
                $lamellaIdent = 'Ch' . $channel . 'Lamella';
                $this->EnsureShutterVariable($dummyID, $lamellaIdent, 'Drehgrad', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SHUTTER,
                    'USAGE_TYPE' => 1,
                    // Lamellenlogik des Moduls:
                    // 0 = geschlossen/innen, 100 = offen/außen.
                    // Die Rotation muss daher gegenüber der Rollo-Position
                    // umgekehrt zugeordnet werden.
                    'CLOSE_INSIDE_VALUE' => 0,
                    'OPEN_OUTSIDE_VALUE' => 100,
                    'MAX_ROTATION_INSIDE' => 0,
                    'MAX_ROTATION_OUTSIDE' => 75,
                    'SUN_POSITION' => 1
                ], 20);
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
                'Position' => $active && in_array($type, ['shutter', 'awning'], true),
                'Lamella' => $active && $type === 'shutter',
                'Command' => $active && $type === 'shutter'
            ] as $suffix => $needed) {
                if ($needed) {
                    continue;
                }
                $oldIdent = 'Ch' . $channel . $suffix;
                if (in_array($suffix, ['DimmerSwitch', 'Level', 'Position', 'Lamella'], true)) {
                    $oldID = $this->FindManagedVariableID($oldIdent);
                    if ($oldID > 0 && IPS_VariableExists($oldID)) {
                        IPS_DeleteVariable($oldID);
                    }
                } elseif (@$this->GetIDForIdent($oldIdent) > 0) {
                    $this->UnregisterVariable($oldIdent);
                }
            }
            if (!($active && $type === 'dimmer')) {
                $this->RemoveDimmerDummy($channel);
            }
            if (!($active && $type === 'shutter')) {
                $this->RemoveShutterDummy($channel);
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
