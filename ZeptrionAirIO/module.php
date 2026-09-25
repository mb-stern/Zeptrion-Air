<?php

declare(strict_types=1);

class ZeptrionAirIO extends IPSModuleStrict
{
    private const RX_DATA_ID = '{6D87A41A-1B43-4C3D-9F53-2A2E1F6B73A4}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterHook('zeptrionair/' . $this->InstanceID);
        $this->SetBuffer('NotifyToken', '');
        $this->SetBuffer('NotifyPending', '0');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '' || !$this->ReadPropertyBoolean('Active')) {
            $this->SetBuffer('NotifyToken', '');
            $this->SetBuffer('NotifyPending', '0');
            $this->SetStatus($host === '' ? 201 : 104);
            return;
        }

        // Jeder ApplyChanges-Lauf bekommt eine neue Generation. Antworten eines
        // zuvor gestarteten Prozesses werden dadurch verworfen und erzeugen keine
        // zweite chnotify-Kette.
        $token = bin2hex(random_bytes(8));
        $this->SetBuffer('NotifyToken', $token);
        $this->SetBuffer('NotifyPending', '0');
        $this->SetStatus(102);

        $this->StartNotifyProcess();
    }

    public function ProcessHookData(): void
    {
        $token = isset($_GET['token']) ? (string)$_GET['token'] : '';
        if ($token === '' || !hash_equals($this->GetBuffer('NotifyToken'), $token)) {
            $this->SendDebug('chnotify', 'Veraltete Callback-Generation verworfen', 0);
            http_response_code(204);
            return;
        }

        $this->SetBuffer('NotifyPending', '0');
        $body = file_get_contents('php://input');
        if (!is_string($body)) {
            $body = '';
        }

        if (trim($body) !== '') {
            $this->SendDebug('chnotify RAW', $body, 0);
            $this->SendDataToChildren(json_encode([
                'DataID' => self::RX_DATA_ID,
                'Buffer' => bin2hex($body)
            ], JSON_UNESCAPED_SLASHES));
        } else {
            $this->SendDebug('chnotify', 'Long-Poll ohne Nutzdaten beendet', 0);
        }

        http_response_code(204);

        // Erst nach der abgeschlossenen Antwort die nächste Long-Poll-Anfrage
        // asynchron starten. Der PHP-Aufruf selbst wartet nicht auf chnotify.
        if ($this->ReadPropertyBoolean('Active')) {
            $this->StartNotifyProcess();
        }
    }

    public function RestartNotify(): void
    {
        $this->SetBuffer('NotifyPending', '0');
        $this->StartNotifyProcess();
    }

    private function StartNotifyProcess(): void
    {
        if ($this->GetBuffer('NotifyPending') === '1') {
            return;
        }

        $host = trim($this->ReadPropertyString('Host'));
        $token = $this->GetBuffer('NotifyToken');
        if ($host === '' || $token === '' || !$this->ReadPropertyBoolean('Active')) {
            return;
        }

        // Dieser Machbarkeitstest ist absichtlich Linux/Raspberry-Pi-spezifisch.
        // curl hält /chnotify außerhalb des Symcon-PHP-Workers offen. Die Antwort
        // wird über einen ausschließlich lokalen Symcon-WebHook zurückgereicht.
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->SendDebug('chnotify', 'Externer Long-Poll-Test benötigt Linux', 0);
            $this->SetStatus(202);
            return;
        }

        $curl = '/usr/bin/curl';
        $shell = '/bin/sh';

        // Keine PHP-Dateisystemprüfung auf Systemprogramme: je nach Symcon-
        // Sandbox/open_basedir kann is_file()/is_executable() hier false liefern,
        // obwohl IPS_Execute das Programm starten darf.
        $source = 'http://' . $host . '/zrap/chnotify';
        $callback = 'http://127.0.0.1:3777/hook/zeptrionair/' . $this->InstanceID .
            '?token=' . rawurlencode($token);

        // pipefail gibt es bei /bin/sh nicht überall; für den Test reicht die
        // Pipeline. --max-time beendet auch einen hängenden Geräte-Request.
        $command =
            escapeshellarg($curl) . ' --silent --show-error --max-time 35 --connect-timeout 2 ' .
            '--header ' . escapeshellarg('Connection: close') . ' ' . escapeshellarg($source) .
            ' | ' .
            escapeshellarg($curl) . ' --silent --show-error --max-time 5 --connect-timeout 2 ' .
            '--request POST --header ' . escapeshellarg('Content-Type: application/xml') .
            ' --data-binary @- ' . escapeshellarg($callback);

        $this->SetBuffer('NotifyPending', '1');
        $this->SendDebug(
            'chnotify',
            'Start: ' . $source . ' / Plattform=' . IPS_GetKernelPlatform() . ' / Shell=' . $shell . ' / curl=' . $curl,
            0
        );

        try {
            $result = IPS_Execute($shell, '-c ' . escapeshellarg($command), false, false);
            $this->SendDebug('chnotify Start', 'IPS_Execute => ' . var_export($result, true), 0);
            $this->SetStatus(102);
        } catch (Throwable $e) {
            $this->SetBuffer('NotifyPending', '0');
            $this->SetStatus(202);
            $this->SendDebug('chnotify Startfehler', $e->getMessage(), 0);
        }
    }
}
