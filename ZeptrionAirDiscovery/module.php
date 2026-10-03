<?php
declare(strict_types=1);
class ZeptrionAirDiscovery extends IPSModuleStrict
{
    private const DEVICE_MODULE_ID = '{75F3D2A4-9D4E-4E5C-A07E-8EFA49D824C1}';
    private const CLIENT_SOCKET_MODULE_ID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const ZEROCONF_MODULE_ID = '{780B2D48-916C-4D59-AD35-5A429B2355A5}';

    public function Create(): void
    {
        parent::Create();
    }

    public function GetConfigurationForm(): string
    {
        $discovered = $this->DiscoverDevices();
        $existing = $this->GetExistingInstanceData();
        $rows = [];
        foreach ($discovered as $device) {
            $host = $device['host'];
            $instance = $existing[$host] ?? null;
            $reachable = (bool)($device['reachable'] ?? false);
            if ($instance !== null && !$reachable) {
                $rows[$host] = $this->BuildExistingInstanceRow($host, $instance, 'Nicht erreichbar');
                continue;
            }
            $rows[$host] = [
                'name' => $host,
                'Host' => $host,
                'IP' => $device['ip'],
                'RSSI' => $device['rssi'],
                'Type' => $device['type'],
                'SerialNumber' => $device['serial'],
                'Software' => $device['sw'],
                'Channels' => $device['channels'],
                'ChannelInfo' => $reachable ? $device['channelInfo'] : 'Nicht erreichbar',
                'instanceID' => $instance['instanceID'] ?? 0
            ];
            if ($reachable && $instance === null) {
                $rows[$host]['create'] = [[
                    'moduleID' => self::DEVICE_MODULE_ID,
                    'configuration' => [
                        'Host' => $host,
                        'DeviceName' => $device['name'],
                        'DeviceType' => $device['type'],
                        'SerialNumber' => $device['serial'],
                        'Channels' => $device['channels'],
                        'Channel1Name' => $device['channelConfig'][1]['name'] ?? 'Kanal 1',
                        'Channel1Type' => $device['channelConfig'][1]['type'] ?? 'unused',
                        'Channel2Name' => $device['channelConfig'][2]['name'] ?? 'Kanal 2',
                        'Channel2Type' => $device['channelConfig'][2]['type'] ?? 'unused',
                        'Channel3Name' => $device['channelConfig'][3]['name'] ?? 'Kanal 3',
                        'Channel3Type' => $device['channelConfig'][3]['type'] ?? 'unused',
                        'Channel4Name' => $device['channelConfig'][4]['name'] ?? 'Kanal 4',
                        'Channel4Type' => $device['channelConfig'][4]['type'] ?? 'unused',
                        'Channel1UpTimeMs' => $this->DefaultTime($device['channelConfig'][1]['type'] ?? 'unused'),
                        'Channel1DownTimeMs' => $this->DefaultTime($device['channelConfig'][1]['type'] ?? 'unused'),
                        'Channel2UpTimeMs' => $this->DefaultTime($device['channelConfig'][2]['type'] ?? 'unused'),
                        'Channel2DownTimeMs' => $this->DefaultTime($device['channelConfig'][2]['type'] ?? 'unused'),
                        'Channel3UpTimeMs' => $this->DefaultTime($device['channelConfig'][3]['type'] ?? 'unused'),
                        'Channel3DownTimeMs' => $this->DefaultTime($device['channelConfig'][3]['type'] ?? 'unused'),
                        'Channel4UpTimeMs' => $this->DefaultTime($device['channelConfig'][4]['type'] ?? 'unused'),
                        'Channel4DownTimeMs' => $this->DefaultTime($device['channelConfig'][4]['type'] ?? 'unused'),
                        'ShowOnline' => true,
                        'ShowRSSI' => true,
                        'ShowScenes' => false,
                        'ShowIPAddress' => false,
                        'ShowDeviceTypeInfo' => false,
                        'ShowSerialNumberInfo' => false,
                        'ShowSoftwareInfo' => false,
                        'ShowChannelActualValues' => false
                    ]
                ], [
                    'moduleID' => self::CLIENT_SOCKET_MODULE_ID,
                    'configuration' => [
                        'Host' => $host,
                        'Port' => 80,
                        'Open' => true
                    ]
                ]];
            } elseif ($reachable && $instance !== null) {
                $rows[$host]['create'] = [[
                    'moduleID' => self::DEVICE_MODULE_ID,
                    'configuration' => ['Host' => $host]
                ], [
                    'moduleID' => self::CLIENT_SOCKET_MODULE_ID,
                    'configuration' => [
                        'Host' => $host,
                        'Port' => 80,
                        'Open' => true
                    ]
                ]];
            }
        }
        foreach ($existing as $host => $instance) {
            if (!isset($rows[$host])) {
                $rows[$host] = $this->BuildExistingInstanceRow($host, $instance, 'Nicht erreichbar / nicht entdeckt');
            }
        }
        $values = array_values($rows);
        usort($values, static fn(array $a, array $b): int => strnatcasecmp($a['Host'], $b['Host']));
        return json_encode([
            'actions' => [[
                'type' => 'Configurator',
                'name' => 'Devices',
                'caption' => 'Gefundene zeptrionAIR WLAN-Module',
                'rowCount' => 15,
                'add' => false,
                'delete' => false,
                'sort' => ['column' => 'Host', 'direction' => 'ascending'],
                'columns' => [
                    ['caption' => 'Host', 'name' => 'Host', 'width' => '145px'],
                    ['caption' => 'IP-Adresse', 'name' => 'IP', 'width' => '125px'],
                    ['caption' => 'RSSI', 'name' => 'RSSI', 'width' => '80px'],
                    ['caption' => 'Gerätetyp', 'name' => 'Type', 'width' => '140px'],
                    ['caption' => 'Seriennr.', 'name' => 'SerialNumber', 'width' => '130px'],
                    ['caption' => 'SW', 'name' => 'Software', 'width' => '90px'],
                    ['caption' => 'Kanäle', 'name' => 'Channels', 'width' => '70px'],
                    ['caption' => 'Verbraucher / Kanäle', 'name' => 'ChannelInfo', 'width' => 'auto']
                ],
                'values' => $values
            ]]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function DefaultTime(string $type): int
    {
        return $type === 'shutter' ? 27000 : ($type === 'awning' ? 25000 : 4000);
    }

    private function DiscoverDevices(): array
    {
        $found = [];
        $zcIDs = IPS_GetInstanceListByModuleID(self::ZEROCONF_MODULE_ID);
        if ($zcIDs === []) {
            $this->SendDebug('Discovery', 'Kein DNS-SD Control (Zeroconf) gefunden', 0);
            return [];
        }
        $zcID = $zcIDs[0];
        $previousCount = -1;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->CollectServices($zcID, '_zapp._tcp', false, $found);
            $count = count($found);
            if ($attempt > 1 && $count === $previousCount) break;
            $previousCount = $count;
            if ($attempt < 3) usleep(250000);
        }
        $this->CollectServices($zcID, '_http._tcp', true, $found);
        $this->EnrichDevicesParallel($found);
        return array_values($found);
    }

    private function CollectServices(int $zcID, string $type, bool $legacyOnly, array &$found): void
    {
        try {
            $services = ZC_QueryServiceType($zcID, $type, '');
        } catch (Throwable $e) {
            $this->SendDebug('mDNS ' . $type, $e->getMessage(), 0);
            return;
        }
        if (!is_array($services)) return;
        foreach ($services as $service) {
            $name = (string)($service['Name'] ?? '');
            if ($legacyOnly && !preg_match('/^zapp-\d{8}$/i', $name)) continue;
            $host = rtrim($name, '.');
            if ($host === '') continue;
            $found[$host] = [
                'host' => $host,
                'ip' => '',
                'rssi' => '',
                'name' => preg_replace('/\.local\.?$/i', '', $name) ?: $name,
                'type' => '',
                'serial' => '',
                'sw' => '',
                'channels' => 2,
                'channelInfo' => '',
                'channelConfig' => []
            ];
        }
    }

    private function EnrichDevicesParallel(array &$devices): void
    {
        if ($devices === []) return;
        $requests = [];
        foreach ($devices as $host => $device) {
            $requests[$host . '|id'] = ['host' => $host, 'path' => '/zrap/id'];
            $requests[$host . '|chdes'] = ['host' => $host, 'path' => '/zrap/chdes'];
            $requests[$host . '|rssi'] = ['host' => $host, 'path' => '/zrap/rssi'];
        }
        $responses = $this->HttpXmlGetMulti($requests);
        $retry = [];
        foreach ($requests as $key => $request) if (($responses[$key] ?? null) === null) $retry[$key] = $request;
        if ($retry !== []) {
            foreach ($this->HttpXmlGetMulti($retry, 2500, 5000) as $key => $response) {
                if ($response !== null) $responses[$key] = $response;
            }
        }
        // Wenn nur /zrap/chdes fehlt, obwohl /zrap/id erfolgreich war,
        // die Kanaldaten dieses zApps gezielt noch zweimal einzeln nachladen.
        foreach ($devices as $host => $device) {
            $idKey = $host . '|id';
            $chdesKey = $host . '|chdes';
            $id = $responses[$idKey] ?? null;
            if ($id === null || strtoupper((string)($id['sys'] ?? '')) !== 'ZEPTRION') {
                continue;
            }
            if (($responses[$chdesKey] ?? null) !== null) {
                continue;
            }
            // zApps beantworten /zrap/chdes teilweise deutlich später als /zrap/id.
            // Deshalb gezielt länger nachladen, damit beim Anlegen die erkannten
            // Kanaltypen/-namen vorhanden sind und nicht auf "unused" zurückfallen.
            for ($attempt = 2; $attempt <= 6; $attempt++) {
                $this->SendDebug('Discovery Retry', $host . ' /zrap/chdes - Versuch ' . $attempt . '/6', 0);
                usleep($attempt <= 3 ? 300000 : 600000);
                $single = $this->HttpXmlGetMulti([
                    $chdesKey => ['host' => $host, 'path' => '/zrap/chdes']
                ], 3000, 6500);
                if (($single[$chdesKey] ?? null) !== null) {
                    $responses[$chdesKey] = $single[$chdesKey];
                    $this->SendDebug('Discovery Retry', $host . ' /zrap/chdes - erfolgreich bei Versuch ' . $attempt . '/6', 0);
                    break;
                }
                if ($attempt === 6) {
                    $this->SendDebug('Discovery Retry', $host . ' /zrap/chdes - nach 6 Versuchen keine Antwort', 0);
                }
            }
        }
        foreach ($devices as $host => &$device) {
            $id = $responses[$host . '|id'] ?? null;
            $device['ip'] = $this->ResolveIPv4($host);
            $device['rssi'] = $this->ExtractRssi($responses[$host . '|rssi'] ?? null);
            $device['reachable'] = $id !== null && strtoupper((string)($id['sys'] ?? '')) === 'ZEPTRION';
            $this->ApplyApiData($device, $id, $responses[$host . '|chdes'] ?? null);
        }
        unset($device);
    }

    private function ResolveIPv4(string $host): string
    {
        $ip = gethostbyname($host);
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '';
    }

    private function ExtractRssi(?array $data): string
    {
        if ($data === null) return '';
        foreach (['rssi', 'val', 'value'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) return (string)((int)$data[$key]) . ' dBm';
        }
        foreach ($data as $value) {
            if (is_numeric($value)) return (string)((int)$value) . ' dBm';
            if (is_array($value)) {
                $nested = $this->ExtractRssi($value);
                if ($nested !== '') return $nested;
            }
        }
        return '';
    }

    private function HttpXmlGetMulti(array $requests, int $connectTimeoutMs = 1500, int $timeoutMs = 3500): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($requests as $key => $request) {
            $url = 'http://' . $request['host'] . $request['path'];
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => ['Connection: close']
            ]);
            curl_multi_add_handle($multi, $curl);
            $handles[$key] = ['handle' => $curl, 'url' => $url];
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) curl_multi_select($multi, 0.25);
        } while ($running > 0 && $status === CURLM_OK);
        $result = [];
        foreach ($handles as $key => $entry) {
            $curl = $entry['handle'];
            $body = curl_multi_getcontent($curl);
            $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            $result[$key] = ($error === '' && $httpCode >= 200 && $httpCode < 400 && is_string($body) && trim($body) !== '') ? $this->ParseXml($body) : null;
            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);
        }
        curl_multi_close($multi);
        return $result;
    }

    private function ApplyApiData(array &$device, ?array $id, ?array $des): void
    {
        if ($id !== null) {
            $sys = strtoupper((string)($id['sys'] ?? ''));
            if ($sys !== '' && $sys !== 'ZEPTRION') return;
            $device['type'] = (string)($id['type'] ?? $device['type']);
            $device['serial'] = (string)($id['sn'] ?? '');
            $device['sw'] = (string)($id['sw'] ?? $device['sw']);
            $device['name'] = trim((string)($id['oen'] ?? $id['name'] ?? $device['name']));
            $device['channels'] = $this->ChannelsFromType($device['type'], $device['channels']);
        }
        if ($des === null) return;
        $parts = [];
        for ($channel = 1; $channel <= $device['channels']; $channel++) {
            $key = 'ch' . $channel;
            if (!isset($des[$key]) || !is_array($des[$key])) continue;
            $ch = $des[$key];
            $label = trim((string)($ch['name'] ?? ''));
            $type = trim((string)($ch['type'] ?? ''));
            $cat = trim((string)($ch['cat'] ?? ''));
            if ($cat === '-1') $this->SendDebug('Kanal -1 RAW', $device['host'] . ' / K' . $channel . ' => ' . json_encode($ch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
            $mapped = $this->MapChannelCategory($cat, $label);
            $device['channelConfig'][$channel] = ['name' => $label !== '' ? $label : 'Kanal ' . $channel, 'type' => $mapped['type'], 'scenes' => false];
            $text = 'K' . $channel . ($label !== '' ? ': ' . $label : '');
            if ($type !== '' || $cat !== '') $text .= ' [' . trim($type . '/' . $cat, '/') . ']';
            $parts[] = $text . ' – ' . $mapped['label'];
        }
        $device['channelInfo'] = implode(' | ', $parts);
    }

    private function MapChannelCategory(string $cat, string $name): array
    {
        return match ($cat) {
            '1' => ['type' => 'light', 'label' => 'Licht'],
            '3' => ['type' => 'dimmer', 'label' => 'Dimmer'],
            '5' => ['type' => 'shutter', 'label' => 'Rollo'],
            '6' => ['type' => 'awning', 'label' => 'Markise'],
            default => ['type' => 'unused', 'label' => 'Leer / Smart-Taster']
        };
    }

    private function ParseXml(string $xml): ?array
    {
        libxml_use_internal_errors(true);
        $node = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($node === false) {
            libxml_clear_errors();
            return null;
        }
        $data = json_decode((string)json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);
        return is_array($data) ? $data : null;
    }

    private function ChannelsFromType(string $type, int $fallback = 2): int
    {
        if (preg_match('/^3340-(\d)-/i', $type, $m)) return max(1, min(4, (int)$m[1]));
        return $fallback;
    }

    private function GetExistingInstanceData(): array
    {
        $result = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_ID) as $instanceID) {
            $host = trim((string)IPS_GetProperty($instanceID, 'Host'));
            if ($host === '') continue;
            $channels = max(1, min(4, (int)IPS_GetProperty($instanceID, 'Channels')));
            $channelConfig = [];
            $parts = [];
            for ($channel = 1; $channel <= $channels; $channel++) {
                $name = trim((string)IPS_GetProperty($instanceID, 'Channel' . $channel . 'Name'));
                $type = (string)IPS_GetProperty($instanceID, 'Channel' . $channel . 'Type');
                $channelConfig[$channel] = ['name' => $name, 'type' => $type];
                $label = match ($type) {
                    'light' => 'Licht',
                    'dimmer' => 'Dimmer',
                    'shutter' => 'Rollo',
                    'awning' => 'Markise',
                    default => 'Leer / Smart-Taster'
                };
                $parts[] = 'K' . $channel . ': ' . ($name !== '' ? $name : 'Kanal ' . $channel) . ' – ' . $label;
            }
            $result[$host] = [
                'instanceID' => $instanceID,
                'name' => trim(IPS_GetName($instanceID)) !== '' ? IPS_GetName($instanceID) : $host,
                'type' => (string)IPS_GetProperty($instanceID, 'DeviceType'),
                'serial' => (string)IPS_GetProperty($instanceID, 'SerialNumber'),
                'channels' => $channels,
                'channelConfig' => $channelConfig,
                'channelInfo' => implode(' | ', $parts)
            ];
        }
        return $result;
    }

    private function BuildExistingInstanceRow(string $host, array $instance, string $state): array
    {
        return [
            'Host' => $host,
            'IP' => '',
            'RSSI' => '',
            'Type' => $instance['type'],
            'SerialNumber' => $instance['serial'],
            'Software' => '',
            'Channels' => $instance['channels'],
            'ChannelInfo' => $state . ' – ' . $instance['channelInfo'],
            'instanceID' => $instance['instanceID']
        ];
    }
}
