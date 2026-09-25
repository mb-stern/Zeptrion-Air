<?php

declare(strict_types=1);

class ZeptrionAirIO extends IPSModuleStrict
{
    private mixed $multiHandle = null;
    private mixed $curlHandle = null;
    private bool $requestRunning = false;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterTimer('PumpTimer', 0, 'ZEPAIO_Pump($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->StopRequest();

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '' || !$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('PumpTimer', 0);
            $this->SetStatus($host === '' ? 201 : 104);
            return;
        }

        $this->SendDebug('Lifecycle', 'I/O startet für ' . $host, 0);
        $this->StartRequest();
        $this->SetTimerInterval('PumpTimer', 50);
        $this->SetStatus(102);
    }

    public function Pump(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }

        if (!$this->requestRunning || $this->multiHandle === null || $this->curlHandle === null) {
            $this->SendDebug('chnotify', 'Handle nicht aktiv - starte Request neu', 0);
            $this->StartRequest();
            return;
        }

        do {
            $result = curl_multi_exec($this->multiHandle, $running);
        } while ($result === CURLM_CALL_MULTI_PERFORM);

        if ($result !== CURLM_OK) {
            $this->SendDebug('curl_multi', 'Fehlercode ' . $result, 0);
            $this->RestartRequest();
            return;
        }

        while (($info = curl_multi_info_read($this->multiHandle)) !== false) {
            if (($info['handle'] ?? null) !== $this->curlHandle) {
                continue;
            }

            $body = curl_multi_getcontent($this->curlHandle);
            $httpCode = (int)curl_getinfo($this->curlHandle, CURLINFO_HTTP_CODE);
            $error = curl_error($this->curlHandle);

            $this->SendDebug(
                'chnotify Return',
                'HTTP ' . $httpCode . ($error !== '' ? ' / ' . $error : '') .
                ' / ' . strlen((string)$body) . ' Byte',
                0
            );

            if ($error === '' && $httpCode >= 200 && $httpCode < 400 && trim((string)$body) !== '') {
                $this->SendDebug('chnotify RAW', (string)$body, 0);
                $this->SendDataToChildren(json_encode([
                    'DataID' => '{6D87A41A-1B43-4C3D-9F53-2A2E1F6B73A4}',
                    'Buffer' => bin2hex((string)$body)
                ], JSON_UNESCAPED_SLASHES));
            }

            $this->RestartRequest();
            return;
        }
    }

    private function StartRequest(): void
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return;
        }

        $this->StopRequest();

        $multi = curl_multi_init();
        $curl = curl_init();
        if ($multi === false || $curl === false) {
            $this->SendDebug('chnotify', 'cURL konnte nicht initialisiert werden', 0);
            $this->SetStatus(202);
            return;
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => 'http://' . $host . '/zrap/chnotify',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 1500,
            CURLOPT_TIMEOUT_MS => 35000,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Connection: close']
        ]);

        curl_multi_add_handle($multi, $curl);
        $this->multiHandle = $multi;
        $this->curlHandle = $curl;
        $this->requestRunning = true;

        curl_multi_exec($this->multiHandle, $running);
        $this->SendDebug('chnotify', 'Long-Poll gestartet: http://' . $host . '/zrap/chnotify', 0);
    }

    private function RestartRequest(): void
    {
        $this->StopRequest();
        $this->StartRequest();
    }

    private function StopRequest(): void
    {
        if ($this->multiHandle !== null && $this->curlHandle !== null) {
            @curl_multi_remove_handle($this->multiHandle, $this->curlHandle);
        }
        if ($this->curlHandle !== null) {
            @curl_close($this->curlHandle);
        }
        if ($this->multiHandle !== null) {
            @curl_multi_close($this->multiHandle);
        }

        $this->curlHandle = null;
        $this->multiHandle = null;
        $this->requestRunning = false;
    }
}
