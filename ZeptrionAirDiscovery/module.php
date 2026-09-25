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
                            'Channels'     => $device['channels']
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

        // API Kap. 4: aktuelle Firmware annonciert _zapp._tcp.
        $this->CollectServices($zcID, '_zapp._tcp', false, $found);

        // Ältere Firmware: _http._tcp, gefiltert über den zapp-Hostnamen.
        $this->CollectServices($zcID, '_http._tcp', true, $found);

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

        if (!is_array($services)) {
            return;
        }

        foreach ($services as $service) {
            $name = (string)($service['Name'] ?? '');
            if ($legacyOnly && !preg_match('/^zapp-\d{8}$/i', $name)) {
                continue;
            }

            try {
                $detail = ZC_QueryService(
                    $zcID,
                    $name,
                    (string)($service['Type'] ?? $type),
                    (string)($service['Domain'] ?? 'local.')
                );
            } catch (Throwable $e) {
                $this->SendDebug('mDNS resolve ' . $name, $e->getMessage(), 0);
                continue;
            }

            $host = $this->ExtractIPv4($detail);
            if ($host === '') {
                $host = rtrim((string)($detail['Host'] ?? $detail['Hostname'] ?? ''), '.');
            }
            if ($host === '') {
                continue;
            }

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
                'channelInfo' => ''
            ];
        }
    }

    private function EnrichFromApi(array &$device): void
    {
        $id = $this->HttpXmlGet($device['host'], '/zrap/id');
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

        $des = $this->HttpXmlGet($device['host'], '/zrap/chdes');
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
            $text = 'K' . $channel;
            if ($label !== '') {
                $text .= ': ' . $label;
            }
            if ($type !== '' || $cat !== '') {
                $text .= ' [' . trim($type . '/' . $cat, '/') . ']';
            }
            $parts[] = $text;
        }
        $device['channelInfo'] = implode(' | ', $parts);
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

        $xml = @file_get_contents($url, false, $context);
        if (!is_string($xml) || trim($xml) === '') {
            return null;
        }

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
