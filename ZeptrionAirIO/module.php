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
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '' || !$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus($host === '' ? 201 : 104);
            return;
        }

        // Dieses I/O hat absichtlich keinen I/O-Parent. Der vorherige SSE-Test
        // kann hier nicht funktionieren, weil Symcon I/O-Instanzen nicht an
        // andere Instanzen verbindet.
        $this->SendDebug('chnotify', 'Kein Listener aktiv - SSE-Test zurückgebaut', 0);
        $this->SetStatus(104);
    }

    public function ReceiveData(string $JSONString): string
    {
        $this->SendDebug('SSE RAW', $JSONString, 0);

        $packet = json_decode($JSONString, true);
        if (!is_array($packet)) {
            return '';
        }

        // Für den Machbarkeitstest zuerst die komplette SSE-Nachricht sichtbar
        // machen. Falls chnotify vom SSE Client durchgereicht wird, suchen wir den
        // XML-Inhalt sowohl in typischen Feldern als auch im kompletten Paket.
        $candidates = [];
        foreach (['Data', 'Buffer', 'data', 'Payload'] as $key) {
            if (isset($packet[$key]) && is_string($packet[$key])) {
                $candidates[] = $packet[$key];
            }
        }
        $candidates[] = $JSONString;

        foreach ($candidates as $candidate) {
            $start = strpos($candidate, '<?xml');
            if ($start === false) {
                $start = strpos($candidate, '<chnotify');
            }
            if ($start === false) {
                continue;
            }
            $xml = substr($candidate, $start);
            $end = strpos($xml, '</chnotify>');
            if ($end !== false) {
                $xml = substr($xml, 0, $end + strlen('</chnotify>'));
            }
            $this->SendDebug('chnotify RAW', $xml, 0);
            $this->SendDataToChildren(json_encode([
                'DataID' => self::RX_DATA_ID,
                'Buffer' => bin2hex($xml)
            ], JSON_UNESCAPED_SLASHES));
            break;
        }
        return '';
    }


}
