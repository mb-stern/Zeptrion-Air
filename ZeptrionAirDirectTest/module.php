<?php
declare(strict_types=1);

class ZeptrionAirDirectTest extends IPSModuleStrict
{
    private const TX = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const CS = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Channels', 2);
        $this->RegisterPropertyInteger('RecoveryInterval', 10);
        $this->RegisterTimer('RecoveryTimer', 0, 'ZEPADT_Recovery($_IPS["TARGET"]);');
        $this->SetBuffer('Buffer', '');
        $this->SetBuffer('Listening', '0');
        $this->SetBuffer('Pending', '');
        $this->SetBuffer('Online', '0');
        for ($channel = 1; $channel <= 4; $channel++) {
            $this->RegisterVariableInteger('Ch' . $channel . 'Value', 'Kanal ' . $channel, '~Intensity.100', $channel * 10);
        }
    }

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'require', 'moduleIDs' => [self::CS]]);
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP-Adresse / Hostname'],
                ['type' => 'NumberSpinner', 'name' => 'Channels', 'caption' => 'Kanäle', 'minimum' => 1, 'maximum' => 4],
                ['type' => 'NumberSpinner', 'name' => 'RecoveryInterval', 'caption' => 'Recovery-Intervall (Sekunden)', 'minimum' => 5, 'maximum' => 300],
                ['type' => 'Label', 'caption' => 'Kommunikation entspricht dem funktionierenden Chnotify-Test: chscan und chnotify laufen ueber denselben Client Socket.']
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Listener starten / neu starten', 'onClick' => 'ZEPADT_StartListener($id);'],
                ['type' => 'Button', 'caption' => 'Listener stoppen', 'onClick' => 'ZEPADT_StopListener($id);'],
                ['type' => 'Button', 'caption' => 'Recovery jetzt testen', 'onClick' => 'ZEPADT_ForceRecovery($id);']
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Recovery aktiv'],
                ['code' => 201, 'icon' => 'inactive', 'caption' => 'Host fehlt'],
                ['code' => 202, 'icon' => 'error', 'caption' => 'Client Socket fehlt']
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetReceiveDataFilter('.*');
        $this->SetBuffer('Buffer', '');
        $this->SetBuffer('Pending', '');
        $this->SetBuffer('Online', '0');
        $this->SetBuffer('Listening', '1');
        $this->SetTimerInterval('RecoveryTimer', 0);

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->SetStatus(201);
            return;
        }

        $socketID = $this->GetSocketID();
        if ($socketID <= 0 || !IPS_InstanceExists($socketID)) {
            $this->SetStatus(202);
            return;
        }

        $cfg = json_decode(IPS_GetConfiguration($socketID), true);
        if (!is_array($cfg) || (string)($cfg['Host'] ?? '') !== $host || (int)($cfg['Port'] ?? 0) !== 80 || !((bool)($cfg['Open'] ?? false))) {
            IPS_SetProperty($socketID, 'Host', $host);
            IPS_SetProperty($socketID, 'Port', 80);
            IPS_SetProperty($socketID, 'Open', true);
            IPS_ApplyChanges($socketID);
        }

        if ($this->HasActiveParent()) {
            $this->StartListener();
        } else {
            $this->EnterRecovery('Parent nicht aktiv');
        }
    }

    public function StartListener(): bool
    {
        $this->SetBuffer('Listening', '1');
        $this->SetBuffer('Buffer', '');
        $this->SetBuffer('Pending', '');
        if (!$this->HasActiveParent()) {
            $this->EnterRecovery('Parent nicht aktiv');
            return false;
        }
        $this->SetTimerInterval('RecoveryTimer', 0);
        $this->SetStatus(102);
        return $this->SendRequest('/zrap/chscan', 'scan');
    }

    public function StopListener(): bool
    {
        $this->SetBuffer('Listening', '0');
        $this->SetBuffer('Pending', '');
        $this->SetBuffer('Buffer', '');
        $this->SetBuffer('Online', '0');
        $this->SetTimerInterval('RecoveryTimer', 0);
        $this->SendDebug('STATE', 'Listener gestoppt', 0);
        return true;
    }

    public function ForceRecovery(): bool
    {
        if ($this->GetBuffer('Listening') !== '1') {
            $this->SetBuffer('Listening', '1');
        }
        $this->EnterRecovery('Recovery manuell ausgeloest');
        return true;
    }

    public function Recovery(): void
    {
        if ($this->GetBuffer('Listening') !== '1') {
            $this->SetTimerInterval('RecoveryTimer', 0);
            return;
        }

        if (!$this->HasActiveParent()) {
            $this->SendDebug('RECOVERY', 'Client Socket noch nicht aktiv -> auf Symcon-Reconnect warten', 0);
            return;
        }

        if ($this->GetBuffer('Pending') !== '') {
            $this->SetBuffer('Pending', '');
            $this->SetBuffer('Buffer', '');
        }

        $this->SendDebug('RECOVERY', 'Probe mit chscan', 0);
        $this->SendRequest('/zrap/chscan', 'scan');
    }

    public function ReceiveData(string $JSONString): string
    {
        $d = json_decode($JSONString, true);
        if (!is_array($d) || !isset($d['Buffer']) || $d['Buffer'] === '') {
            return '';
        }

        // Wie im funktionierenden Test: Buffer unveraendert uebernehmen.
        $buffer = $this->GetBuffer('Buffer') . (string)$d['Buffer'];

        while (true) {
            $r = $this->Extract($buffer);
            if ($r === null) {
                break;
            }
            $buffer = $r['rest'];

            $kind = $this->GetBuffer('Pending');
            $this->SetBuffer('Pending', '');
            $this->SetBuffer('Online', '1');
            $this->SendDebug('HTTP', (string)$r['status'] . ' ' . $kind, 0);

            if ($r['status'] !== 200 && $r['status'] !== 302) {
                $this->EnterRecovery('HTTP Status ' . $r['status']);
                continue;
            }

            if ($kind === 'scan') {
                $this->ProcessScan($r['body']);
                $this->SetTimerInterval('RecoveryTimer', 0);
                $this->SetStatus(102);
                $this->SendDebug('RECOVERY', 'chscan OK -> Recovery AUS', 0);
                if ($this->GetBuffer('Listening') === '1') {
                    $this->SendDebug('STATE', 'chscan OK -> chnotify aktivieren', 0);
                }
            } elseif ($kind === 'notify') {
                $this->ProcessNotify($r['body']);
            }

            if ($this->GetBuffer('Listening') === '1' && $this->GetBuffer('Pending') === '') {
                $this->SendRequest('/zrap/chnotify', 'notify');
            }
        }

        $this->SetBuffer('Buffer', $buffer);
        return '';
    }

    private function EnterRecovery(string $reason): void
    {
        $this->SetBuffer('Online', '0');
        $this->SetBuffer('Pending', '');
        $this->SetBuffer('Buffer', '');
        $sec = max(5, $this->ReadPropertyInteger('RecoveryInterval'));
        $this->SetTimerInterval('RecoveryTimer', $sec * 1000);
        $this->SetStatus(104);
        $this->SendDebug('RECOVERY', $reason . ' -> alle ' . $sec . ' s chscan versuchen', 0);
    }

    private function SendRequest(string $path, string $kind): bool
    {
        if ($this->GetBuffer('Listening') !== '1' || !$this->HasActiveParent()) {
            $this->EnterRecovery('Senden nicht moeglich');
            return false;
        }
        if ($this->GetBuffer('Pending') !== '') {
            return false;
        }

        // Exakt aus dem funktionierenden ZeptrionChnotifyTest.
        $q = "GET " . $path . " HTTP/1.1\r\n" .
             "Host: zeptrion\r\n" .
             "Accept: application/xml,text/xml,*/*\r\n" .
             "Cache-Control: no-cache\r\n" .
             "Connection: keep-alive\r\n\r\n";

        $this->SetBuffer('Pending', $kind);
        $this->SendDebug('TX', $kind . ' ' . $path, 0);
        $ok = $this->SendDataToParent(json_encode(['DataID' => self::TX, 'Buffer' => $q]));

        if ($ok === false) {
            $this->SetBuffer('Pending', '');
            $this->EnterRecovery('SendDataToParent fehlgeschlagen');
            return false;
        }
        return true;
    }

    private function ProcessScan(string $body): void
    {
        $this->SendDebug('CHSCAN RAW', trim($body), 0);
        $x = @simplexml_load_string(trim($body));
        if ($x === false) {
            return;
        }
        $max = max(1, min(4, $this->ReadPropertyInteger('Channels')));
        foreach ($x->children() as $c => $n) {
            $v = isset($n->val) ? (string)$n->val : '';
            $this->SendDebug('SYNC', (string)$c . ' = ' . $v, 0);
            if (preg_match('/^ch([1-4])$/', (string)$c, $m) && (int)$m[1] <= $max && is_numeric($v)) {
                $this->SetValue('Ch' . $m[1] . 'Value', max(0, min(100, (int)round((float)$v))));
            }
        }
    }

    private function ProcessNotify(string $body): void
    {
        $this->SendDebug('NOTIFY RAW', trim($body), 0);
        $x = @simplexml_load_string(trim($body));
        if ($x === false) {
            return;
        }
        $max = max(1, min(4, $this->ReadPropertyInteger('Channels')));
        foreach ($x->children() as $c => $n) {
            $v = isset($n->val) ? (string)$n->val : '';
            $this->SendDebug('EVENT', (string)$c . ' = ' . $v, 0);
            if (preg_match('/^ch([1-4])$/', (string)$c, $m) && (int)$m[1] <= $max && is_numeric($v)) {
                $this->SetValue('Ch' . $m[1] . 'Value', max(0, min(100, (int)round((float)$v))));
            }
        }
    }

    private function GetSocketID(): int
    {
        $instance = IPS_GetInstance($this->InstanceID);
        return (int)($instance['ConnectionID'] ?? 0);
    }

    private function HasActiveParent(): bool
    {
        $socketID = $this->GetSocketID();
        if ($socketID <= 0 || !IPS_InstanceExists($socketID)) {
            return false;
        }
        $socket = IPS_GetInstance($socketID);
        return (int)($socket['InstanceStatus'] ?? 0) === 102;
    }

    private function Extract(string $b): ?array
    {
        $he = strpos($b, "\r\n\r\n");
        if ($he === false) return null;
        $h = substr($b, 0, $he);
        $bs = $he + 4;
        $ls = explode("\r\n", $h);
        $sl = array_shift($ls);
        $st = 0;
        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/', (string)$sl, $m)) $st = (int)$m[1];

        $hs = [];
        foreach ($ls as $l) {
            $p = strpos($l, ':');
            if ($p !== false) $hs[strtolower(trim(substr($l, 0, $p)))] = trim(substr($l, $p + 1));
        }

        if (isset($hs['content-length'])) {
            $n = (int)$hs['content-length'];
            if (strlen($b) < $bs + $n) return null;
            return ['status' => $st, 'body' => substr($b, $bs, $n), 'rest' => substr($b, $bs + $n)];
        }

        if ($st === 302 || $st === 204) {
            return ['status' => $st, 'body' => '', 'rest' => substr($b, $bs)];
        }

        foreach (['</chnotify>', '</chscan>'] as $tag) {
            $p = strpos($b, $tag, $bs);
            if ($p !== false) {
                $e = $p + strlen($tag);
                return ['status' => $st, 'body' => substr($b, $bs, $e - $bs), 'rest' => substr($b, $e)];
            }
        }
        return null;
    }
}
