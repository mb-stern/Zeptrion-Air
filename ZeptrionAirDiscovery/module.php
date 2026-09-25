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
                            'Channel1Scenes'=> $device['channelConfig'][1]['scenes'] ?? false,
                            'Channel2Name'  => $device['channelConfig'][2]['name'] ?? 'Kanal 2',
                            'Channel2Type'  => $device['channelConfig'][2]['type'] ?? 'unused',
                            'Channel2Scenes'=> $device['channelConfig'][2]['scenes'] ?? false
                        ],
                        'name' => $device['name'] !== '' ? $device['name'] : 'zeptrionAIR ' . $host
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
                        ['caption' => 'Name',       'name' => 'Name',        'width' => '220px'],
                        ['caption' => 'IP / Host',  'name' => 'Host',        'width' => '160px'],
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
        $this->CollectServices($zcID, '_zapp._tcp', false, $found);

        if ($found === []) {
            $this->SendDebug('Discovery', 'Keine _zapp._tcp Geräte gefunden, verwende Legacy-Fallback _http._tcp', 0);
            $this->CollectServices($zcID, '_http._tcp', true, $found);
        } else {
            $this->SendDebug('Discovery', '_zapp._tcp erfolgreich - Legacy-Suche wird übersprungen', 0);
        }

        foreach ($found as &$device) {
            $this->EnrichFromApi($device);
        }
        unset($device);

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

            $txt = $this->NormalizeTxt($detail['TXT'] ?? $detail['Text'] ?? $detail['TXTRecords'] ?? []);
            $deviceType = (string)($txt['type'] ?? '');
            $channels = $this->ChannelsFromType($deviceType);

            $found[$host] = [
                'host'        => $host,
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
        $name = strtolower(trim($name));
        if ($name === '' || str_contains($name, 'nicht belegt')) {
            return ['type' => 'unused', 'label' => 'Nicht belegt', 'scenes' => false];
        }
        if (str_contains($name, 'szene') || str_contains($name, 'scene')) {
            return ['type' => 'unused', 'label' => 'Szenentaster', 'scenes' => true];
        }
        return ['type' => 'unused', 'label' => 'Nicht erkannt', 'scenes' => false];
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
