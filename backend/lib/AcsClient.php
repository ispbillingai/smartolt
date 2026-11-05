<?php
/** REST bridge to the FreeISP ACS. Never returns device credentials to callers. */
class AcsRequestException extends RuntimeException
{
    public function __construct(string $message, public bool $uncertain = false) { parent::__construct($message); }
}

class AcsClient
{
    private string $base;
    private array $taskCache = [];
    public function __construct(string $base, private string $key)
    {
        $this->base = self::baseUrl($base);
        if ($key === '' || strlen($key) > 1024 || preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new InvalidArgumentException('Enter a valid ACS API key.');
        }
    }

    public static function baseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $p = parse_url($url);
        if (!$p || strlen($url) > 255 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])
            || empty($p['host']) || !in_array($p['scheme'] ?? '', ['https', 'http'], true)) {
            throw new InvalidArgumentException('Enter the ACS website URL using HTTPS.');
        }
        // HTTP is permitted only for a literal loopback test server, never arbitrary DNS.
        if ($p['scheme'] === 'http' && !in_array($p['host'], ['127.0.0.1', '[::1]'], true)) {
            throw new InvalidArgumentException('The ACS connection requires HTTPS (local loopback tests may use HTTP).');
        }
        if (preg_match('~(?:^|/)\.\.(?:/|$)~', rawurldecode($p['path'] ?? ''))) {
            throw new InvalidArgumentException('The ACS URL cannot contain parent paths.');
        }
        return $url;
    }

    private function request(string $endpoint, array $query = [], ?array $body = null, bool $notFound = false): ?array
    {
        $url = $this->base . '/backend/api/rest/' . $endpoint . ($query ? '?' . http_build_query($query) : '');
        $ch = curl_init($url);
        $response = '';
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROXY => '',
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $this->key],
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 2097152) return 0;
                $response .= $chunk;
                return strlen($chunk);
            }]);
        if ($body !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR)]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $mutating = $body !== null && ($body['action'] ?? '') !== 'preview';
        if ($ok === false) throw new AcsRequestException('The ACS did not return a complete response. Check its connection and certificate.', $mutating);
        if ($code === 404 && $notFound) return null;
        if (in_array($code, [401, 403], true)) throw new AcsRequestException('The ACS rejected the API key.');
        if ($code < 200 || $code > 299) throw new AcsRequestException('The ACS request failed (HTTP ' . $code . ').', $mutating && $code >= 500);
        $data = json_decode($response, true);
        if (!is_array($data) || ($data['success'] ?? false) !== true) {
            throw new AcsRequestException('The ACS returned an invalid response.', $mutating);
        }
        return $data;
    }

    public function checkConnection(): void
    {
        $this->request('provision.php', ['action' => 'capabilities']);
    }

    public static function serialKey(string $serial): string
    {
        $serial = strtoupper(trim($serial));
        if (preg_match('/^([A-Z0-9]{4})-?([0-9A-F]{8})$/D', $serial, $m)) return strtoupper(bin2hex($m[1])) . $m[2];
        return $serial;
    }

    private static function serials(string $serial): array
    {
        $raw = strtoupper(trim($serial));
        $key = self::serialKey($serial);
        if ($raw === '' || strlen($raw) > 64 || !preg_match('/^[A-Z0-9-]+$/D', $raw)) throw new InvalidArgumentException('This ONU has no valid serial number.');
        $variants = [$raw, $key];
        if (preg_match('/^[0-9A-F]{16}$/D', $key)) {
            $vendor = hex2bin(substr($key, 0, 8));
            if (preg_match('/^[A-Z0-9]{4}$/D', $vendor)) {
                $variants[] = $vendor . substr($key, 8);
                $variants[] = $vendor . '-' . substr($key, 8);
            }
        }
        return array_values(array_unique($variants));
    }

    /** Check all exact representations; refuse ambiguous or mismatched devices. */
    public function device(string $serial): array
    {
        $found = [];
        foreach (self::serials($serial) as $variant) {
            $response = $this->request('devices.php', ['serial' => $variant], null, true);
            if ($response === null) continue;
            $d = $response['device'] ?? [];
            if (!is_array($d) || !is_scalar($d['id'] ?? null) || (int)$d['id'] < 1 || !is_string($d['serialNumber'] ?? null) || self::serialKey($d['serialNumber']) !== self::serialKey($serial)) {
                throw new RuntimeException('The ACS returned a different serial number. No device was linked.');
            }
            $found[(int)$d['id']] = ['id' => (int)$d['id'], 'snapshot' => self::safeSnapshot($d)];
        }
        if (!$found) throw new RuntimeException('This serial number is not registered in the ACS.');
        if (count($found) !== 1) throw new RuntimeException('More than one ACS device matches this serial. Resolve the duplicate in the ACS first.');
        return array_values($found)[0];
    }

    private static function safeText(mixed $value, int $limit = 255): string
    {
        if (!is_scalar($value)) return '';
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', (string)$value) ?? '', 0, $limit);
    }

    public static function safeSnapshot(array $d): array
    {
        $safe = [];
        foreach (['serial' => 'serialNumber', 'manufacturer' => 'manufacturer', 'model' => 'model', 'status' => 'status',
            'last_contact' => 'lastContact', 'ip' => 'ipAddress', 'software' => 'softwareVersion', 'hardware' => 'hardwareVersion', 'ssid' => 'ssid'] as $out => $in) {
            $safe[$out] = self::safeText($d[$in] ?? '');
        }
        $safe['clients'] = is_numeric($d['connectedClients'] ?? null) ? max(0, (int)$d['connectedClients']) : null;
        $safe['uptime'] = is_numeric($d['uptime'] ?? null) ? max(0, (int)$d['uptime']) : null;
        $safe['wifi'] = $safe['lan'] = $safe['hosts'] = [];
        foreach (array_slice(is_array($d['parameters'] ?? null) ? $d['parameters'] : [], 0, 10000) as $p) {
            if (!is_array($p)) continue;
            if (!is_string($p['name'] ?? null)) continue;
            $name = (string)($p['name'] ?? '');
            $type = null;
            if (preg_match('/^(?:InternetGatewayDevice\.LANDevice\.\d+\.WLANConfiguration\.\d+\.|Device\.WiFi\.(?:Radio|SSID|AccessPoint)\.\d+\.)(?:SSID|Enable|Status|Channel|TotalAssociations|AutoChannelEnable|OperatingFrequencyBand|AssociatedDevice\.\d+\.(?:SignalStrength|RSSI|AssociatedDeviceMACAddress|MACAddress))$/D', $name)) $type = 'wifi';
            if (preg_match('/^(?:InternetGatewayDevice\.LANDevice\.\d+\.LANEthernetInterfaceConfig\.\d+\.|Device\.Ethernet\.Interface\.\d+\.)(?:Enable|Status|MaxBitRate|DuplexMode|MACAddress)$/D', $name)) $type = 'lan';
            if ($type !== null && count($safe[$type]) < 256) $safe[$type][] = ['name' => $name, 'value' => self::safeText($p['value'] ?? ''), 'updated_at' => self::safeText($p['updatedAt'] ?? '')];
        }
        foreach (array_slice(is_array($d['connectedHosts'] ?? null) ? $d['connectedHosts'] : [], 0, 256) as $h) {
            if (!is_array($h)) continue;
            $safe['hosts'][] = ['ip' => self::safeText($h['ipAddress'] ?? ''), 'mac' => self::safeText($h['macAddress'] ?? ''),
                'hostname' => self::safeText($h['hostname'] ?? ''), 'last_seen' => self::safeText($h['lastSeen'] ?? ''), 'active' => !empty($h['isActive'])];
        }
        return $safe;
    }

    private static function taskIds(array $response): array
    {
        $ids = $response['task_ids'] ?? [];
        if (!is_array($ids) || !$ids || count($ids) > 64) throw new AcsRequestException('The ACS did not confirm its queued task IDs. Check the ACS before repeating.', true);
        foreach ($ids as $id) if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id) || (int)$id < 1) throw new AcsRequestException('The ACS returned an invalid task ID. Check the ACS before repeating.', true);
        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function action(int $deviceId, string $operation, string $requestId): array
    {
        if (!in_array($operation, ['refresh', 'reboot'], true) || !preg_match('/^[a-f0-9]{32}$/D', $requestId)) throw new InvalidArgumentException('Invalid ACS action.');
        $cap = $this->request('parameters.php', ['action' => 'device_action_capabilities', 'device_id' => $deviceId]);
        if (empty($cap['capabilities'][$operation])) throw new RuntimeException('This ACS does not support the requested device action. Update the ACS first.');
        $r = $this->request('parameters.php', [], ['action' => 'device_action', 'device_id' => $deviceId, 'operation' => $operation, 'request_id' => $requestId]);
        return ['task_ids' => self::taskIds($r), 'job_id' => null];
    }

    public static function validateWifi(string $ssid, string $password): void
    {
        if ($ssid === '' || strlen($ssid) > 32 || preg_match('/[\x00-\x1f\x7f]/', $ssid)) throw new InvalidArgumentException('WiFi name must be 1 to 32 bytes without control characters.');
        if (!preg_match('/^[\x20-\x7e]{8,63}$/D', $password) && !preg_match('/^[a-fA-F0-9]{64}$/D', $password)) {
            throw new InvalidArgumentException('WiFi password must contain 8 to 63 printable ASCII characters or 64 hexadecimal digits.');
        }
    }

    public function wifi(int $deviceId, string $ssid, string $password): array
    {
        self::validateWifi($ssid, $password);
        $body = ['device_id' => $deviceId, 'method' => 'api', 'config' => ['wifi' => ['ssid' => $ssid, 'password' => $password]]];
        $preview = $this->request('provision.php', [], ['action' => 'preview'] + $body);
        if (!is_array($preview['tasks'] ?? null) || count($preview['tasks']) !== 1 || !is_array($preview['tasks'][0] ?? null) || ($preview['tasks'][0]['task_type'] ?? '') !== 'wifi') {
            throw new RuntimeException('The ACS preview did not produce a single WiFi change. Nothing was queued.');
        }
        $r = $this->request('provision.php', [], ['action' => 'provision'] + $body);
        return ['task_ids' => self::taskIds($r), 'job_id' => (int)($r['job_id'] ?? 0) ?: null];
    }

    /** Read task state only; task_data and messages may contain passwords and are discarded. */
    public function taskState(int $deviceId, array $ids): string
    {
        if (!$ids) return 'unknown';
        $r = $this->taskCache[$deviceId] ??= $this->request('tasks.php', ['device_id' => $deviceId]);
        if (!is_array($r['tasks'] ?? null)) throw new RuntimeException('The ACS returned an invalid task list.');
        $states = [];
        foreach ($r['tasks'] as $task) {
            if (!is_array($task) || !is_scalar($task['id'] ?? null) || !is_scalar($task['device_id'] ?? null) || !is_string($task['status'] ?? null)) throw new RuntimeException('The ACS returned an invalid task state.');
            if (!in_array((int)($task['id'] ?? 0), $ids, true)) continue;
            if ((int)($task['device_id'] ?? 0) !== $deviceId) throw new RuntimeException('The ACS task belongs to a different device.');
            $states[(int)$task['id']] = strtolower((string)($task['status'] ?? 'unknown'));
        }
        if (count($states) !== count($ids)) return 'unknown';
        if (array_intersect($states, ['failed', 'canceled', 'cancelled'])) return 'failed';
        if (!array_diff($states, ['completed', 'success', 'succeeded'])) return 'completed';
        if (array_intersect($states, ['processing', 'running', 'in_progress', 'sent'])) return 'running';
        return array_diff($states, ['pending', 'queued']) ? 'unknown' : 'queued';
    }
}
