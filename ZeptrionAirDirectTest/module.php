<?php
declare(strict_types=1);

class ZeptrionAirDirectTest extends IPSModuleStrict
{
    private const CLIENT_SOCKET_ID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const TX_DATA_ID = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Channels', 2);
        $this->RegisterAttributeString('ReceiveBuffer', '');
        $this->RegisterAttributeBoolean('NotifyPending', false);
        $this->RegisterTimer('Recovery', 0, 'ZEPADT_Recovery($_IPS[\'TARGET\']);');
        for ($channel = 1; $channel <= 4; $channel++) {
            $this->RegisterVariableInteger('Ch' . $channel . 'Value', 'Kanal ' . $channel, '~Intensity.100', $channel * 10);
        }
    }

    public function GetCompatibleParents(): string
    {
        return '{"type":"connect","moduleIDs":["' . self::CLIENT_SOCKET_ID . '"]}';
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP-Adresse / Hostname'],
                ['type' => 'NumberSpinner', 'name' => 'Channels', 'caption' => 'Kanäle', 'minimum' => 1, 'maximum' => 4],
                ['type' => 'Label', 'caption' => 'Normalbetrieb: dauerhaftes chnotify. Ist der Client Socket getrennt, wird alle 10 Sekunden per chscan geprüft. Sobald das Gerät wieder erreichbar und der Socket verbunden ist, startet chnotify wieder.']
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'chscan jetzt lesen', 'onClick' => 'echo ZEPADT_Scan($id);'],
                ['type' => 'Button', 'caption' => 'chnotify neu starten', 'onClick' => 'ZEPADT_RestartNotify($id);']
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Verbindung unterbrochen - Recovery aktiv'],
                ['code' => 201, 'icon' => 'inactive', 'caption' => 'Host fehlt'],
                ['code' => 202, 'icon' => 'error', 'caption' => 'Client Socket fehlt']
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetReceiveDataFilter('.*');
        $this->WriteAttributeString('ReceiveBuffer', '');
        $this->WriteAttributeBoolean('NotifyPending', false);
        $this->SetTimerInterval('Recovery', 0);
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->SetStatus(201);
            return;
        }
        $parentID = $this->GetParentID();
        if ($parentID <= 0) {
            $this->SetStatus(202);
            return;
        }
        $parentConfig = json_decode(IPS_GetConfiguration($parentID), true);
        $needsApply = !is_array($parentConfig)
            || (string)($parentConfig['Host'] ?? '') !== $host
            || (int)($parentConfig['Port'] ?? 0) !== 80
            || !((bool)($parentConfig['Open'] ?? false));
        if ($needsApply) {
            IPS_SetProperty($parentID, 'Host', $host);
            IPS_SetProperty($parentID, 'Port', 80);
            IPS_SetProperty($parentID, 'Open', true);
            IPS_ApplyChanges($parentID);
        }
        if (!$this->IsParentConnected()) {
            $this->StartRecovery('Client Socket ist noch nicht verbunden');
            return;
        }
        $this->SetStatus(102);
        $this->EnsureNotify();
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) return '';
        $chunk = (string)($data['Buffer'] ?? '');
        if ($chunk === '') return '';
        $buffer = $this->ReadAttributeString('ReceiveBuffer') . $chunk;
        $this->WriteAttributeString('ReceiveBuffer', $buffer);
        $this->SendDebug('chNotify RX', $chunk, 0);
        $response = $this->ExtractHttpResponse($buffer);
        if ($response === null) return '';
        $this->WriteAttributeString('ReceiveBuffer', (string)$response['rest']);
        $this->WriteAttributeBoolean('NotifyPending', false);
        if ((int)$response['status'] >= 200 && (int)$response['status'] < 300) {
            $this->ApplyXmlChannelValues((string)$response['body']);
            $this->SetTimerInterval('Recovery', 0);
            $this->SetStatus(102);
            $this->EnsureNotify();
        } else {
            $this->SendDebug('chNotify HTTP', 'Status ' . (int)$response['status'], 0);
            $this->StartRecovery('HTTP ' . (int)$response['status']);
        }
        return '';
    }

    public function EnsureNotify(): void
    {
        if ($this->ReadAttributeBoolean('NotifyPending')) return;
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') return;
        if (!$this->IsParentConnected()) {
            $this->StartRecovery('Client Socket ist nicht verbunden');
            return;
        }
        $request = "GET /zrap/chnotify HTTP/1.1\r\n"
            . 'Host: ' . $host . "\r\n"
            . "Accept: application/xml,text/xml,*/*\r\n"
            . "Connection: keep-alive\r\n\r\n";
        $payload = json_encode(['DataID' => self::TX_DATA_ID, 'Buffer' => $request], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->WriteAttributeString('ReceiveBuffer', '');
        $this->WriteAttributeBoolean('NotifyPending', true);
        try {
            $this->SendDataToParent((string)$payload);
            $this->SendDebug('chNotify TX', 'GET /zrap/chnotify', 0);
        } catch (Throwable $e) {
            $this->WriteAttributeBoolean('NotifyPending', false);
            $this->StartRecovery('Senden fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function RestartNotify(): void
    {
        $this->SetTimerInterval('Recovery', 0);
        $this->WriteAttributeString('ReceiveBuffer', '');
        $this->WriteAttributeBoolean('NotifyPending', false);
        if (!$this->IsParentConnected()) {
            $this->StartRecovery('Client Socket ist nicht verbunden');
            return;
        }
        $this->SetStatus(102);
        $this->EnsureNotify();
    }

    public function Recovery(): void
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') return;
        $this->SendDebug('Recovery', 'Prüfe Gerät per chscan', 0);
        $result = $this->HttpRequest('GET', '/zrap/chscan');
        if (!$result['success']) {
            $this->SendDebug('Recovery', 'chscan fehlgeschlagen: ' . $result['error'], 0);
            return;
        }
        $this->ApplyXmlChannelValues($result['body']);
        if (!$this->IsParentConnected()) {
            $this->SendDebug('Recovery', 'Gerät erreichbar, Client Socket aber noch nicht verbunden', 0);
            return;
        }
        $this->SendDebug('Recovery', 'Client Socket wieder verbunden - starte chnotify', 0);
        $this->SetTimerInterval('Recovery', 0);
        $this->WriteAttributeString('ReceiveBuffer', '');
        $this->WriteAttributeBoolean('NotifyPending', false);
        $this->SetStatus(102);
        $this->EnsureNotify();
    }

    public function Scan(): string
    {
        $result = $this->HttpRequest('GET', '/zrap/chscan');
        if (!$result['success']) return 'chscan fehlgeschlagen: ' . $result['error'];
        $this->ApplyXmlChannelValues($result['body']);
        return 'chscan erfolgreich';
    }

    public function SetChannel(int $Channel, int $Value): bool
    {
        $max = max(1, min(4, $this->ReadPropertyInteger('Channels')));
        if ($Channel < 1 || $Channel > $max) throw new InvalidArgumentException('Ungültiger Kanal');
        $value = max(0, min(100, $Value));
        $command = $value === 0 ? 'off' : ($value === 100 ? 'on' : null);
        if ($command === null) throw new InvalidArgumentException('Der Direkt-Test schaltet vorerst nur 0 oder 100 Prozent.');
        $result = $this->HttpRequest('POST', '/zrap/chctrl/ch' . $Channel, ['cmd' => $command]);
        if ($result['success']) $this->SetValue('Ch' . $Channel . 'Value', $value);
        return $result['success'];
    }

    private function GetParentID(): int
    {
        $instance = IPS_GetInstance($this->InstanceID);
        $parentID = (int)($instance['ConnectionID'] ?? 0);
        return ($parentID > 0 && IPS_InstanceExists($parentID)) ? $parentID : 0;
    }

    private function IsParentConnected(): bool
    {
        $parentID = $this->GetParentID();
        if ($parentID <= 0) return false;
        $parent = IPS_GetInstance($parentID);
        return (int)($parent['InstanceStatus'] ?? 0) === 102;
    }

    private function StartRecovery(string $reason): void
    {
        $this->SendDebug('Recovery', 'Aktiviert: ' . $reason, 0);
        $this->WriteAttributeString('ReceiveBuffer', '');
        $this->WriteAttributeBoolean('NotifyPending', false);
        $this->SetStatus(104);
        $this->SetTimerInterval('Recovery', 10000);
    }

    private function HttpRequest(string $method, string $path, ?array $form = null): array
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') return ['success' => false, 'body' => '', 'error' => 'Host fehlt'];
        $curl = curl_init();
        if ($curl === false) return ['success' => false, 'body' => '', 'error' => 'cURL konnte nicht initialisiert werden'];
        $options = [CURLOPT_URL => 'http://' . $host . $path, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 1500, CURLOPT_TIMEOUT_MS => 5000, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_HTTPHEADER => ['Connection: close']];
        if ($form !== null) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($form);
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded', 'Connection: close'];
        }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return ['success' => $body !== false && $error === '' && $code >= 200 && $code < 400, 'body' => is_string($body) ? $body : '', 'error' => $error !== '' ? $error : ('HTTP ' . $code)];
    }

    private function ApplyXmlChannelValues(string $xmlText): void
    {
        $start = strpos($xmlText, '<?xml');
        if ($start !== false) $xmlText = substr($xmlText, $start);
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string(trim($xmlText), 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            libxml_clear_errors();
            $this->SendDebug('XML', 'Ungültige Antwort: ' . $xmlText, 0);
            return;
        }
        $max = max(1, min(4, $this->ReadPropertyInteger('Channels')));
        for ($channel = 1; $channel <= $max; $channel++) {
            $key = 'ch' . $channel;
            if (!isset($xml->{$key})) continue;
            $node = $xml->{$key};
            $raw = isset($node->val) ? (string)$node->val : (string)$node;
            if (!is_numeric($raw)) continue;
            $value = max(0, min(100, (int)round((float)$raw)));
            $this->SetValue('Ch' . $channel . 'Value', $value);
        }
    }

    private function ExtractHttpResponse(string $buffer): ?array
    {
        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd === false) return null;
        $header = substr($buffer, 0, $headerEnd);
        $bodyStart = $headerEnd + 4;
        $status = 0;
        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $header, $m)) $status = (int)$m[1];
        $length = null;
        if (preg_match('/\r\nContent-Length:\s*(\d+)/i', $header, $m)) $length = (int)$m[1];
        if ($length !== null) {
            if (strlen($buffer) < $bodyStart + $length) return null;
            return ['status' => $status, 'body' => substr($buffer, $bodyStart, $length), 'rest' => substr($buffer, $bodyStart + $length)];
        }
        $body = substr($buffer, $bodyStart);
        foreach (['</chnotify>', '</chscan>'] as $closing) {
            $end = strpos($body, $closing);
            if ($end !== false) {
                $end += strlen($closing);
                return ['status' => $status, 'body' => substr($body, 0, $end), 'rest' => substr($body, $end)];
            }
        }
        return null;
    }
}
