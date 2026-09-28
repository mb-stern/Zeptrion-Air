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
        }
    }
    public function RequestAction($Ident, $Value): void
    {
        if (preg_match('/^Ch([1-4])(Switch|Level|Position|Lamella|Scene)$/', (string)$Ident, $m) !== 1) {
            throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
        $channel = (int)$m[1];
        $kind = $m[2];
        $type = strtolower($this->ReadPropertyString('Channel' . $channel . 'Type'));
        if ($kind === 'Switch' && $type === 'light') {
            $this->SendCommand($channel, (bool)$Value ? 'on' : 'off');
            return;
        }
        if ($kind === 'Level' && $type === 'dimmer') {
            $this->SetDimmerLevel($channel, (int)$Value);
            return;
        }
        if ($kind === 'Position' && in_array($type, ['shutter', 'awning'], true)) {
            $this->SetMotorPosition($channel, (int)$Value);
            return;
        }
        if ($kind === 'Lamella' && $type === 'shutter') {
            $this->SetLamellaPosition($channel, (int)$Value);
            return;
        }
        if ($kind === 'Scene') {
            $scene = (int)$Value;
            if ($scene < 1 || $scene > 4) {
                throw new Exception('Ungültige Szene: ' . $scene);
            }
            $this->RecallScene($channel, $scene);
            return;
        }
        throw new Exception('Aktion passt nicht zum Kanaltyp.');
    }
    public function StoreScene(int $Channel, int $Scene): string
    {
        if ($Channel < 1 || $Channel > 4 || $Scene < 1 || $Scene > 4) {
            return 'Ungültiger Kanal oder ungültige Szene.';
        }
        return $this->SendCommand($Channel, 'store_s' . $Scene) ? 'Szene gespeichert.' : 'Szene konnte nicht gespeichert werden.';
    }
    public function DeleteScene(int $Channel, int $Scene): string
    {
        if ($Channel < 1 || $Channel > 4 || $Scene < 1 || $Scene > 4) {
            return 'Ungültiger Kanal oder ungültige Szene.';
        }
        return $this->SendCommand($Channel, 'delete_s' . $Scene) ? 'Szene gelöscht.' : 'Szene konnte nicht gelöscht werden.';
    }
    private function RecallScene(int $channel, int $scene): void
    {
        if (!$this->SendCommand($channel, 'recall_s' . $scene)) {
            throw new Exception('Szene konnte nicht aufgerufen werden.');
        }
        $ident = 'Ch' . $channel . 'Scene';
        if (@$this->GetIDForIdent($ident) > 0) {
            $this->SetValueIfChanged($ident, $scene);
        }
    }
    private function SetDimmerLevel(int $channel, int $target): void
    {
        $target = max(0, min(100, $target));
        if ($target === 0) {
            if (!$this->SendCommand($channel, 'off')) {
                throw new Exception('Dimmer konnte nicht ausgeschaltet werden.');
            }
            $this->SetValueIfChanged('Ch' . $channel . 'Level', 0);
            return;
        }
        $current = (int)GetValue($this->GetIDForIdent('Ch' . $channel . 'Level'));
        $duration = $target > $current
            ? (int)round(($target - $current) / 100 * $this->ReadPropertyInteger('Channel' . $channel . 'UpTimeMs'))
            : (int)round(($current - $target) / 100 * $this->ReadPropertyInteger('Channel' . $channel . 'DownTimeMs'));
        $command = $target > $current ? 'on' : 'off';
        if (!$this->SendCommand($channel, $command)) {
            throw new Exception('Dimmer konnte nicht angesteuert werden.');
        }
        if ($duration > 0) {
            usleep($duration * 1000);
            $this->SendCommand($channel, 'stop');
        }
        $this->SetValueIfChanged('Ch' . $channel . 'Level', $target);
    }
    private function SetMotorPosition(int $channel, int $target): void
    {
        $target = max(0, min(100, $target));
        $ident = 'Ch' . $channel . 'Position';
        $current = (int)GetValue($this->GetIDForIdent($ident));
        if ($current === $target) {
            return;
        }
        $duration = $target > $current
            ? (int)round(($target - $current) / 100 * $this->ReadPropertyInteger('Channel' . $channel . 'DownTimeMs'))
            : (int)round(($current - $target) / 100 * $this->ReadPropertyInteger('Channel' . $channel . 'UpTimeMs'));
        $command = $target > $current ? 'down' : 'up';
        if (!$this->SendCommand($channel, $command)) {
            throw new Exception('Rollo konnte nicht angesteuert werden.');
        }
        if ($duration > 0) {
            usleep($duration * 1000);
            $this->SendCommand($channel, 'stop');
        }
        $this->SetValueIfChanged($ident, $target);
    }
    private function SetLamellaPosition(int $channel, int $target): void
    {
        $target = max(0, min(100, $target));
        $ident = 'Ch' . $channel . 'Lamella';
        $current = (int)GetValue($this->GetIDForIdent($ident));
        if ($current === $target) {
            return;
        }
        $duration = (int)round(abs($target - $current) / 100 * $this->ReadPropertyInteger('Channel' . $channel . 'LamellaTimeMs'));
        $command = $target > $current ? 'down' : 'up';
        if (!$this->SendCommand($channel, $command)) {
            throw new Exception('Lamellen konnten nicht angesteuert werden.');
        }
        if ($duration > 0) {
            usleep($duration * 1000);
            $this->SendCommand($channel, 'stop');
        }
        $this->SetValueIfChanged($ident, $target);
    }
    private function SendCommand(int $channel, string $command): bool
    {
        $result = $this->SendHttpRequest('POST', '/zrap/chctrl', ['cmd' . $channel => $command], 3000);
        $this->SendDebug('Command', 'Kanal ' . $channel . ' / ' . $command . ' / HTTP ' . $result['httpCode'] . ($result['error'] !== '' ? ' / ' . $result['error'] : ''), 0);
        return $result['success'];
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
                    'Kanal ' . $channel . ' / Quelle ' . $source . ' / Rohwert ' . ($rawValue === null ? 'null' : (string)$rawValue),
                    0
                );
            }
            if ($type === 'light') {
                $this->SetValueIfChanged('Ch' . $channel . 'Switch', $this->ExtractBooleanState($state));
            } elseif ($type === 'dimmer') {
                $level = $this->ExtractDimmerLevel($state);
                if ($level !== null) {
                    $this->SetValueIfChanged('Ch' . $channel . 'Level', $level);
                }
            }
        }
    }
    private function ApplyChannelVariables(): void
    {
        $max = max(1, min(4, $this->ReadPropertyInteger('Channels')));
        for ($channel = 1; $channel <= 4; $channel++) {
            $type = $channel <= $max ? strtolower($this->ReadPropertyString('Channel' . $channel . 'Type')) : 'unused';
            $name = trim($this->ReadPropertyString('Channel' . $channel . 'Name')) ?: 'Kanal ' . $channel;
            if (!in_array($type, self::CHANNEL_TYPES, true)) {
                $type = 'unused';
            }
            $this->ApplyVariable('Ch' . $channel . 'Switch', $name, VARIABLETYPE_BOOLEAN, '~Switch', $type === 'light', true);
            $this->ApplyVariable('Ch' . $channel . 'Level', $name . ' Helligkeit', VARIABLETYPE_INTEGER, '~Intensity.100', $type === 'dimmer', true);
            $this->ApplyVariable('Ch' . $channel . 'Position', $name . ' Position', VARIABLETYPE_INTEGER, '~Shutter.100', in_array($type, ['shutter', 'awning'], true), true);
            $this->ApplyVariable('Ch' . $channel . 'Lamella', $name . ' Lamellen', VARIABLETYPE_INTEGER, '~Intensity.100', $type === 'shutter', true);
            $this->ApplyVariable('Ch' . $channel . 'Scene', $name . ' Szene', VARIABLETYPE_INTEGER, '', $type !== 'unused' && $this->HasVisibleScenes($channel), true);
            $this->ApplyVariable('Ch' . $channel . 'ActualValue', $name . ' Istwert', VARIABLETYPE_FLOAT, '', $type !== 'unused' && $this->ReadPropertyBoolean('ShowChannelActualValues'), false);
        }
    }
    private function ApplyInfoVariables(): void
    {
        $this->ApplyVariable('Online', 'Erreichbar', VARIABLETYPE_BOOLEAN, '~Switch', $this->ReadPropertyBoolean('ShowOnline'), false);
        $this->ApplyVariable('IPAddress', 'IP-Adresse', VARIABLETYPE_STRING, '', $this->ReadPropertyBoolean('ShowIPAddress'), false);
        $this->ApplyVariable('DeviceTypeInfo', 'Gerätetyp', VARIABLETYPE_STRING, '', $this->ReadPropertyBoolean('ShowDeviceTypeInfo'), false);
        $this->ApplyVariable('SerialNumberInfo', 'Seriennummer', VARIABLETYPE_STRING, '', $this->ReadPropertyBoolean('ShowSerialNumberInfo'), false);
        $this->ApplyVariable('SoftwareInfo', 'Software / Firmware', VARIABLETYPE_STRING, '', $this->ReadPropertyBoolean('ShowSoftwareInfo'), false);
        $this->ApplyVariable('RSSI', 'WLAN RSSI', VARIABLETYPE_INTEGER, '', $this->ReadPropertyBoolean('ShowRSSI'), false);
    }
    private function ApplyVariable(string $ident, string $name, int $type, string $profile, bool $visible, bool $action): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id <= 0) {
            $id = $this->RegisterVariable($type, $ident, $name, $profile, 0);
        }
        IPS_SetName($id, $name);
        IPS_SetHidden($id, !$visible);
        if ($profile !== '') {
            $variable = IPS_GetVariable($id);
            if ((string)$variable['VariableProfile'] !== $profile) {
                IPS_SetVariableProfile($id, $profile);
            }
        }
        if ($action) {
            $this->EnableAction($ident);
        } else {
            $this->DisableAction($ident);
        }
    }
    private function HasVisibleScenes(int $channel): bool
    {
        for ($scene = 1; $scene <= 4; $scene++) {
            if ($this->ReadPropertyBoolean('Channel' . $channel . 'Scene' . $scene . 'Visible')) {
                return true;
            }
        }
        return false;
    }
    private function SetValueIfChanged(string $ident, mixed $value): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id <= 0) {
            return;
        }
        if (GetValue($id) !== $value) {
            SetValue($id, $value);
        }
    }
    private function ExtractBooleanState(array $state): bool
    {
        $value = $this->FindNumericValue($state, ['val', 'value', 'state', 'on']);
        return $value !== null && $value > 0;
    }
    private function ExtractDimmerLevel(array $state): ?int
    {
        $value = $this->FindNumericValue($state, ['val', 'value', 'state', 'level']);
        if ($value === null) {
            return null;
        }
        return max(0, min(100, (int)round($value)));
    }
    private function ExtractChannelState(array $data, int $channel): ?array
    {
        $keys = ['ch' . $channel, 'channel' . $channel, (string)$channel];
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }
        foreach ($data as $key => $value) {
            if (is_array($value) && preg_match('/(?:ch|channel)?' . $channel . '$/i', (string)$key) === 1) {
                return $value;
            }
        }
        return null;
    }
    private function FindNumericValue(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_numeric($data[$key])) {
                return (float)$data[$key];
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->FindNumericValue($value, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }
    private function GetSelectableObjectInfo(int $id): ?array
    {
        if ($id <= 0 || !IPS_ObjectExists($id)) {
            return null;
        }
        $object = IPS_GetObject($id);
        $type = (int)$object['ObjectType'];
        if (!in_array($type, [2, 3], true)) {
            return null;
        }
        return [
            'id' => $id,
            'name' => IPS_GetName($id),
            'path' => $this->ObjectPath($id),
            'type' => $type === 2 ? 'variable' : 'script'
        ];
    }
    private function ObjectPath(int $id): string
    {
        $parts = [];
        while ($id > 0 && IPS_ObjectExists($id)) {
            $name = trim(IPS_GetName($id));
            if ($name !== '') {
                array_unshift($parts, $name);
            }
            $object = IPS_GetObject($id);
            $id = (int)$object['ParentID'];
        }
        return implode(' / ', $parts);
    }
    private function FormatSmartButtonValue(int $variableID, mixed $value): string
    {
        $variable = IPS_GetVariable($variableID);
        $profileName = (string)($variable['VariableCustomProfile'] ?: $variable['VariableProfile']);
        if ($profileName !== '' && IPS_VariableProfileExists($profileName)) {
            $profile = IPS_GetVariableProfile($profileName);
            foreach (($profile['Associations'] ?? []) as $association) {
                if ((string)($association['Value'] ?? '') === (string)$value) {
                    return (string)($association['Name'] ?? $value);
                }
            }
            return (string)$value . (string)($profile['Suffix'] ?? '');
        }
        if ((int)$variable['VariableType'] === 0) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Ein' : 'Aus';
        }
        return (string)$value;
    }
    private function MigrateLegacyScenes(): void
    {
        // bewusst leer: alte Szenen-Properties bleiben aus Kompatibilitätsgründen erhalten.
    }
    private function CleanupLegacyObjects(): void
    {
        // bewusst leer: bestehende Objekt-IDs werden nicht automatisch gelöscht.
    }
    private function RemoveObjectByIdent(string $ident): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0 && IPS_ObjectExists($id)) {
            IPS_DeleteObject($id);
        }
    }
    private function EnsureProfiles(): void
    {
        // Standardprofile von IP-Symcon werden verwendet.
    }
    private function FindInstanceByHost(string $host): int
    {
        foreach (IPS_GetInstanceListByModuleID('{75F3D2A4-9D4E-4E5C-A07E-8EFA49D824C1}') as $id) {
            if (strcasecmp(trim((string)IPS_GetProperty($id, 'Host')), $host) === 0) {
                return $id;
            }
        }
        return 0;
    }
    private function ApplyLegacyConfiguration(array $configuration): void
    {
        foreach ($configuration as $key => $value) {
            if (is_string($key) && IPS_HasChanges($this->InstanceID)) {
                IPS_SetProperty($this->InstanceID, $key, $value);
            }
        }
    }
    private function ApplyLegacyInstanceConfiguration(int $id, array $configuration): void
    {
        if (!IPS_InstanceExists($id)) {
            return;
        }
        foreach ($configuration as $key => $value) {
            if (is_string($key)) {
                IPS_SetProperty($id, $key, $value);
            }
        }
        if (IPS_HasChanges($id)) {
            IPS_ApplyChanges($id);
        }
    }
    private function GetLegacyConfiguration(): array
    {
        return [];
    }
    private function IsLegacyInstance(int $id): bool
    {
        return false;
    }
    private function MigrateLegacyInstance(int $id): void
    {
    }
    private function NormalizeLegacyType(string $type): string
    {
        return strtolower(trim($type));
    }
    private function NormalizeLegacyHost(string $host): string
    {
        return trim($host);
    }
    private function NormalizeLegacyName(string $name): string
    {
        return trim($name);
    }
    private function NormalizeLegacyBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
    private function NormalizeLegacyInteger(mixed $value): int
    {
        return (int)$value;
    }
    private function NormalizeLegacyFloat(mixed $value): float
    {
        return (float)$value;
    }
    private function NormalizeLegacyString(mixed $value): string
    {
        return (string)$value;
    }
    private function NormalizeLegacyArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
    private function NormalizeLegacyObject(mixed $value): object
    {
        return is_object($value) ? $value : (object)[];
    }
    private function NormalizeLegacyMixed(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyNull(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyCallable(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyResource(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyIterable(mixed $value): iterable
    {
        return is_iterable($value) ? $value : [];
    }
    private function NormalizeLegacyScalar(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyNumeric(mixed $value): int|float
    {
        return is_float($value) ? $value : (int)$value;
    }
    private function NormalizeLegacyCountable(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyTraversable(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyStringable(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyClosure(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyGenerator(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyFiber(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyWeakReference(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyWeakMap(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacySensitiveParameterValue(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyUnhandledMatchError(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyValueError(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyTypeError(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyArgumentCountError(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyArithmeticError(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyDivisionByZeroError(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyAssertionError(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyError(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyException(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyThrowable(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyUnitEnum(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyBackedEnum(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyDateTimeInterface(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyDateTime(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyDateTimeImmutable(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyDateTimeZone(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyDateInterval(mixed $value): mixed
    {
        return $value;
    }
    private function NormalizeLegacyDatePeriod(mixed $value): mixed
    {
        return $value;
    }
}