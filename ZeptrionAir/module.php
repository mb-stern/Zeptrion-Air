<?php
declare(strict_types=1);
class ZeptrionAir extends IPSModule
{
    private const TX = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const CS = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const DEVICE_MODULE_ID = '{75F3D2A4-9D4E-4E5C-A07E-8EFA49D824C1}';
    private const CHANNEL_TYPES = ['unused', 'light', 'dimmer', 'shutter', 'awning'];
    public function Create(): void
    {
        parent::Create();
        $this->RequireParent(self::CS);
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
        $this->RegisterTimer('NotifyTimer', 0, 'ZEPA_NotifyTick($_IPS[\'TARGET\']);'); // Migration, bleibt aus
        $this->RegisterPropertyInteger('RecoveryInterval', 10);
        $this->RegisterTimer('RecoveryTimer', 0, 'ZEPA_RecoveryTick($_IPS[\'TARGET\']);');
        $this->RegisterTimer('LearnKickTimer', 0, 'ZEPA_LearnKickTick($_IPS[\'TARGET\']);');
        $this->RegisterTimer('OwnMotorTimer', 0, 'ZEPA_OwnMotorTick($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SceneResetTimer', 0, ''); // Migration: Szenenwert bleibt nun stehen.
        $this->RegisterAttributeInteger('CommunicationFailures', 0);
        $this->RegisterAttributeBoolean('LastRequestSkipped', false);
        $this->RegisterAttributeString('MotorRuntimeState', '{}');
        $this->RegisterAttributeString('OwnMotorState', '{}');
        $this->RegisterAttributeString('MotorLearnedTimes', '{}');
        $this->RegisterAttributeString('SmartButtonScenes', '[]');
        $this->RegisterAttributeString('SmartButtonToken', '');
        $this->SetBuffer('NotifyBuffer', '');
        $this->SetBuffer('NotifyListening', '0');
        $this->SetBuffer('NotifyPending', '');
        $this->SetBuffer('NotifyOnline', '0');
        $this->RegisterHook($this->SmartButtonHookName());
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
    private function RegisterHook(string $hook): void
    {
        $hook = trim($hook, '/');
        if ($hook === '') {
            return;
        }

        $webHookControls = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F05AE9B93}');
        if ($webHookControls === []) {
            $this->SendDebug('Smart-Taster', 'WebHook Control nicht gefunden', 0);
            return;
        }

        $webHookID = (int)$webHookControls[0];
        $hooks = json_decode(IPS_GetProperty($webHookID, 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }

        $changed = false;
        $found = false;
        foreach ($hooks as &$entry) {
            if (!is_array($entry) || (string)($entry['Hook'] ?? '') !== '/' . $hook) {
                continue;
            }
            $found = true;
            if ((int)($entry['TargetID'] ?? 0) !== $this->InstanceID) {
                $entry['TargetID'] = $this->InstanceID;
                $changed = true;
            }
            break;
        }
        unset($entry);

        if (!$found) {
            $hooks[] = [
                'Hook' => '/' . $hook,
                'TargetID' => $this->InstanceID
            ];
            $changed = true;
        }

        if ($changed) {
            IPS_SetProperty($webHookID, 'Hooks', json_encode($hooks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            IPS_ApplyChanges($webHookID);
        }
    }

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'require', 'moduleIDs' => [self::CS]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function GetConfigurationForParent()
    {
        $host = trim($this->ReadPropertyString('Host'));
        return json_encode([
            'Host' => $host,
            'Port' => 80,
            'Open' => true
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
        $smartButtonAssignments = json_decode($this->GetSmartButtonAssignments($this->InstanceID), true);
        if (!is_array($smartButtonAssignments)) { $smartButtonAssignments = []; }
        $smartButtonAssignments = array_slice($smartButtonAssignments, 0, 2);
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
                    'onClick' => "echo '/hook/" . $this->SmartButtonHookName() . "';"
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
                        'onClick' => "echo '/hook/" . $this->SmartButtonHookName() . "';"
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
                        ['type' => 'NumberSpinner', 'name' => 'Channel' . $channel . 'LamellaTimeMs', 'caption' => 'Lamellenzeit geschlossen → offen (ms)', 'minimum' => 100],
                        ['type' => 'Label', 'caption' => $this->MotorLearnedTimeCaption($channel)],
                        ['type' => 'Button', 'caption' => 'Fahrzeiten automatisch einlernen', 'onClick' => 'echo ZEPA_StartTravelLearning($id, ' . $channel . ');']
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
                        ['type' => 'NumberSpinner', 'name' => 'Channel' . $channel . 'StepPercent', 'caption' => 'Schrittweite Position (%)', 'minimum' => 1, 'maximum' => 100],
                        ['type' => 'Label', 'caption' => $this->MotorLearnedTimeCaption($channel)],
                        ['type' => 'Button', 'caption' => 'Fahrzeiten automatisch einlernen', 'onClick' => 'echo ZEPA_StartTravelLearning($id, ' . $channel . ');']
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
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if ($this->ReadAttributeString('SmartButtonToken') === '') {
            $this->WriteAttributeString('SmartButtonToken', bin2hex(random_bytes(16)));
        }
        $this->RegisterHook($this->SmartButtonHookName());
        $this->SetBuffer('NotifyBuffer', '');
        $this->SetBuffer('NotifyPending', '');
        $this->SetBuffer('NotifyOnline', '0');
        $this->SetBuffer('NotifyListening', '1');
        $this->SetTimerInterval('RecoveryTimer', 0);
        $this->SendDebug('Lifecycle', 'ApplyChanges gestartet', 0);
        // Alte Long-Poll/SSE-Experimente bleiben deaktiviert.
        $this->SetTimerInterval('SceneResetTimer', 0);
        $this->ApplyChannelVariables();
        $this->ApplyInfoVariables();
        if (trim($this->ReadPropertyString('Host')) === '') {
            $this->SetTimerInterval('PollTimer', 0);
            $this->SetTimerInterval('InfoTimer', 0);
            $this->SetTimerInterval('NotifyTimer', 0);
            $this->SetTimerInterval('RecoveryTimer', 0);
            $this->SetStatus(201);
            return;
        }
        // Polling wird vollständig automatisch geregelt:
        // normal 5 s, bei Fehlern 10 s -> 30 s -> 60 s.
        $this->WriteAttributeInteger('CommunicationFailures', 0);
        $this->SetTimerInterval('InfoTimer', 60000);
        $state=$this->ReadMotorState();
        $state['_notifySynced']=false;
        $this->WriteMotorState($state);
        $this->SendDebug('CHNOTIFY','Listener aktiviert',0);
        $this->SetTimerInterval('NotifyTimer', 0);
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
        // Eigener Client Socket: initial chscan, danach dauerhaft chnotify.
        if ($this->HasActiveParent()) {
            $this->StartNotifyListener();
        } else {
            $this->EnterNotifyRecovery('Client Socket noch nicht aktiv');
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
                $target=max(0,min(100,(int)$Value));
                $step=max(1,min(100,$this->ReadPropertyInteger('Channel'.$channel.'StepPercent')));
                $target=max(0,min(100,(int)round($target/$step)*$step));
                $ident='Ch'.$channel.'Position';
                $nowMs=(int)round(microtime(true)*1000);
                $own=$this->GetOwnMotorState($channel);
                if (($own['type']??'')==='position' && $nowMs<=(int)($own['until']??0)) {
                    $current=$this->EstimateOwnPhysicalPosition($channel,$own,$nowMs);
                } else {
                    $id=$this->FindManagedVariableID($ident); $current=$id>0?(int)GetValue($id):0;
                }
                $current=max(0,min(100,$current));
                if ($target===$current) { $this->SetMotorPosition($channel,$target); return; }
                $direction=$target>$current?'down':'up';
                if ($target===0 || $target===100) {
                    $command=$target===0?'open':'close';
                    $duration=$direction==='down'
                        ? (int)round((100-$current)*$this->EffectiveMotorTime($channel,'down')/100)
                        : (int)round($current*$this->EffectiveMotorTime($channel,'up')/100);
                    $duration=max(500,$duration)+1500;
                } else {
                    $duration=$direction==='down'
                        ? (int)round(($target-$current)*$this->EffectiveMotorTime($channel,'down')/100)
                        : (int)round(($current-$target)*$this->EffectiveMotorTime($channel,'up')/100);
                    $duration=max(100,min(32000,$duration));
                    $command=$direction==='down'?'move_close_'.$duration:'move_open_'.$duration;
                }
                if ($this->SendCommand($channel,$command)) {
                    $this->SetMotorPosition($channel,$target);
                    if (strtolower($this->ReadPropertyString('Channel'.$channel.'Type'))==='shutter') {
                        $this->SetValueIfChanged('Ch'.$channel.'Lamella',$direction==='down'?0:100);
                    }
                    $this->SetOwnMotorState($channel,[
                        'type'=>'position','startMs'=>$nowMs,'until'=>$nowMs+$duration,
                        'startPosition'=>$current,'target'=>$target,'direction'=>$direction
                    ]);
                    $motorType=strtolower($this->ReadPropertyString('Channel'.$channel.'Type'));
                    $this->SendDebug($motorType==='awning'?'MARKISE SOLL':'ROLLO SOLL','ch'.$channel.' physisch~'.$current.'% -> Soll '.$target.'% | '.$direction.' | '.$duration.'ms',0);
                }
                return;
            case 'Lamella':
                $target=max(0,min(100,(int)$Value));
                $ident='Ch'.$channel.'Lamella';
                $id=$this->FindManagedVariableID($ident);
                $current=$id>0?(int)GetValue($id):0;
                if ($target===$current) return;

                $nowMs=(int)round(microtime(true)*1000);
                $own=$this->GetOwnMotorState($channel);

                // Waehrend einer eigenen Positionsfahrt darf eine Blenden-
                // aenderung den Behang NICHT stoppen. Nur Sollwert merken und
                // nach Ende der Positionsfahrt ausfuehren.
                if (($own['type']??'')==='position' && $nowMs<=(int)($own['until']??0)) {
                    $own['pendingLamella']=$target;
                    $this->SetOwnMotorState($channel,$own);
                    $this->SetValueIfChanged($ident,$target);
                    $this->SendDebug('LAMELLE PUFFER','ch'.$channel.' Soll='.$target.'% | wird nach Positionsfahrt ausgefuehrt',0);
                    return;
                }

                $this->StartOwnLamellaMove($channel,$target,$current);
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
    private function HttpXmlGet(string $path, int $timeoutMs = 2500): ?array
    {
        $this->WriteAttributeBoolean('LastRequestSkipped', false);
        $lockPrefix = $path === '/zrap/chnotify' ? 'ZEPA_NOTIFY_' : 'ZEPA_HTTP_';
        $lockName = $lockPrefix . $this->InstanceID;
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
                CURLOPT_TIMEOUT_MS => $timeoutMs,
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
    public function NotifyTick(): void
    {
        // FIX8: chnotify is handled by the Splitter/Client Socket.
        // No long-poll and no recovery polling timer in the device instance.
        $this->SetTimerInterval('NotifyTimer', 0);
    }

    public function ProcessNotifyData(string $payload): void
    {
        $xml = @simplexml_load_string(trim($payload));
        if ($xml === false) {
            $this->SendDebug('CHNOTIFY', 'Ungültige XML-Nachricht: ' . $payload, 0);
            return;
        }
        $data = json_decode((string) json_encode($xml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);
        if (!is_array($data)) {
            return;
        }

        $this->SendDebug('CHNOTIFY RX', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
        $max = max(1, min(4, $this->ReadPropertyInteger('Channels')));
        for ($channel = 1; $channel <= $max; $channel++) {
            $chState = $this->ExtractChannelState($data, $channel);
            if ($chState === null) continue;
            $raw = $this->FindNumericValue($chState, ['val', 'value', 'state']);
            if ($raw === null) continue;
            $value = (int) round($raw);
            $type = strtolower($this->ReadPropertyString('Channel' . $channel . 'Type'));
            $this->SendDebug('CHNOTIFY EVENT', 'ch' . $channel . '=' . $value . ' | ' . $type, 0);
            if (in_array($type, ['shutter', 'awning'], true)) {
                $this->ProcessMotorNotify($channel, $value);
            } elseif ($type === 'light') {
                $this->SetValueIfChanged('Ch' . $channel . 'Switch', $value > 0);
            } elseif ($type === 'dimmer') {
                $this->SetValueIfChanged('Ch' . $channel . 'DimmerSwitch', $value > 0);
            }
        }
    }

    public function StartTravelLearning(int $Channel): string
    {
        $this->ValidateChannel($Channel);
        $type = strtolower($this->ReadPropertyString('Channel' . $Channel . 'Type'));
        if (!in_array($type, ['shutter', 'awning'], true)) {
            return 'Einlernen ist nur bei Rollo oder Markise möglich.';
        }
        $state = $this->ReadMotorState();
        foreach ($state as $otherChannel => $otherState) {
            if (!is_array($otherState) || $otherChannel === (string)$Channel) continue;
            $otherLearn=(string)($otherState['learnState']??'idle');
            if ($otherLearn!=='' && $otherLearn!=='idle') {
                return 'Einlernen nicht gestartet: Kanal '.$otherChannel.' wird bereits eingelernt.';
            }
        }
        $key = (string)$Channel;
        $state[$key] = [
            'learnState' => 'reference_wait_start',
            'learnStartMs' => 0,
            'moving' => false,
            'direction' => '',
            'commandDirection' => 'up',
            'commandTarget' => 0
        ];
        $this->WriteMotorState($state);
        $this->SendDebug('LERNEN', 'ch' . $Channel . ': START -> zuerst HOCH bis Endanschlag', 0);
        if (!$this->SendCommand($Channel, 'open')) {
            $state = $this->ReadMotorState();
            $state[$key]['learnState'] = 'idle';
            $this->WriteMotorState($state);
            $this->SetTimerInterval('LearnKickTimer', 0);
            return 'Einlernen konnte nicht gestartet werden.';
        }

        // Die Referenzfahrt kann bereits am oberen Endanschlag starten. In diesem
        // Fall erzeugt zeptrionAIR kein ch=100 START-Ereignis. Nach zwei Sekunden
        // prüfen wir deshalb ausschließlich diesen Sonderfall und starten dann die
        // RUNTER-Messfahrt. Die bestehende chnotify-/Socket-Logik bleibt unverändert.
        $this->SetTimerInterval('LearnKickTimer', 2000);
        return 'Einlernen gestartet: HOCH Referenz → RUNTER messen → HOCH messen.';
    }

    public function LearnKickTick(): void
    {
        $this->SetTimerInterval('LearnKickTimer', 0);
        $state = $this->ReadMotorState();

        foreach ($state as $key => $m) {
            if (!is_array($m) || (string)($m['learnState'] ?? 'idle') !== 'reference_wait_start') {
                continue;
            }

            $channel = (int)$key;
            if ($channel < 1) {
                continue;
            }

            // Kein START nach OPEN: Der Behang war bereits oben. Damit ist der
            // obere Endanschlag unser Synchronpunkt; nun die vollständige
            // Abwärtsfahrt messen.
            $this->SetMotorPosition($channel, 0);
            $m['learnState'] = 'down_wait_start';
            $m['learnStartMs'] = 0;
            $m['commandDirection'] = 'down';
            $m['commandTarget'] = 100;
            $state[$key] = $m;
            $this->WriteMotorState($state);

            $this->SendDebug('LERNEN', 'ch'.$channel.': kein HOCH-Start erkannt -> oberer Anschlag angenommen -> RUNTER Messfahrt', 0);
            if (!$this->SendCommand($channel, 'close')) {
                $state = $this->ReadMotorState();
                if (isset($state[$key]) && is_array($state[$key])) {
                    $state[$key]['learnState'] = 'idle';
                    $this->WriteMotorState($state);
                }
                $this->SendDebug('LERNEN', 'ch'.$channel.': RUNTER Messfahrt konnte nicht gestartet werden', 0);
            }
            return;
        }
    }

    private function ProcessMotorNotify(int $channel, int $value): void
    {
        if ($value !== 0 && $value !== 100) return;
        $state = $this->ReadMotorState();
        $key = (string)$channel;
        $m = is_array($state[$key] ?? null) ? $state[$key] : [];
        $learn = (string)($m['learnState'] ?? 'idle');
        $now = (int)round(microtime(true) * 1000);

        if ($learn !== '' && $learn !== 'idle') {
            if ($value === 100) {
                if ($learn === 'reference_wait_start') {
                    $this->SetTimerInterval('LearnKickTimer', 0);
                    $m['learnState']='reference_running'; $m['learnStartMs']=$now;
                    $this->SendDebug('LERNEN','ch'.$channel.': Referenzfahrt HOCH läuft',0);
                } elseif ($learn === 'down_wait_start') {
                    $m['learnState']='down_running'; $m['learnStartMs']=$now;
                    $this->SendDebug('LERNEN','ch'.$channel.': RUNTER Zeitmessung gestartet',0);
                } elseif ($learn === 'up_wait_start') {
                    $m['learnState']='up_running'; $m['learnStartMs']=$now;
                    $this->SendDebug('LERNEN','ch'.$channel.': HOCH Zeitmessung gestartet',0);
                }
                $state[$key]=$m; $this->WriteMotorState($state); return;
            }
            if ($learn === 'reference_running') {
                $this->SetMotorPosition($channel,0);
                $m['learnState']='down_wait_start'; $m['learnStartMs']=0;
                $state[$key]=$m; $this->WriteMotorState($state);
                $this->SendDebug('LERNEN','ch'.$channel.': oberer Anschlag = 0% -> RUNTER Messfahrt',0);
                $this->SendCommand($channel,'close'); return;
            }
            if ($learn === 'down_running') {
                $elapsed=max(1,$now-(int)($m['learnStartMs']??$now));
                $this->WriteLearnedMotorTime($channel,'down',$elapsed);
                $this->SetMotorPosition($channel,100);
                $m['learnState']='up_wait_start'; $m['learnStartMs']=0;
                $state[$key]=$m; $this->WriteMotorState($state);
                $this->SendDebug('LERNEN','ch'.$channel.': RUNTER='.number_format($elapsed/1000,3,'.','').'s -> 100% | HOCH Messfahrt',0);
                $this->SendCommand($channel,'open'); return;
            }
            if ($learn === 'up_running') {
                $elapsed=max(1,$now-(int)($m['learnStartMs']??$now));
                $this->WriteLearnedMotorTime($channel,'up',$elapsed);
                $this->SetMotorPosition($channel,0);
                $m['learnState']='idle'; $m['learnStartMs']=0; $m['lastDirection']='up';
                unset($m['commandDirection'],$m['commandTarget']);
                $state[$key]=$m; $this->WriteMotorState($state);
                $t=$this->ReadLearnedMotorTimes()[$key]??[];
                // Die gemessenen Werte zusätzlich dauerhaft in die sichtbare
                // Instanzkonfiguration übernehmen. Die Attribute bleiben die
                // Laufzeitquelle; die Properties zeigen danach denselben Stand.
                $learnedUp=max(100,(int)($t['up']??$elapsed));
                $learnedDown=max(100,(int)($t['down']??0));
                IPS_SetProperty($this->InstanceID, 'Channel'.$channel.'UpTimeMs', $learnedUp);
                IPS_SetProperty($this->InstanceID, 'Channel'.$channel.'DownTimeMs', $learnedDown);
                // IPS_SetProperty schreibt die neue Konfiguration zunächst nur vor.
                // Erst ApplyChanges übernimmt sie dauerhaft und aktualisiert auch
                // die im Konfigurationsformular sichtbaren NumberSpinner.
                IPS_ApplyChanges($this->InstanceID);
                $this->SendDebug('LERNEN','ch'.$channel.': FERTIG | RUNTER '.number_format($learnedDown/1000,3,'.','').'s | HOCH '.number_format($learnedUp/1000,3,'.','').'s | Werte dauerhaft in Konfiguration übernommen | Position 0%',0);
                return;
            }
            return;
        }

        // chnotify waehrend einer EIGENEN Variablenbedienung ist ausschliesslich
        // Rueckmeldung. Es darf weder Position noch Blende schreiben.
        $own=$this->GetOwnMotorState($channel);
        if ((bool)($own['active']??false)) {
            $until=(int)($own['until']??0);
            if ($now <= $until) {
                $this->SendDebug('OWN MOTOR','ch'.$channel.' chnotify Event='.$value.' ignoriert fuer Variablen | Typ='.(string)($own['type']??''),0);
                return;
            }
            $this->ClearOwnMotorState($channel);
        }

        if ($value === 100) {
            $position=array_key_exists('commandStartPosition',$m) ? max(0,min(100,(int)$m['commandStartPosition'])) : $this->GetMotorPosition($channel);
            $direction=(string)($m['commandDirection']??'');
            if ($direction==='') {
                // Externer Schalter: An den Endlagen ist die Richtung physisch
                // eindeutig. 100%=unten -> nur HOCH moeglich; 0%=oben -> nur
                // RUNTER moeglich. (Positionsskala: 0 offen/oben, 100 unten.)
                $direction=$position===100?'up':($position===0?'down':'unknown');
            }
            if (strtolower($this->ReadPropertyString('Channel'.$channel.'Type'))==='shutter') {
                if ($direction==='down') $this->SetValueIfChanged('Ch'.$channel.'Lamella',0);
                elseif ($direction==='up') $this->SetValueIfChanged('Ch'.$channel.'Lamella',100);
            }
            $m['moving']=true; $m['moveStartMs']=$now; $m['direction']=$direction;
            $state[$key]=$m; $this->WriteMotorState($state);
            $this->SendDebug('ROLLO','ch'.$channel.' START | Position='.$position.'% | Richtung='.$direction,0);
            return;
        }
        if (!(bool)($m['moving']??false)) return;

        $elapsed=max(0,$now-(int)($m['moveStartMs']??$now));
        $position=array_key_exists('commandStartPosition',$m) ? max(0,min(100,(int)$m['commandStartPosition'])) : $this->GetMotorPosition($channel);
        $direction=(string)($m['direction']??'unknown');
        $commandTarget=array_key_exists('commandTarget',$m)?(int)$m['commandTarget']:null;
        $up=$this->EffectiveMotorTime($channel,'up');
        $down=$this->EffectiveMotorTime($channel,'down');
        $expectUp=(int)round($position*$up/100);
        $expectDown=(int)round((100-$position)*$down/100);
        $errUp=abs($elapsed-$expectUp); $errDown=abs($elapsed-$expectDown);

        $type=strtolower($this->ReadPropertyString('Channel'.$channel.'Type'));
        $lamellaTime=max(100,min(32000,$this->ReadPropertyInteger('Channel'.$channel.'LamellaTimeMs')));
        $lastDirection=(string)($m['lastDirection']??'');

        // Kurze EXTERNE Gegenfahrt: nur Blende, keine Positionsaenderung.
        $endPositionForcesTravel =
            ($position===0 && $direction==='down') ||
            ($position===100 && $direction==='up');

        if ($type==='shutter' && !$endPositionForcesTravel && $elapsed>0 && $elapsed<$lamellaTime && ($lastDirection==='down'||$lastDirection==='up')) {
            $direction=$lastDirection==='down'?'up':'down';
            $lid=$this->FindManagedVariableID('Ch'.$channel.'Lamella');
            $cur=$lid>0?max(0,min(100,(int)GetValue($lid))):($lastDirection==='down'?0:100);
            $delta=(int)round($elapsed*100/max(1,$lamellaTime));
            $newLamella=$direction==='up'?min(100,$cur+$delta):max(0,$cur-$delta);
            $this->SetValueIfChanged('Ch'.$channel.'Lamella',$newLamella);
            $newPosition=$position;
            $this->SendDebug('LAMELLE','ch'.$channel.' externe Gegenfahrt '.$elapsed.'ms | '.$newLamella.'% | Position bleibt '.$position.'%',0);
        } else {
            if ($endPositionForcesTravel) {
                $this->SendDebug(
                    'ROLLO ENDSLAGE',
                    'ch'.$channel.' Position='.$position.'% | Richtung='.$direction.' eindeutig | keine Lamellen-Gegenfahrt',
                    0
                );
            }
            if ($direction==='unknown') {
                $tolUp=max(750,(int)round($expectUp*0.10));
                $tolDown=max(750,(int)round($expectDown*0.10));
                $upPossible=$elapsed<=($expectUp+$tolUp);
                $downPossible=$elapsed<=($expectDown+$tolDown);
                if (!$upPossible && $downPossible) $direction='down';
                elseif (!$downPossible && $upPossible) $direction='up';
                else $direction=$errUp<=$errDown?'up':'down';
                $this->SendDebug('ROLLO MATCH','ch'.$channel.' extern | '.$elapsed.'ms -> '.$direction,0);
            }
            if ($type==='shutter' && $endPositionForcesTravel && $elapsed<$lamellaTime) {
                // Direkt aus einer Endlage bewegt sich zuerst die Lamelle.
                // Beispiel: Lamellenzeit 1000 ms, 300 ms HOCH aus 100 %
                // => Position bleibt 100 %, Lamelle ca. 30 % geschlossen
                // bzw. 70 % offen (Skala 0=geschlossen, 100=offen).
                $newPosition=$position;
                $fraction=max(0.0,min(1.0,$elapsed/max(1,$lamellaTime)));
                // In den ersten Lamellenzeit-ms ab einer Endlage bewegt sich
                // nur die Lamelle. Die Prozentzahl entspricht direkt dem bereits
                // durchlaufenen Anteil dieser Lamellenzeit:
                // unten -> HOCH: 0 % geschlossen -> Richtung 100 % offen
                // oben  -> RUNTER: 100 % offen -> Richtung 0 % geschlossen.
                $newLamella=$direction==='up'
                    ? max(0,min(100,(int)round(100*$fraction)))
                    : max(0,min(100,(int)round(100-(100*$fraction))));
                $this->SetValueIfChanged('Ch'.$channel.'Lamella',$newLamella);
                $this->SendDebug('LAMELLE ENDSLAGE','ch'.$channel.' '.$elapsed.'ms / '.$lamellaTime.'ms | Richtung='.$direction.' | Drehgrad='.$newLamella.'% | Position bleibt '.$position.'%',0);
            } else {
                if ($direction==='up') {
                    $newPosition=max(0,$position-(int)round($elapsed*100/max(1,$up)));
                    if ($elapsed >= $expectUp-max(750,(int)round($expectUp*0.08))) $newPosition=0;
                } else {
                    $newPosition=min(100,$position+(int)round($elapsed*100/max(1,$down)));
                    if ($elapsed >= $expectDown-max(750,(int)round($expectDown*0.08))) $newPosition=100;
                }
                $this->SetMotorPosition($channel,$newPosition);
                if ($type==='shutter') $this->SetValueIfChanged('Ch'.$channel.'Lamella',$direction==='up'?100:0);
            }
            $m['lastDirection']=$direction;
        }
        $m['moving']=false; $m['direction']=''; $m['lastDirection']=$direction;
        unset($m['commandDirection'],$m['commandTarget'],$m['commandStartPosition']);
        $state[$key]=$m; $this->WriteMotorState($state);
        $this->SendDebug('ROLLO','ch'.$channel.' ENDE | '.$elapsed.'ms | Richtung='.$direction.' | Position='.$newPosition.'%',0);
    }

    private function MotorLearnedTimeCaption(int $channel): string
    {
        $t=$this->ReadLearnedMotorTimes()[(string)$channel]??[];
        $up=(int)($t['up']??0); $down=(int)($t['down']??0);
        return ($up>0&&$down>0)
            ? 'Gelernte Fahrzeiten: HOCH '.number_format($up/1000,3,'.','').' s | RUNTER '.number_format($down/1000,3,'.','').' s'
            : 'Gelernte Fahrzeiten: noch nicht eingelernt';
    }
    public function OwnMotorTick(): void
    {
        $all=$this->ReadOwnMotorStates();
        $now=(int)round(microtime(true)*1000);
        foreach (array_keys($all) as $k) {
            $v=$all[$k]??null;
            if (!is_array($v)) { unset($all[$k]); continue; }
            if ($now<=(int)($v['until']??0)) continue;

            $channel=(int)$k;
            if (($v['type']??'')==='position' && array_key_exists('pendingLamella',$v)) {
                // Positionsfahrt ist beendet. Gepufferte Blende NICHT sofort
                // senden, sondern dem Aktor 500 ms zum Stillstand geben.
                $target=max(0,min(100,(int)$v['pendingLamella']));
                $current=($v['direction']??'down')==='down'?0:100;
                $all[$k]=[
                    'type'=>'pendingLamellaDelay',
                    'until'=>$now+500,
                    'target'=>$target,
                    'startLamella'=>$current
                ];
                $this->SendDebug('LAMELLE PUFFER','ch'.$channel.' Positionsfahrt beendet | 500ms Wartezeit vor Blende '.$target.'%',0);
                continue;
            }
            if (($v['type']??'')==='pendingLamellaDelay') {
                $target=max(0,min(100,(int)($v['target']??0)));
                $current=max(0,min(100,(int)($v['startLamella']??0)));
                unset($all[$k]);
                $this->WriteOwnMotorStates($all);
                $this->SendDebug('LAMELLE PUFFER','ch'.$channel.' Wartezeit beendet | Blende '.$current.'% -> '.$target.'%',0);
                $this->StartOwnLamellaMove($channel,$target,$current);
                $all=$this->ReadOwnMotorStates();
                continue;
            }
            unset($all[$k]);
        }
        $this->WriteOwnMotorStates($all);
        if (count($all)===0) $this->SetTimerInterval('OwnMotorTimer',0);
    }
    private function ReadOwnMotorStates(): array
    {
        $d=json_decode($this->ReadAttributeString('OwnMotorState'),true);
        return is_array($d)?$d:[];
    }
    private function WriteOwnMotorStates(array $d): void
    {
        $this->WriteAttributeString('OwnMotorState',json_encode($d,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    private function GetOwnMotorState(int $channel): array
    {
        $all=$this->ReadOwnMotorStates(); $v=$all[(string)$channel]??[];
        if (!is_array($v)) return [];
        $v['active']=true; return $v;
    }
    private function SetOwnMotorState(int $channel,array $v): void
    {
        $all=$this->ReadOwnMotorStates(); $all[(string)$channel]=$v;
        $this->WriteOwnMotorStates($all); $this->SetTimerInterval('OwnMotorTimer',100);
    }
    private function ClearOwnMotorState(int $channel): void
    {
        $all=$this->ReadOwnMotorStates(); unset($all[(string)$channel]); $this->WriteOwnMotorStates($all);
    }

    private function StartOwnLamellaMove(int $channel,int $target,int $current): void
    {
        $target=max(0,min(100,$target));
        $current=max(0,min(100,$current));
        if ($target===$current) {
            $this->SetValueIfChanged('Ch'.$channel.'Lamella',$target);
            return;
        }
        $fullTime=max(100,min(32000,$this->ReadPropertyInteger('Channel'.$channel.'LamellaTimeMs')));
        $calculatedTime=max(1,(int)round(abs($target-$current)*$fullTime/100));
        // Die Lamellen-Fahrzeit wird ausschliesslich proportional aus der
        // konfigurierten Lamellenzeit berechnet. Die separate 500-ms-Pause
        // gilt nur nach einer gepufferten Positionsfahrt.
        $time=max(100,min(32000,$calculatedTime));
        $direction=$target>$current?'up':'down';
        $command=$direction==='up'?'move_open_'.$time:'move_close_'.$time;
        $this->SendDebug('LAMELLE EXEC','ch'.$channel.' Puffer/Variablenbefehl '.$current.'% -> '.$target.'% | berechnet='.$calculatedTime.'ms | gesendet='.$time.'ms',0);
        if ($this->SendCommand($channel,$command)) {
            $this->SetValueIfChanged('Ch'.$channel.'Lamella',$target);
            $now=(int)round(microtime(true)*1000);
            $this->SetOwnMotorState($channel,[
                'type'=>'lamella','startMs'=>$now,'until'=>$now+$time+300,
                'target'=>$target,'direction'=>$direction
            ]);
            $this->SendDebug('LAMELLE SOLL','ch'.$channel.' '.$current.'% -> '.$target.'% | '.$direction.' | '.$time.'ms',0);
        }
    }

    private function EstimateOwnPhysicalPosition(int $channel,array $own,int $nowMs): int
    {
        $start=max(0,min(100,(int)($own['startPosition']??0)));
        $elapsed=max(0,$nowMs-(int)($own['startMs']??$nowMs));
        $direction=(string)($own['direction']??'');
        if ($direction==='down') {
            return min(100,$start+(int)round($elapsed*100/max(1,$this->EffectiveMotorTime($channel,'down'))));
        }
        if ($direction==='up') {
            return max(0,$start-(int)round($elapsed*100/max(1,$this->EffectiveMotorTime($channel,'up'))));
        }
        return $start;
    }

    private function ReadMotorState(): array
    {
        $d=json_decode($this->ReadAttributeString('MotorRuntimeState'),true);
        return is_array($d)?$d:[];
    }
    private function WriteMotorState(array $state): void
    {
        $this->WriteAttributeString('MotorRuntimeState',json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    private function ReadLearnedMotorTimes(): array
    {
        $d=json_decode($this->ReadAttributeString('MotorLearnedTimes'),true);
        return is_array($d)?$d:[];
    }
    private function WriteLearnedMotorTime(int $channel,string $direction,int $milliseconds): void
    {
        $times=$this->ReadLearnedMotorTimes(); $key=(string)$channel;
        if (!is_array($times[$key]??null)) $times[$key]=[];
        $times[$key][$direction]=max(100,$milliseconds);
        $this->WriteAttributeString('MotorLearnedTimes',json_encode($times,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    private function EffectiveMotorTime(int $channel,string $direction): int
    {
        $times=$this->ReadLearnedMotorTimes();
        $learned=(int)($times[(string)$channel][$direction]??0);
        if ($learned>0) return $learned;
        return max(100,$this->ReadPropertyInteger('Channel'.$channel.($direction==='up'?'UpTimeMs':'DownTimeMs')));
    }
    private function GetMotorPosition(int $channel): int
    {
        $id=$this->FindManagedVariableID('Ch'.$channel.'Position');
        return $id>0?max(0,min(100,(int)GetValue($id))):0;
    }
    private function SetMotorPosition(int $channel,int $position): void
    {
        $this->SetValueIfChanged('Ch'.$channel.'Position',max(0,min(100,$position)));
        if (strtolower($this->ReadPropertyString('Channel'.$channel.'Type'))==='shutter') {
            if ($position===0) $this->SetValueIfChanged('Ch'.$channel.'Lamella',100);
            elseif ($position===100) $this->SetValueIfChanged('Ch'.$channel.'Lamella',0);
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

    private function SmartButtonHookName(): string
    {
        return 'zeptrionair-' . $this->InstanceID;
    }

    public function StartNotifyListener(): bool
    {
        $this->SetBuffer('NotifyListening', '1');
        $this->SetBuffer('NotifyBuffer', '');
        $this->SetBuffer('NotifyPending', '');
        if (!$this->HasActiveParent()) {
            $this->EnterNotifyRecovery('Client Socket nicht aktiv');
            return false;
        }
        $this->SetTimerInterval('RecoveryTimer', 0);
        return $this->SendNotifyRequest('/zrap/chscan', 'scan');
    }

    public function RecoveryTick(): void
    {
        if ($this->GetBuffer('NotifyListening') !== '1') {
            $this->SetTimerInterval('RecoveryTimer', 0);
            return;
        }
        if (!$this->HasActiveParent()) {
            $this->SendDebug('RECOVERY', 'Client Socket noch nicht aktiv -> auf Symcon-Reconnect warten', 0);
            return;
        }
        if ($this->GetBuffer('NotifyPending') !== '') {
            $this->SetBuffer('NotifyPending', '');
            $this->SetBuffer('NotifyBuffer', '');
        }
        $this->SendDebug('RECOVERY', 'Probe mit chscan', 0);
        $this->SendNotifyRequest('/zrap/chscan', 'scan');
    }

    public function ReceiveData($JSONString)
    {
        $d = json_decode($JSONString, true);
        if (!is_array($d) || !isset($d['Buffer']) || $d['Buffer'] === '') return '';
        $buffer = $this->GetBuffer('NotifyBuffer') . (string)$d['Buffer'];
        while (true) {
            $r = $this->ExtractNotifyResponse($buffer);
            if ($r === null) break;
            $buffer = $r['rest'];
            $kind = $this->GetBuffer('NotifyPending');
            $this->SetBuffer('NotifyPending', '');
            $this->SetBuffer('NotifyOnline', '1');
            $this->SendDebug('CHNOTIFY HTTP', (string)$r['status'] . ' ' . $kind, 0);
            if ($r['status'] !== 200 && $r['status'] !== 302) {
                $this->EnterNotifyRecovery('HTTP Status ' . $r['status']);
                continue;
            }
            if ($kind === 'scan') {
                $this->ProcessNotifyData($r['body']);
                $this->SetTimerInterval('RecoveryTimer', 0);
                $this->SendDebug('RECOVERY', 'chscan OK -> chnotify aktivieren', 0);
            } elseif ($kind === 'notify') {
                $this->ProcessNotifyData($r['body']);
            }
            if ($this->GetBuffer('NotifyListening') === '1' && $this->GetBuffer('NotifyPending') === '') {
                $this->SendNotifyRequest('/zrap/chnotify', 'notify');
            }
        }
        $this->SetBuffer('NotifyBuffer', $buffer);
        return '';
    }

    private function EnterNotifyRecovery(string $reason): void
    {
        $this->SetBuffer('NotifyOnline', '0');
        $this->SetBuffer('NotifyPending', '');
        $this->SetBuffer('NotifyBuffer', '');
        $sec = max(5, $this->ReadPropertyInteger('RecoveryInterval'));
        $this->SetTimerInterval('RecoveryTimer', $sec * 1000);
        $this->SendDebug('RECOVERY', $reason . ' -> alle ' . $sec . ' s chscan versuchen', 0);
    }

    private function SendNotifyRequest(string $path, string $kind): bool
    {
        if ($this->GetBuffer('NotifyListening') !== '1' || !$this->HasActiveParent()) {
            $this->EnterNotifyRecovery('Senden nicht möglich');
            return false;
        }
        if ($this->GetBuffer('NotifyPending') !== '') return false;
        $q = "GET " . $path . " HTTP/1.1\r\n" .
             "Host: zeptrion\r\n" .
             "Accept: application/xml,text/xml,*/*\r\n" .
             "Cache-Control: no-cache\r\n" .
             "Connection: keep-alive\r\n\r\n";
        $this->SetBuffer('NotifyPending', $kind);
        $this->SendDebug('CHNOTIFY TX', $kind . ' ' . $path, 0);
        $ok = $this->SendDataToParent(json_encode(['DataID' => self::TX, 'Buffer' => $q], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($ok === false) {
            $this->SetBuffer('NotifyPending', '');
            $this->EnterNotifyRecovery('SendDataToParent fehlgeschlagen');
            return false;
        }
        return true;
    }

    private function ExtractNotifyResponse(string $b): ?array
    {
        $he = strpos($b, "\r\n\r\n");
        if ($he === false) return null;
        $h = substr($b, 0, $he); $bs = $he + 4;
        $ls = explode("\r\n", $h); $sl = array_shift($ls); $st = 0;
        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/', (string)$sl, $m)) $st = (int)$m[1];
        $hs = [];
        foreach ($ls as $l) {
            $pos = strpos($l, ':');
            if ($pos !== false) $hs[strtolower(trim(substr($l, 0, $pos)))] = trim(substr($l, $pos + 1));
        }
        if (isset($hs['content-length'])) {
            $n = (int)$hs['content-length'];
            if (strlen($b) < $bs + $n) return null;
            return ['status' => $st, 'body' => substr($b, $bs, $n), 'rest' => substr($b, $bs + $n)];
        }
        if ($st === 302 || $st === 204) return ['status' => $st, 'body' => '', 'rest' => substr($b, $bs)];
        foreach (['</chnotify>', '</chscan>'] as $tag) {
            $pos = strpos($b, $tag, $bs);
            if ($pos !== false) {
                $end = $pos + strlen($tag);
                return ['status' => $st, 'body' => substr($b, $bs, $end - $bs), 'rest' => substr($b, $end)];
            }
        }
        return null;
    }

    public function GetSmartButtonAssignment(int $deviceInstance): string
    {
        $names = [];
        foreach ($this->ReadScenes() as $scene) {
            if ((int)($scene['smartButtonInstance'] ?? 0) !== $deviceInstance) {
                continue;
            }
            $name = trim((string)($scene['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return implode(', ', array_values(array_unique($names)));
    }
    public function GetSmartButtonAssignments(int $deviceInstance): string
    {
        $result = [];
        foreach ($this->ReadScenes() as $scene) {
            if ((int)($scene['smartButtonInstance'] ?? 0) !== $deviceInstance) {
                continue;
            }
            $targets = [];
            foreach (($scene['targets'] ?? []) as $target) {
                if (!is_array($target)) {
                    continue;
                }
                $type = (string)($target['type'] ?? '');
                if ($type === 'zeptrion') {
                    $instance = (int)($target['instance'] ?? 0);
                    $channel = (int)($target['channel'] ?? 0);
                    $memory = (int)($target['memory'] ?? 0);
                    if ($this->IsDeviceInstance($instance)) {
                        $channelName = trim((string)IPS_GetProperty($instance, 'Channel' . $channel . 'Name'));
                        $deviceName = trim(IPS_GetName($instance));
                        $caption = $channelName !== '' ? $channelName : ($deviceName . ' / Kanal ' . $channel);
                    } else {
                        $caption = 'zeptrionAIR';
                    }
                    $targets[] = $caption . ' → S' . $memory . ' (direkt)';
                } elseif ($type === 'symcon') {
                    $objectID = (int)($target['object'] ?? 0);
                    if (IPS_VariableExists($objectID)) {
                        $targets[] = $this->ObjectPath($objectID) . ' → ' . $this->FormatSmartButtonValue($objectID, $target['value'] ?? null);
                    } elseif (IPS_ScriptExists($objectID)) {
                        $targets[] = $this->ObjectPath($objectID) . ' → Script ausführen';
                    }
                }
            }
            $result[] = [
                'name' => trim((string)($scene['name'] ?? '')) ?: 'Smart-Taster',
                'targets' => $targets
            ];
            if (count($result) >= 2) {
                break;
            }
        }
        return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
    protected function ProcessHookData(): void
    {
        if ((string)($_GET['action'] ?? '') === 'run') {
            $this->RunScene((string)($_GET['scene'] ?? ''), (string)($_GET['token'] ?? ''));
            return;
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
                if (!(error_reporting() & $severity)) {
                    return false;
                }
                throw new ErrorException($message, 0, $severity, $file, $line);
            });
            try {
                $rawInput = file_get_contents('php://input');
                if ($rawInput === false) {
                    throw new RuntimeException('Anfragedaten konnten nicht gelesen werden.');
                }
                $in = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($in)) {
                    throw new RuntimeException('Ungültige Anfrage.');
                }
                switch ((string)($in['op'] ?? '')) {
                case 'program':
                    echo json_encode($this->ProgramScene($in), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;
                case 'forget':
                    echo json_encode($this->ForgetScene((string)($in['scene'] ?? '')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;
                case 'select-delete':
                    echo json_encode($this->SelectDelete(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;
                case 'tree-children':
                    echo json_encode(['ok' => true, 'items' => $this->GetObjectTreeChildren((int)($in['parent'] ?? 0))], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;
                case 'tree-search':
                    echo json_encode(['ok' => true, 'items' => $this->SearchObjectTree((string)($in['query'] ?? ''))], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;
                    case 'object-info':
                        echo json_encode(['ok' => true, 'object' => $this->GetSelectableObjectInfo((int)($in['id'] ?? 0))], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        return;
                }
                echo json_encode(['ok' => false, 'message' => 'Unbekannte Aktion'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                $this->SendDebug('Webinterface', $e->getMessage(), 0);
                echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->BuildInterface();
    }
    private function ProgramScene(array $in): array
    {
        $name = trim((string)($in['name'] ?? '')) ?: 'Szene';
        $targets = $this->NormalizeTargets(is_array($in['targets'] ?? null) ? $in['targets'] : []);
        if ($targets === []) {
            return ['ok' => false, 'message' => 'Bitte mindestens ein Ziel auswählen.'];
        }
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($in['scene'] ?? '')) ?: bin2hex(random_bytes(6));
        $sel = $this->SelectSmartButton();
        if (!$sel['success']) {
            return ['ok' => false, 'message' => $sel['message']];
        }
        $services = [];
        $hasSymconTargets = false;
        foreach ($targets as $target) {
            if ((string)($target['type'] ?? '') === 'zeptrion') {
                $instance = (int)($target['instance'] ?? 0);
                $host = $this->IsDeviceInstance($instance) ? trim((string)IPS_GetProperty($instance, 'Host')) : '';
                if ($host === '') {
                    return ['ok' => false, 'message' => 'Für ein zeptrionAIR-Ziel ist keine Host-Adresse hinterlegt.'];
                }
                $services[] = [
                    'typ' => 'application/x-www-form-urlencoded',
                    'req' => 'POST',
                    'loc' => $host,
                    'pth' => '/zrap/chctrl',
                    'bdy' => 'cmd' . (int)$target['channel'] . '=recall_s' . (int)$target['memory']
                ];
            } elseif ((string)($target['type'] ?? '') === 'symcon') {
                $hasSymconTargets = true;
            }
        }
        if ($hasSymconTargets) {
            $hh = (string)($_SERVER['HTTP_HOST'] ?? '');
            if ($hh === '') {
                return ['ok' => false, 'message' => 'Symcon-Adresse konnte nicht ermittelt werden.'];
            }
            $p = explode(':', $hh, 2);
            $token = $this->ReadAttributeString('SmartButtonToken');
            $services[] = [
                'req' => 'GET',
                'typ' => 'application/x-www-form-urlencoded',
                'loc' => $p[0],
                'prt' => (string)(isset($p[1]) ? (int)$p[1] : 3777),
                'pth' => '/hook/' . $this->SmartButtonHookName() . '?action=run&scene=' . rawurlencode($id) . '&token=' . rawurlencode($token),
                'bdy' => ''
            ];
        }
        if ($services === []) {
            return ['ok' => false, 'message' => 'Es konnten keine Smart-Taster-Dienste erzeugt werden.'];
        }
        $payload = count($services) === 1 ? $services[0] : $services;
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return ['ok' => false, 'message' => 'Smart-Taster-Programm konnte nicht erzeugt werden.'];
        }
        $estimatedBytes = strlen($encoded) + strlen('Content-Type: application/json\r\nContent-Length: ' . strlen($encoded) . '\r\nConnection: close\r\n');
        if ($estimatedBytes > 730) {
            return ['ok' => false, 'message' => 'Die Smart-Taster-Szene ist zu gross. zeptrionAIR erlaubt für /zapi/smartbt/prgs maximal 730 Byte inklusive HTTP-Header.'];
        }
        $r = $this->SmartButtonRequest((string)$sel['host'], 'POST', '/zapi/smartbt/prgs', $payload, 5000);
        if (!$r['success']) {
            return ['ok' => false, 'message' => 'Programmierung fehlgeschlagen: ' . $r['message']];
        }
        $scenes = $this->ReadScenes();
        $entry = [
            'id' => $id,
            'name' => $name,
            'targets' => $targets,
            'smartButtonHost' => $sel['host'],
            'smartButtonName' => $sel['name'],
            'smartButtonInstance' => $sel['instance']
        ];
        $found = false;
        foreach ($scenes as &$scene) {
            if ((string)($scene['id'] ?? '') === $id) {
                $scene = $entry;
                $found = true;
                break;
            }
        }
        unset($scene);
        if (!$found) {
            $scenes[] = $entry;
        }
        $this->WriteScenes($scenes);
        return ['ok' => true, 'message' => 'Smart-Taste wurde an „' . $sel['name'] . '“ erkannt und mit „' . $name . '“ programmiert.', 'scenes' => $scenes];
    }
    private function SelectDelete(): array
    {
        $s = $this->SelectSmartButton();
        if (!$s['success']) {
            return ['ok' => false, 'message' => $s['message']];
        }
        return ['ok' => true, 'message' => 'Smart-Taste auf „' . $s['name'] . '“ wurde erkannt und zum Löschen ausgewählt.'];
    }
    private function SelectSmartButton(): array
    {
        $devices = $this->GetDeviceHosts();
        if ($devices === []) {
            return ['success' => false, 'message' => 'Keine zeptrionAIR-Geräte mit Host gefunden.'];
        }
        $active = [];
        foreach ($devices as $host => $device) {
            $r = $this->SmartButtonRequest($host, 'POST', '/zapi/smartbt/prgm', ['on' => true, 'ntm' => 60], 4000);
            if ($r['success']) {
                $active[$host] = $device;
            }
        }
        if ($active === []) {
            return ['success' => false, 'message' => 'Programmiermodus konnte auf keinem zApp gestartet werden.'];
        }
        $locked = [];
        foreach (array_keys($active) as $host) {
            $lockName = 'ZEPA_SMART_HTTP_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $host);
            if (IPS_SemaphoreEnter($lockName, 0)) {
                $locked[$host] = $lockName;
            } else {
                unset($active[$host]);
            }
        }
        if ($active === []) {
            return ['success' => false, 'message' => 'Gerätekommunikation ist belegt. Smart-Taster-Auswahl konnte nicht gestartet werden.'];
        }
        $this->SendDebug('Smart-Taster', 'Warte auf Tastendruck auf ' . count($active) . ' zApp(s): ' . implode(', ', array_keys($active)), 0);
        $multi = curl_multi_init();
        $handles = [];
        foreach ($active as $host => $device) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'http://' . $host . '/zapi/smartbt/prgn',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 2500,
                CURLOPT_TIMEOUT_MS => 65000,
                CURLOPT_HTTPHEADER => ['Connection: close']
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$host] = $ch;
        }
        $selected = '';
        $deadline = microtime(true) + 66;
        do {
            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
            foreach ($handles as $host => $ch) {
                if ((int)(curl_getinfo($ch)['http_code'] ?? 0) === 200) {
                    $body = (string)curl_multi_getcontent($ch);
                    if ($body !== '') {
                        $this->SendDebug('Smart-Taster prgn RAW', $host . ' / HTTP 200 / Antwort: ' . $body, 0);
                        $json = json_decode($body, true);
                        if (is_array($json) && ($json['prg'] ?? false) === true) {
                            $selected = $host;
                            break 2;
                        }
                    }
                }
            }
            if ($running > 0) {
                curl_multi_select($multi, .2);
            }
        } while ($running > 0 && microtime(true) < $deadline);
        foreach ($handles as $ch) {
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        foreach ($locked as $lockName) {
            IPS_SemaphoreLeave($lockName);
        }
        if ($selected === '') {
            return ['success' => false, 'message' => 'Keine Smart-Taste erkannt.'];
        }
        $device = $active[$selected];
        $this->SendDebug('Smart-Taster erkannt', 'zApp: ' . $selected . ' / Gerät: ' . $device['name'] . ' / Instanz: ' . $device['instance'], 0);
        return ['success' => true, 'host' => $selected, 'name' => $device['name'], 'instance' => $device['instance']];
    }
    private function SmartButtonRequest(string $host, string $method, string $path, mixed $payload = null, int $timeoutMs = 4000): array
    {
        $lockName = 'ZEPA_SMART_HTTP_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $host);
        if (!IPS_SemaphoreEnter($lockName, 0)) {
            return ['success' => false, 'message' => 'Gerätekommunikation ist belegt', 'raw' => '', 'httpCode' => 0];
        }
        try {
            $url = 'http://' . $host . $path;
            $ch = curl_init();
            if ($ch === false) {
                return ['success' => false, 'message' => 'cURL konnte nicht initialisiert werden', 'raw' => '', 'httpCode' => 0];
            }
            $options = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 2000,
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => ['Connection: close']
            ];
            if ($payload !== null) {
                $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $options[CURLOPT_POSTFIELDS] = $body;
                $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Content-Length: ' . strlen((string)$body), 'Connection: close'];
            }
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $raw = is_string($response) ? $response : '';
            $this->SendDebug('Smart-Taster RAW', $method . ' ' . $url . ' / HTTP ' . $code . ' / Antwort: ' . $raw . ($error !== '' ? ' / Fehler: ' . $error : ''), 0);
            return ['success' => $error === '' && $code >= 200 && $code < 300, 'message' => $error !== '' ? $error : 'HTTP ' . $code . ($raw !== '' ? ' / ' . $raw : ''), 'raw' => $raw, 'httpCode' => $code];
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
    }
    private function ForgetScene(string $id): array
    {
        $scenes = array_values(array_filter($this->ReadScenes(), static fn(array $scene): bool => (string)($scene['id'] ?? '') !== $id));
        $this->WriteScenes($scenes);
        return ['ok' => true, 'message' => 'Smart-Taster-Eintrag wurde aus diesem Gerät entfernt.', 'scenes' => $scenes];
    }
    private function RunScene(string $id, string $token): void
    {
        if ($token === '' || !hash_equals($this->ReadAttributeString('SmartButtonToken'), $token)) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        foreach ($this->ReadScenes() as $scene) {
            if ((string)($scene['id'] ?? '') !== $id) {
                continue;
            }
            foreach (($scene['targets'] ?? []) as $target) {
                try {
                    $type = (string)($target['type'] ?? 'zeptrion');
                    if ($type === 'symcon') {
                        $objectID = (int)($target['object'] ?? 0);
                        if (IPS_VariableExists($objectID)) {
                            RequestAction($objectID, $target['value'] ?? null);
                        } elseif (IPS_ScriptExists($objectID)) {
                            IPS_RunScript($objectID);
                        }
                    } elseif ($type === 'variable') {
                        $objectID = (int)($target['variable'] ?? 0);
                        if (IPS_VariableExists($objectID)) {
                            RequestAction($objectID, $target['value'] ?? null);
                        }
                    } elseif ($type === 'script') {
                        $objectID = (int)($target['script'] ?? 0);
                        if (IPS_ScriptExists($objectID)) {
                            IPS_RunScript($objectID);
                        }
                    }
                } catch (Throwable $e) {
                    $this->SendDebug('Smart-Taster Aktion', $e->getMessage(), 0);
                }
            }
            echo 'OK';
            return;
        }
        http_response_code(404);
        echo 'Scene not found';
    }
    private function BuildInterface(): string
    {
        $scenes = $this->ReadScenes();
        foreach ($scenes as &$scene) {
            if (!is_array($scene['targets'] ?? null)) {
                continue;
            }
            foreach ($scene['targets'] as &$target) {
                if (!is_array($target)) {
                    continue;
                }
                $type = (string)($target['type'] ?? '');
                $objectID = $type === 'variable' ? (int)($target['variable'] ?? 0) : ($type === 'script' ? (int)($target['script'] ?? 0) : (int)($target['object'] ?? 0));
                if (!in_array($type, ['symcon', 'variable', 'script'], true) || $objectID <= 0) {
                    continue;
                }
                $info = $this->GetSelectableObjectInfo($objectID);
                if ($info !== null) {
                    $target['objectInfo'] = $info;
                } else {
                    $target['objectMissing'] = true;
                }
            }
            unset($target);
        }
        unset($scene);
        $data = json_encode([
            'scenes' => $scenes,
            'zeptrionTargets' => $this->GetTargets()
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $html = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Smart-Taster</title>
<style>
body{font-family:system-ui,sans-serif;max-width:1000px;margin:28px auto;padding:0 16px;background:#f5f5f5;color:#222}h1{font-size:24px}.card{background:#fff;border-radius:10px;padding:16px;margin:12px 0;box-shadow:0 1px 4px #0002}.row{display:flex;gap:8px;align-items:center;margin:8px 0;flex-wrap:wrap}input,select,button{font:inherit;padding:8px;border:1px solid #bbb;border-radius:6px}input{min-width:150px}select{min-width:180px}button{cursor:pointer}.name{flex:1}.danger{margin-left:auto}.status{padding:12px 0;min-height:24px;font-weight:600}.target{padding-left:12px;border-left:3px solid #ddd}.busy{opacity:.55;pointer-events:none}.hint,.source{color:#666;font-size:14px}.objfield{min-width:330px;text-align:left}.modal{position:fixed;inset:0;background:#0008;display:flex;align-items:center;justify-content:center;z-index:99}.modalbox{background:#fff;width:min(760px,92vw);height:min(650px,84vh);border-radius:10px;padding:14px;display:flex;flex-direction:column}.tree{overflow:auto;flex:1;border:1px solid #ddd;border-radius:6px;padding:6px}.node{margin:1px 0}.nodeRow{display:flex;align-items:center;min-height:30px;border-radius:4px}.nodeRow:hover{background:#eee}.twisty{width:28px;border:0;background:transparent;padding:4px}.nodeLabel{border:0;background:transparent;text-align:left;flex:1;padding:5px}.nodeLabel.selectable{font-weight:500}.children{margin-left:22px}.search{box-sizing:border-box;width:100%;margin:8px 0}.close{margin-left:auto}.modalHead{display:flex;align-items:center;gap:10px}.typeTag{font-size:12px;color:#777;margin-left:8px}.searchResult{display:block;width:100%;text-align:left;border:0;background:transparent;padding:7px;border-radius:4px}.searchResult:hover{background:#eee}
</style></head><body><h1>Smart-Taster konfigurieren</h1><p class="hint">Szene benennen, zeptrionAIR-Ziele direkt oder Symcon-Objekte hinzufügen und danach programmieren.</p><div id="scenes"></div><button id="addScene">+ Szene hinzufügen</button> <button id="clearButton">Smart-Taster löschen</button><div class="status" id="status"></div><script>
const D=__DATA__;let scenes=Array.isArray(D.scenes)?D.scenes:[];const Z=D.zeptrionTargets||[];const cache=new Map();
const el=id=>document.getElementById(id),mk=(t,x)=>{const e=document.createElement(t);if(x!==undefined)e.textContent=x;return e};
function msg(x){el('status').textContent=x||''}function busy(v){document.body.classList.toggle('busy',!!v)}
function opts(s,l,v){s.replaceChildren();l.forEach(x=>{const o=mk('option',x.caption);o.value=x.value;if(String(x.value)===String(v))o.selected=true;s.append(o)})}
async function api(x){try{const r=await fetch('__HOOK__',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(x)});const raw=await r.text();try{return JSON.parse(raw)}catch(e){return{ok:false,message:'Ungültige Serverantwort: '+raw.trim().slice(0,500)}}}catch(e){return{ok:false,message:e.message}}}
async function objectInfo(id){if(!id)return null;if(cache.has(+id))return cache.get(+id);const r=await api({op:'object-info',id:+id});if(r.ok&&r.object){cache.set(+id,r.object);return r.object}return null}
function migrate(t){if(t.type==='variable')return{type:'symcon',object:+t.variable,value:t.value};if(t.type==='script')return{type:'symcon',object:+t.script};return t}
async function pick(t,done){const m=mk('div');m.className='modal';const b=mk('div');b.className='modalbox';const head=mk('div');head.className='modalHead';head.append(mk('h3','Symcon-Objekt auswählen'));const x=mk('button','Schliessen');x.className='close';x.onclick=()=>m.remove();head.append(x);const q=mk('input');q.className='search';q.placeholder='Objekt suchen …';const tree=mk('div');tree.className='tree';b.append(head,q,tree);m.append(b);document.body.append(m);
async function choose(item){if(!item.selectable)return;const full=await api({op:'object-info',id:+item.id});if(!full.ok||!full.object){msg('Objektinformationen konnten nicht geladen werden.');return}t.object=+item.id;t.objectInfo=full.object;t.value=full.object.defaultValue??'';cache.set(+item.id,full.object);m.remove();done()}
async function load(parent,container){container.textContent='Lade …';const r=await api({op:'tree-children',parent});container.replaceChildren();if(!r.ok)return;(r.items||[]).forEach(item=>{const wrap=mk('div');wrap.className='node';const row=mk('div');row.className='nodeRow';const twist=mk('button',item.hasChildren?'▶':'');twist.className='twisty';const label=mk('button',(item.icon||'')+' '+item.name);label.className='nodeLabel'+(item.selectable?' selectable':'');const tag=mk('span',item.type==='variable'?'Variable':item.type==='script'?'Script':'');tag.className='typeTag';row.append(twist,label,tag);wrap.append(row);const children=mk('div');children.className='children';wrap.append(children);let open=false;twist.onclick=async()=>{if(!item.hasChildren)return;open=!open;twist.textContent=open?'▼':'▶';if(open&&children.childNodes.length===0)await load(item.id,children);children.style.display=open?'block':'none'};label.onclick=()=>item.selectable?choose(item):twist.click();container.append(wrap)})}
let timer=0;q.oninput=()=>{clearTimeout(timer);timer=setTimeout(async()=>{const text=q.value.trim();if(text===''){await load(0,tree);return}tree.textContent='Suche …';const r=await api({op:'tree-search',query:text});tree.replaceChildren();(r.items||[]).forEach(item=>{const e=mk('button',(item.type==='variable'?'● ':'▶ ')+item.path);e.className='searchResult';e.onclick=()=>choose(item);tree.append(e)})},180)};await load(0,tree)}
async function valueEditor(t,r){const o=t.objectInfo||await objectInfo(t.object);if(!o||o.type!=='variable')return;if(Array.isArray(o.associations)&&o.associations.length){const s=mk('select');opts(s,o.associations.map(a=>({value:a.value,caption:a.name})),t.value);s.onchange=()=>t.value=o.varType===1?+s.value:o.varType===2?+s.value:s.value;r.append(s);return}if(o.varType===0){const s=mk('select');opts(s,[{value:'false',caption:'Aus / False'},{value:'true',caption:'Ein / True'}],String(t.value));s.onchange=()=>t.value=s.value;r.append(s);return}if((o.varType===1||o.varType===2)&&o.profileMin!==null&&o.profileMax!==null){const min=Number(o.profileMin),max=Number(o.profileMax),rawStep=Number(o.profileStep),step=rawStep>0?rawStep:(o.varType===1?1:0.1),suffix=o.profileSuffix||'';const count=Math.floor((max-min)/step+0.0000001)+1;if(count>0&&count<=500){const s=mk('select');const values=[];for(let i=0;i<count;i++){let v=min+i*step;if(o.varType===1)v=Math.round(v);else v=Math.round(v*1000000)/1000000;values.push({value:v,caption:String(v)+(suffix?' '+suffix.trim():'')})}if(!values.some(x=>Number(x.value)===Number(t.value))&&t.value!==''&&t.value!==undefined)values.push({value:Number(t.value),caption:String(t.value)+(suffix?' '+suffix.trim():'')});values.sort((a,b)=>Number(a.value)-Number(b.value));opts(s,values,t.value===''||t.value===undefined?min:t.value);s.onchange=()=>t.value=o.varType===1?parseInt(s.value,10):parseFloat(s.value);r.append(s);return}const n=mk('input');n.type='number';n.min=String(min);n.max=String(max);n.step=String(step);n.value=t.value===''||t.value===undefined?String(min):String(t.value);n.onchange=()=>t.value=o.varType===1?parseInt(n.value,10):parseFloat(n.value);r.append(n);if(suffix){const u=mk('span',suffix);u.className='source';r.append(u)}return}const v=mk('input');v.placeholder='Wert';v.value=t.value??'';v.oninput=()=>t.value=v.value;r.append(v)}
async function render(){const root=el('scenes');root.replaceChildren();for(const s of scenes){s.targets=(s.targets||[]).map(migrate);const c=mk('div');c.className='card';const top=mk('div');top.className='row';const n=mk('input');n.className='name';n.value=s.name||'';n.oninput=()=>s.name=n.value;const f=mk('button','Löschen');f.className='danger';f.onclick=()=>forget(s.id);top.append(n,f);c.append(top);if(s.smartButtonName){const src=mk('div','Smart-Taster: '+s.smartButtonName+(s.smartButtonHost?' ('+s.smartButtonHost+')':''));src.className='source';c.append(src)}for(let j=0;j<s.targets.length;j++){const t=s.targets[j];const r=mk('div');r.className='row target';if(t.type==='symcon'){const o=t.objectInfo||await objectInfo(t.object);if(o)t.objectInfo=o;const missing=!!t.object&&t.objectMissing&&!o;const p=mk('button',o?o.path:(missing?'Objekt #'+t.object+' nicht mehr vorhanden':'Objekt auswählen …'));p.className='objfield';p.onclick=()=>pick(t,render);r.append(p);if(o){const tag=mk('span',o.type==='script'?'Script':'Variable');tag.className='source';r.append(tag);await valueEditor(t,r)}}else{const q=mk('select');opts(q,Z,String(t.instance||0)+':'+String(t.channel||0));q.onchange=()=>{const a=q.value.split(':');t.instance=+a[0];t.channel=+a[1]};const mem=mk('select');opts(mem,[1,2,3,4].map(x=>({value:x,caption:'S'+x})),t.memory||1);mem.onchange=()=>t.memory=+mem.value;r.append(q,mem);const direct=mk('span','direkt zeptrionAIR → zeptrionAIR');direct.className='source';r.append(direct)}const d=mk('button','Entfernen');d.onclick=()=>{s.targets.splice(j,1);render()};r.append(d);c.append(r)}const a=mk('div');a.className='row';const addZ=mk('button','+ zeptrionAIR-Ziel');addZ.onclick=()=>{if(!Z.length){msg('Keine zeptrionAIR-Ziele vorhanden.');return}const v=String(Z[0].value).split(':');s.targets.push({type:'zeptrion',instance:+v[0],channel:+v[1],memory:1});render()};const add=mk('button','+ Symcon-Objekt');add.onclick=()=>{s.targets.push({type:'symcon',object:0});render()};const p=mk('button','Smart-Taste programmieren');p.onclick=()=>program(s);a.append(addZ,add,p);c.append(a);root.append(c)}}
function addScene(){scenes.push({id:Math.random().toString(36).slice(2),name:'Neue Szene',targets:[]});render()}
async function program(s){if(!confirm('Die Smart-Tasten beginnen jetzt zu blinken. Bitte danach die gewünschte blinkende Smart-Taste am Schalter drücken.'))return;msg('Smart-Tasten werden aktiviert. Bitte gewünschte blinkende Smart-Taste drücken …');busy(true);const clean=(s.targets||[]).map(t=>{const x={...t};delete x.objectInfo;return x});const r=await api({op:'program',scene:s.id,name:s.name,targets:clean});busy(false);if(r.ok&&r.scenes)scenes=r.scenes;await render();msg(r.message)}
async function forget(id){const r=await api({op:'forget',scene:id});if(r.ok&&r.scenes)scenes=r.scenes;await render();msg(r.message)}
async function clearButton(){if(!confirm('Die Smart-Tasten beginnen jetzt zu blinken. Bitte danach die Smart-Taste drücken, deren Programmierung gelöscht werden soll.'))return;msg('Smart-Tasten werden aktiviert. Bitte die zu löschende Smart-Taste drücken …');busy(true);const r=await api({op:'select-delete'});busy(false);msg(r.message)}
el('addScene').onclick=addScene;el('clearButton').onclick=clearButton;render();
</script></body></html>
HTML;
        return str_replace(['__DATA__', '__HOOK__'], [$data, '/hook/' . $this->SmartButtonHookName()], $html);
    }
    private function GetDeviceHosts(): array
    {
        $result = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_ID) as $id) {
            $host = trim((string)IPS_GetProperty($id, 'Host'));
            if ($host === '') {
                continue;
            }
            $name = trim(IPS_GetName($id));
            $result[$host] = ['instance' => $id, 'name' => $name !== '' ? $name : $host];
        }
        ksort($result, SORT_NATURAL);
        return $result;
    }
    private function GetTargets(): array
    {
        $result = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_ID) as $id) {
            $count = max(1, min(4, (int)IPS_GetProperty($id, 'Channels')));
            for ($channel = 1; $channel <= $count; $channel++) {
                if (strtolower((string)IPS_GetProperty($id, 'Channel' . $channel . 'Type')) === 'unused') {
                    continue;
                }
                $name = trim((string)IPS_GetProperty($id, 'Channel' . $channel . 'Name'));
                if ($name === '') {
                    $name = IPS_GetName($id) . ' / Kanal ' . $channel;
                }
                $result[] = ['value' => $id . ':' . $channel, 'caption' => $name, 'instance' => $id, 'channel' => $channel];
            }
        }
        usort($result, static fn($a, $b) => strnatcasecmp($a['caption'], $b['caption']));
        return $result;
    }
    private function GetObjectTreeChildren(int $parentID): array
    {
        $ids = $parentID === 0 ? IPS_GetChildrenIDs(0) : (IPS_ObjectExists($parentID) ? IPS_GetChildrenIDs($parentID) : []);
        $items = [];
        foreach ($ids as $id) {
            $info = $this->GetTreeObjectInfo((int)$id);
            if ($info !== null) {
                $items[] = $info;
            }
        }
        usort($items, static function (array $a, array $b): int {
            $rank = static function (array $item): int {
                $id = (int)($item['id'] ?? 0);
                if ($id > 0 && IPS_ObjectExists($id)) {
                    $object = IPS_GetObject($id);
                    $objectType = (int)($object['ObjectType'] ?? -1);
                    if ($objectType === 0) {
                        return 0;
                    }
                    if ($objectType === 1) {
                        return 1;
                    }
                }
                return 2;
            };
            $ra = $rank($a);
            $rb = $rank($b);
            return $ra === $rb ? strnatcasecmp((string)$a['name'], (string)$b['name']) : ($ra <=> $rb);
        });
        return $items;
    }
    private function SearchObjectTree(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $needle = mb_strtolower($query);
        $items = [];
        foreach (IPS_GetObjectList() as $id) {
            if (!IPS_VariableExists($id) && !IPS_ScriptExists($id)) {
                continue;
            }
            $path = $this->ObjectPath($id);
            if (mb_strpos(mb_strtolower($path), $needle) === false) {
                continue;
            }
            $info = $this->GetSelectableObjectInfo($id);
            if ($info !== null) {
                $items[] = $info;
            }
            if (count($items) >= 100) {
                break;
            }
        }
        usort($items, static fn(array $a, array $b): int => strnatcasecmp((string)$a['path'], (string)$b['path']));
        return $items;
    }
    private function GetTreeObjectInfo(int $id): ?array
    {
        if (!IPS_ObjectExists($id)) {
            return null;
        }
        $selectable = IPS_VariableExists($id) || IPS_ScriptExists($id);
        $type = IPS_VariableExists($id) ? 'variable' : (IPS_ScriptExists($id) ? 'script' : 'container');
        return [
            'id' => $id,
            'name' => IPS_GetName($id),
            'path' => $this->ObjectPath($id),
            'type' => $type,
            'selectable' => $selectable,
            'hasChildren' => count(IPS_GetChildrenIDs($id)) > 0,
            'icon' => $type === 'variable' ? '●' : ($type === 'script' ? '▶' : '▸')
        ];
    }
    private function GetSelectableObjectInfo(int $id): ?array
    {
        if (IPS_VariableExists($id)) {
            $variable = IPS_GetVariable($id);
            $profileName = (string)($variable['VariableCustomProfile'] ?: $variable['VariableProfile']);
            $associations = [];
            $profileMin = null;
            $profileMax = null;
            $profileStep = null;
            $profileSuffix = '';
            if ($profileName !== '' && IPS_VariableProfileExists($profileName)) {
                $profile = IPS_GetVariableProfile($profileName);
                foreach (($profile['Associations'] ?? []) as $association) {
                    $associations[] = ['value' => $association['Value'], 'name' => $association['Name']];
                }
                $profileMin = $profile['MinValue'] ?? null;
                $profileMax = $profile['MaxValue'] ?? null;
                $profileStep = $profile['StepSize'] ?? null;
                $profileSuffix = (string)($profile['Suffix'] ?? '');
            }
            return [
                'id' => $id,
                'name' => IPS_GetName($id),
                'path' => $this->ObjectPath($id),
                'type' => 'variable',
                'selectable' => true,
                'varType' => (int)$variable['VariableType'],
                'associations' => $associations,
                'profileMin' => $profileMin,
                'profileMax' => $profileMax,
                'profileStep' => $profileStep,
                'profileSuffix' => $profileSuffix,
                'defaultValue' => (int)$variable['VariableType'] === 0 ? 'false' : ($profileMin ?? '')
            ];
        }
        if (IPS_ScriptExists($id)) {
            return [
                'id' => $id,
                'name' => IPS_GetName($id),
                'path' => $this->ObjectPath($id),
                'type' => 'script',
                'selectable' => true
            ];
        }
        return null;
    }
    private function ObjectPath(int $id): string
    {
        $parts = [];
        $current = $id;
        for ($i = 0; $i < 20 && $current > 0; $i++) {
            $parts[] = IPS_GetName($current);
            $current = IPS_GetParent($current);
        }
        return implode(' / ', array_reverse($parts));
    }
    private function NormalizeTargets(array $targets): array
    {
        $out = [];
        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }
            $type = (string)($target['type'] ?? 'zeptrion');
            if ($type === 'zeptrion') {
                $instance = (int)($target['instance'] ?? 0);
                $channel = (int)($target['channel'] ?? 0);
                $memory = (int)($target['memory'] ?? 0);
                if ($this->IsDeviceInstance($instance) && $channel >= 1 && $channel <= 4 && $memory >= 1 && $memory <= 4) {
                    $out[] = ['type' => 'zeptrion', 'instance' => $instance, 'channel' => $channel, 'memory' => $memory];
                }
            } elseif ($type === 'symcon') {
                $id = (int)($target['object'] ?? 0);
                if (IPS_VariableExists($id)) {
                    $variable = IPS_GetVariable($id);
                    $value = $target['value'] ?? '';
                    switch ((int)$variable['VariableType']) {
                        case 0: $value = filter_var($value, FILTER_VALIDATE_BOOLEAN); break;
                        case 1: $value = (int)$value; break;
                        case 2: $value = (float)$value; break;
                        default: $value = (string)$value;
                    }
                    $out[] = ['type' => 'symcon', 'object' => $id, 'value' => $value];
                } elseif (IPS_ScriptExists($id)) {
                    $out[] = ['type' => 'symcon', 'object' => $id];
                }
            } elseif ($type === 'variable') {
                $id = (int)($target['variable'] ?? 0);
                if (IPS_VariableExists($id)) {
                    $out[] = ['type' => 'symcon', 'object' => $id, 'value' => $target['value'] ?? ''];
                }
            } elseif ($type === 'script') {
                $id = (int)($target['script'] ?? 0);
                if (IPS_ScriptExists($id)) {
                    $out[] = ['type' => 'symcon', 'object' => $id];
                }
            }
        }
        return $out;
    }
    private function IsDeviceInstance(int $id): bool
    {
        if ($id <= 0 || !IPS_InstanceExists($id)) {
            return false;
        }
        $instance = IPS_GetInstance($id);
        return (string)($instance['ModuleInfo']['ModuleID'] ?? '') === self::DEVICE_MODULE_ID;
    }
    private function ReadScenes(): array
    {
        $data = json_decode($this->ReadAttributeString('SmartButtonScenes'), true);
        return is_array($data) ? $data : [];
    }
    private function WriteScenes(array $scenes): void
    {
        $this->WriteAttributeString('SmartButtonScenes', json_encode(array_values($scenes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
