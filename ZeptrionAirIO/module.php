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
        // Der externe IPS_Execute-/Shell-Versuch wurde entfernt. Ein dauerhafter
        // /chnotify Long-Poll lässt sich mit reinem PHP-cURL zwar ausführen, würde
        // aber für die Dauer des Requests einen PHP-Thread belegen. Genau das soll
        // diese I/O-Instanz nicht im Hintergrund tun.
        $this->SetBuffer('NotifyPending', '0');
        $this->SendDebug(
            'chnotify',
            'Kein Listener gestartet: reines PHP-cURL ist synchron und würde einen PHP-Thread blockieren',
            0
        );
        $this->SetStatus(104);
    }

}
