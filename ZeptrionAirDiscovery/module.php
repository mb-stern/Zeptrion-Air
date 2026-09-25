<?php

declare(strict_types=1);

class ZeptrionAirDiscovery extends IPSModuleStrict
{
    private const DEVICE_MODULE_ID = '{75F3D2A4-9D4E-4E5C-A07E-8EFA49D824C1}';
    private const ZEROCONF_MODULE_ID = '{780B2D48-916C-4D59-AD35-5A429B2355A5}';

    public function Create(): void
    {
        parent::Create();
    }

    public function GetConfigurationForm(): string
    {
        $devices = $this->DiscoverDevices();
        $existing = $this->GetExistingInstances();

        $values = [];
        foreach ($devices as $device) {
            $host = $device['host'];
            $instanceID = $existing[$host] ?? 0;

            $values[] = [
                'Host'         => $host,
                'IP'           => $device['ip'],
                'RSSI'         => $device['rssi'],
                'Name'         => $device['name'],
                'Type'         => $device['type'],
                'SerialNumber' => $device['serial'],
                'Software'     => $device['sw'],
                'Channels'     => $device['channels'],
                'ChannelInfo'  => $device['channelInfo'],
                'instanceID'   => $instanceID,
                'create'       => [
                    [
                        'moduleID'      => self::DEVICE_MODULE_ID,
                        'configuration' => [
                            'Host'         => $host,
                            'DeviceName'   => $device['name'],
                            'DeviceType'   => $device['type'],
                            'SerialNumber' => $device['serial'],
                            'Channels'     => $device['channels'],
                            'Channel1Name'  => $device['channelConfig'][1]['name'] ?? 'Kanal 1',
                            'Channel1Type'  => $device['channelConfig'][1]['type'] ?? 'unused',
                            'Channel2Name'  => $device['channelConfig'][2]['name'] ?? 'Kanal 2',
                            'Channel2Type'  => $device['channelConfig'][2]['type'] ?? 'unused',
                            'Channel3Name'  => $device['channelConfig'][3]['name'] ?? 'Kanal 3',
                            'Channel3Type'  => $device['channelConfig'][3]['type'] ?? 'unused',
                            'Channel4Name'  => $device['channelConfig'][4]['name'] ?? 'Kanal 4',
                            'Channel4Type'  => $device['channelConfig'][4]['type'] ?? 'unused',
                            'ShowOnline' => true,
                            'ShowRSSI' => true,
                            'ShowScenes' => true,
                            'ShowIPAddress' => false,
                            'ShowDeviceTypeInfo' => false,
                            'ShowSerialNumberInfo' => false,
                            'ShowSoftwareInfo' => false,
                            'ShowChannelActualValues' => false
                        ],
                        // Instanzname immer anhand des eindeutigen zapp-Hostnamens setzen.
                        'name' => $host
                    ]
                ]
            ];
        }

        usort($values, static fn(array $a, array $b): int => strnatcasecmp($a['Name'] . $a['Host'], $b['Name'] . $b['Host']));

        return json_encode([
            'actions' => [
                [
                    'type'    => 'Configurator',
                    'name'    => 'Devices',
                    'caption' => 'Gefundene zeptrionAIR WLAN-Module',
                    'rowCount' => 15,
                    'add'     => false,
                    'delete'  => false,
                    'sort'    => [
                        'column'    => 'Name',
                        'direction' => 'ascending'
                    ],
                    'columns' => [
                        ['caption' => 'Name',       'name' => 'Name',        'width' => '70px'],
                        ['caption' => 'Host',       'name' => 'Host',        'width' => '145px'],
                        ['caption' => 'IP-Adresse', 'name' => 'IP',          'width' => '125px'],
                        ['caption' => 'RSSI',       'name' => 'RSSI',        'width' => '80px'],
                        ['caption' => 'Gerätetyp',  'name' => 'Type',        'width' => '140px'],
                        ['caption' => 'Seriennr.',  'name' => 'SerialNumber','width' => '130px'],
                        ['caption' => 'SW',         'name' => 'Software',    'width' => '90px'],
                        ['caption' => 'Kanäle',     'name' => 'Channels',    'width' => '70px'],
                        ['caption' => 'Verbraucher / Kanäle', 'name' => 'ChannelInfo', 'width' => 'auto']
                    ],
                    'values' => $values
                ]
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
        $this->SendDebug('Discovery', 'DNS-SD Control ID: ' . $zcID, 0);

        // API Kap. 4: aktuelle Firmware annonciert _zapp._tcp.
        // Nur wenn dort nichts gefunden wird, suchen wir als Fallback über _http._tcp.
        // Die zeptrionAIR WLAN-Module antworten auf mDNS nicht immer alle im
        // selben Query-Lauf. Mehrere kurze Läufe zusammenführen; vorhandene Hosts
        // werden durch den Hostnamen als Array-Key automatisch dedupliziert.
        $previousCount = -1;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->CollectServices($zcID, '_zapp._tcp', false, $found);
            $count = count($found);
            $this->SendDebug('Discovery', '_zapp._tcp Lauf ' . $attempt . ': insgesamt ' . $count . ' Geräte', 0);

            if ($attempt > 1 && $count === $previousCount) {
                break;
            }
            $previousCount = $count;
            if ($attempt < 3) {
                usleep(250000);
            }
        }

        // Legacy-Ankündigungen immer ergänzend abfragen. Bisher wurde _http._tcp
        // übersprungen, sobald auch nur ein einziges _zapp._tcp Gerät gefunden war.
        // Dadurch konnten einzelne Geräte zeitweise aus der Liste fehlen.
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

        $this->SendDebug('mDNS ' . $type . ' RAW', json_encode($services, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        if (!is_array($services)) {
            $this->SendDebug('mDNS ' . $type, 'Antwort ist kein Array', 0);
            return;
        }

        $this->SendDebug('mDNS ' . $type, 'Gefundene Services: ' . count($services), 0);

        foreach ($services as $service) {
            $this->SendDebug('mDNS ' . $type . ' Service RAW', json_encode($service, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
            $name = (string)($service['Name'] ?? '');
            if ($legacyOnly && !preg_match('/^zapp-\d{8}$/i', $name)) {
                continue;
            }

            // Der mDNS-Service-Name ist zugleich der funktionierende HTTP-Hostname.
            // Eine zusätzliche ZC_QueryService()-Auflösung ist nicht nötig und kostet
            // bei den zeptrionAIR-Modulen etwa eine Sekunde pro Gerät.
            $host = rtrim($name, '.');
            $this->SendDebug('mDNS ' . $name, 'Verwende Host direkt: ' . $host, 0);

            // ZC_QueryServiceType liefert bei diesen Geräten keine TXT-Daten.
            $txt = [];
            $deviceType = '';
            $channels = $this->ChannelsFromType($deviceType);

            $found[$host] = [
                'host'        => $host,
                'ip'          => '',
                'rssi'        => '',
                'name'        => preg_replace('/\.local\.?$/i', '', $name) ?: $name,
                'type'        => $deviceType,
                'serial'      => '',
                'sw'          => (string)($txt['sw'] ?? ''),
                'channels'    => $channels,
                'channelInfo' => '',
                'channelConfig' => []
            ];
        }
    }

    private function EnrichDevicesParallel(array &$devices): void
    {
        if ($devices === []) {
            return;
        }

        $started = microtime(true);
        $requests = [];
        foreach ($devices as $host => $device) {
            $requests[$host . '|id'] = ['host' => $host, 'path' => '/zrap/id'];
            $requests[$host . '|chdes'] = ['host' => $host, 'path' => '/zrap/chdes'];
            $requests[$host . '|rssi'] = ['host' => $host, 'path' => '/zrap/rssi'];
        }

        $responses = $this->HttpXmlGetMulti($requests);

        // Einzelne WLAN-Module reagieren gelegentlich nicht innerhalb des ersten kurzen
        // Parallel-Laufs. Nur die fehlgeschlagenen Requests einmal gemeinsam wiederholen.
        $retry = [];
        foreach ($requests as $key => $request) {
            if (($responses[$key] ?? null) === null) {
                $retry[$key] = $request;
            }
        }
        if ($retry !== []) {
            $this->SendDebug('Discovery', 'Wiederhole ' . count($retry) . ' fehlgeschlagene API-Abfragen einmalig', 0);
            $retryResponses = $this->HttpXmlGetMulti($retry, 2500, 5000);
            foreach ($retryResponses as $key => $response) {
                if ($response !== null) {
                    $responses[$key] = $response;
                }
            }
        }

        foreach ($devices as $host => &$device) {
            $id = $responses[$host . '|id'] ?? null;
            $des = $responses[$host . '|chdes'] ?? null;
            $rssi = $responses[$host . '|rssi'] ?? null;
            $device['ip'] = $this->ResolveIPv4($host);
            $device['rssi'] = $this->ExtractRssi($rssi);
            $this->ApplyApiData($device, $id, $des);
        }
        unset($device);

        $this->SendDebug(
            'Discovery',
            sprintf('API-Daten für %d Geräte parallel geladen in %.2f s', count($devices), microtime(true) - $started),
            0
        );
    }

    private function ResolveIPv4(string $host): string
    {
        $ip = gethostbyname($host);
        if ($ip === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '';
    }

    private function ExtractRssi(?array $data): string
    {
        if ($data === null) {
            return '';
        }
        foreach (['rssi', 'val', 'value'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (string)((int)$data[$key]) . ' dBm';
            }
        }
        foreach ($data as $value) {
            if (is_numeric($value)) {
                return (string)((int)$value) . ' dBm';
            }
            if (is_array($value)) {
                $nested = $this->ExtractRssi($value);
                if ($nested !== '') {
                    return $nested;
                }
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
            if ($running > 0) {
                curl_multi_select($multi, 0.25);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $result = [];
        foreach ($handles as $key => $entry) {
            $curl = $entry['handle'];
            $body = curl_multi_getcontent($curl);
            $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);

            if ($error !== '' || $httpCode < 200 || $httpCode >= 400 || !is_string($body) || trim($body) === '') {
                $this->SendDebug(
                    'HTTP Parallel Fehler',
                    $entry['url'] . ' / HTTP ' . $httpCode . ($error !== '' ? ' / ' . $error : ''),
                    0
                );
                $result[$key] = null;
            } else {
                $result[$key] = $this->ParseXml($body);
            }

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
            if ($sys !== '' && $sys !== 'ZEPTRION') {
                return;
            }

            $device['type'] = (string)($id['type'] ?? $device['type']);
            $device['serial'] = (string)($id['sn'] ?? '');
            $device['sw'] = (string)($id['sw'] ?? $device['sw']);
            $device['name'] = trim((string)($id['oen'] ?? $id['name'] ?? $device['name']));
            $device['channels'] = $this->ChannelsFromType($device['type'], $device['channels']);
        }

        if ($des === null) {
            return;
        }

        $parts = [];
        for ($channel = 1; $channel <= $device['channels']; $channel++) {
            $key = 'ch' . $channel;
            if (!isset($des[$key]) || !is_array($des[$key])) {
                continue;
            }

            $ch = $des[$key];
            $label = trim((string)($ch['name'] ?? ''));
            $type = trim((string)($ch['type'] ?? ''));
            $cat = trim((string)($ch['cat'] ?? ''));

            // Kategorie -1 ist über /zrap/chdes nicht eindeutig. Für den Vergleich
            // zwischen tatsächlich leerem Kanal und Smart-/Szenentaster geben wir
            // deshalb den vollständigen Kanal-Datensatz aus, ohne etwas am Gerät zu ändern.
            if ($cat === '-1') {
                $this->SendDebug(
                    'Kanal -1 RAW',
                    $device['host'] . ' / K' . $channel . ' => ' .
                    json_encode($ch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    0
                );
            }

            $mapped = $this->MapChannelCategory($cat, $label);

            $device['channelConfig'][$channel] = [
                'name' => $label !== '' ? $label : 'Kanal ' . $channel,
                'type' => $mapped['type'],
                'scenes' => $mapped['scenes']
            ];

            $text = 'K' . $channel;
            if ($label !== '') {
                $text .= ': ' . $label;
            }
            if ($type !== '' || $cat !== '') {
                $text .= ' [' . trim($type . '/' . $cat, '/') . ']';
            }
            $text .= ' – ' . $mapped['label'];
            $parts[] = $text;
        }

        $device['channelInfo'] = implode(' | ', $parts);
    }

    private function EnrichFromApi(array &$device): void
    {
        $this->SendDebug('API', 'Prüfe ' . $device['host'] . ' /zrap/id', 0);
        $id = $this->HttpXmlGet($device['host'], '/zrap/id');
        if ($id !== null) {
            $this->SendDebug('API /zrap/id RAW', json_encode($id, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
            $sys = strtoupper((string)($id['sys'] ?? ''));
            if ($sys !== '' && $sys !== 'ZEPTRION') {
                return;
            }

            $device['type'] = (string)($id['type'] ?? $device['type']);
            $device['serial'] = (string)($id['sn'] ?? '');
            $device['sw'] = (string)($id['sw'] ?? $device['sw']);
            $device['name'] = trim((string)($id['oen'] ?? $id['name'] ?? $device['name']));
            $device['channels'] = $this->ChannelsFromType($device['type'], $device['channels']);
        } else {
            $this->SendDebug('API', $device['host'] . ' liefert keine verwertbare Antwort auf /zrap/id', 0);
        }

        $this->SendDebug('API', 'Prüfe ' . $device['host'] . ' /zrap/chdes', 0);
        $des = $this->HttpXmlGet($device['host'], '/zrap/chdes');
        if ($des === null) {
            $this->SendDebug('API', $device['host'] . ' liefert keine verwertbare Antwort auf /zrap/chdes', 0);
            return;
        }

        $this->SendDebug('API /zrap/chdes RAW', json_encode($des, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        $parts = [];
        for ($channel = 1; $channel <= $device['channels']; $channel++) {
            $key = 'ch' . $channel;
            if (!isset($des[$key]) || !is_array($des[$key])) {
                continue;
            }
            $ch = $des[$key];
            $label = trim((string)($ch['name'] ?? ''));
            $type = trim((string)($ch['type'] ?? ''));
            $cat = trim((string)($ch['cat'] ?? ''));
            $mapped = $this->MapChannelCategory($cat, $label);
            $device['channelConfig'][$channel] = [
                'name' => $label !== '' ? $label : 'Kanal ' . $channel,
                'type' => $mapped['type'],
                'scenes' => $mapped['scenes']
            ];

            $text = 'K' . $channel;
            if ($label !== '') {
                $text .= ': ' . $label;
            }
            if ($type !== '' || $cat !== '') {
                $text .= ' [' . trim($type . '/' . $cat, '/') . ']';
            }
            $text .= ' – ' . $mapped['label'];
            $parts[] = $text;
        }
        $device['channelInfo'] = implode(' | ', $parts);
    }

    private function MapChannelCategory(string $cat, string $name): array
    {
        // Zuordnung aus den realen /zrap/chdes Antworten dieser Installation.
        // Unbekannte Kategorien bleiben bewusst "Nicht erkannt".
        return match ($cat) {
            '1' => ['type' => 'light',   'label' => 'Licht / Schalter', 'scenes' => false],
            '3' => ['type' => 'dimmer',  'label' => 'Dimmer / DALI',    'scenes' => false],
            '5' => ['type' => 'shutter', 'label' => 'Store / Rollo',    'scenes' => false],
            '6' => ['type' => 'shutter', 'label' => 'Markise',          'scenes' => false],
            default => $this->MapChannelByName($name)
        };
    }

    private function MapChannelByName(string $name): array
    {
        // /zrap/chdes kennzeichnet Smart-Taster und leere Kanäle nicht eindeutig.
        // Deshalb werden alle Kanäle ohne erkannte Verbraucher-Kategorie neutral
        // als "Leer / Smart-Taster" angezeigt – unabhängig vom vergebenen Namen.
        return ['type' => 'unused', 'label' => 'Leer / Smart-Taster', 'scenes' => false];
    }

    private function HttpXmlGet(string $host, string $path): ?array
    {
        $url = 'http://' . $host . $path;
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 2.0,
                'ignore_errors' => true,
                'header'        => "Connection: close\r\n"
            ]
        ]);

        $this->SendDebug('HTTP GET', $url, 0);
        $xml = @file_get_contents($url, false, $context);
        if (!is_string($xml) || trim($xml) === '') {
            $error = error_get_last();
            $this->SendDebug('HTTP GET Fehler', $url . ' / ' . ($error['message'] ?? 'keine Antwort'), 0);
            return null;
        }

        $this->SendDebug('HTTP GET RAW', $url . ' => ' . $xml, 0);

        return $this->ParseXml($xml);
    }

    private function ParseXml(string $xml): ?array
    {
        libxml_use_internal_errors(true);
        $node = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($node === false) {
            libxml_clear_errors();
            return null;
        }

        $json = json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $data = json_decode((string)$json, true);
        return is_array($data) ? $data : null;
    }

    private function ExtractIPv4(array $detail): string
    {
        foreach (['IPv4', 'Address', 'IP', 'Host'] as $key) {
            $value = $detail[$key] ?? null;
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $value;
            }
            if (is_array($value)) {
                foreach ($value as $entry) {
                    if (is_string($entry) && filter_var($entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        return $entry;
                    }
                }
            }
        }

        foreach ($detail as $value) {
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $value;
            }
        }
        return '';
    }

    private function NormalizeTxt(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && !is_int($key)) {
                $result[strtolower($key)] = is_scalar($value) ? (string)$value : '';
                continue;
            }
            if (is_string($value) && str_contains($value, '=')) {
                [$k, $v] = explode('=', $value, 2);
                $result[strtolower(trim($k))] = trim($v);
            }
        }
        return $result;
    }

    private function ChannelsFromType(string $type, int $fallback = 2): int
    {
        if (preg_match('/^3340-(\d)-/i', $type, $m)) {
            return max(1, min(4, (int)$m[1]));
        }
        return $fallback;
    }

    private function GetExistingInstances(): array
    {
        $result = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_ID) as $instanceID) {
            $host = trim((string)IPS_GetProperty($instanceID, 'Host'));
            if ($host !== '') {
                $result[$host] = $instanceID;
            }
        }
        return $result;
    }
}
