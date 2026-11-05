<?php
require_once __DIR__ . '/AcsClient.php';

/** Site-local configuration, safe cached readings and auditable ACS requests. */
class AcsIntegration
{
    private PDO $db;
    private bool $connectionLocked = false;
    public function __construct(private OltManager $mgr) { $this->db = $mgr->db(); }

    /** Keep the endpoint stable throughout reads and queued changes, including concurrent settings saves. */
    private function connectionLock(callable $work): mixed
    {
        if ($this->connectionLocked) return $work();
        $name = 'acs_connection_' . substr(hash('sha256', (string)$this->db->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
        $st = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $st->execute([$name]);
        if (!$st->fetchColumn()) throw new RuntimeException('Another ACS request is running. Try again shortly.');
        $this->connectionLocked = true;
        try { return $work(); }
        finally { $this->connectionLocked = false; $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }
    }

    private function rawSettings(): array
    {
        return $this->db->query('SELECT * FROM acs_settings WHERE id=1')->fetch() ?: [];
    }

    public function settings(): array
    {
        $s = $this->rawSettings();
        return ['enabled' => !empty($s['enabled']), 'base_url' => $s['base_url'] ?? '', 'has_api_key' => !empty($s['api_key_enc']),
            'status' => $s['status'] ?? 'unconfigured', 'checked_at' => $s['checked_at'] ?? null, 'last_error' => $s['last_error'] ?? ''];
    }

    public function saveSettings(string $url, string $apiKey, bool $enabled): void
    {
        $this->connectionLock(fn() => $this->persistSettings($url, $apiKey, $enabled));
    }

    private function persistSettings(string $url, string $apiKey, bool $enabled): void
    {
        $old = $this->rawSettings();
        $url = AcsClient::baseUrl($url);
        $changed = ($old['base_url'] ?? '') !== $url;
        if ($apiKey === '' && $changed && !empty($old['api_key_enc'])) throw new InvalidArgumentException('Enter the API key for the new ACS address.');
        $key = $apiKey !== '' ? $apiKey : (!empty($old['api_key_enc']) ? $this->mgr->decrypt($old['api_key_enc']) : '');
        new AcsClient($url, $key); // Validate even when saved disabled; no connection is made.
        $this->db->beginTransaction();
        try {
            $this->db->prepare("INSERT INTO acs_settings(id,base_url,api_key_enc,enabled,status) VALUES(1,?,?,?,'unchecked')
                ON DUPLICATE KEY UPDATE base_url=VALUES(base_url),api_key_enc=VALUES(api_key_enc),enabled=VALUES(enabled),status='unchecked',checked_at=NULL,last_error=''")
                ->execute([$url, $this->mgr->encrypt($key), (int)$enabled]);
            if ($changed) {
                $this->db->exec('DELETE FROM acs_devices');
                $this->db->prepare("UPDATE acs_jobs SET status='unknown',last_error='ACS address changed. Check the original ACS for this request.',updated_at=? WHERE status IN ('preparing','queued','running')")->execute([OltManager::now()]);
            }
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
        $this->mgr->log(null, 'acs.settings', 'ACS connection', true, 'Connection settings saved');
    }

    private function client(): AcsClient
    {
        $s = $this->rawSettings();
        if (empty($s['enabled']) || empty($s['api_key_enc'])) throw new RuntimeException('Enable and configure the ACS connection first.');
        try { $key = $this->mgr->decrypt($s['api_key_enc']); }
        catch (Throwable $e) { throw new RuntimeException('The saved ACS key cannot be read. Enter it again in ACS settings.'); }
        return new AcsClient($s['base_url'], $key);
    }

    public function checkConnection(): void
    {
        $this->connectionLock(fn() => $this->performConnectionCheck());
    }

    private function performConnectionCheck(): void
    {
        try {
            $this->client()->checkConnection();
            $this->db->prepare("UPDATE acs_settings SET status='connected',checked_at=?,last_error='' WHERE id=1")->execute([OltManager::now()]);
        } catch (Throwable $e) {
            $this->db->prepare("UPDATE acs_settings SET status='error',checked_at=?,last_error=? WHERE id=1")->execute([OltManager::now(), $e->getMessage()]);
            throw $e;
        }
    }

    private static function cache(array $row): array
    {
        $row['snapshot'] = json_decode((string)($row['snapshot_json'] ?? ''), true) ?: [];
        unset($row['snapshot_json']);
        return $row;
    }

    public function device(int $onuId): ?array
    {
        $st = $this->db->prepare('SELECT a.* FROM acs_devices a JOIN onus u ON u.id=a.onu_id WHERE a.onu_id=?');
        $st->execute([$onuId]);
        $row = $st->fetch();
        if (!$row) return null;
        $onu = $this->mgr->onu($onuId);
        if (AcsClient::serialKey($row['serial']) !== AcsClient::serialKey($onu['sn'])) return null;
        return self::cache($row);
    }

    public function report(): array
    {
        $rows = $this->db->query('SELECT a.*,u.sn,u.description AS name,u.olt_id,z.name AS zone_name,o.name AS olt_name
            FROM acs_devices a JOIN onus u ON u.id=a.onu_id JOIN olts o ON o.id=u.olt_id
            LEFT JOIN zones z ON z.id=u.zone_id ORDER BY u.description,u.id')->fetchAll();
        return array_map([self::class, 'cache'], array_values(array_filter($rows,
            static fn(array $r): bool => AcsClient::serialKey($r['serial']) === AcsClient::serialKey($r['sn']))));
    }

    public function sync(int $onuId): array
    {
        return $this->connectionLock(fn() => $this->performSync($onuId));
    }

    private function performSync(int $onuId): array
    {
        $onu = $this->mgr->onu($onuId);
        if (!$onu) throw new RuntimeException('ONU not found.');
        try {
            $this->checkConnection();
            $found = $this->client()->device($onu['sn']);
            $this->db->prepare('INSERT INTO acs_devices(onu_id,acs_device_id,serial,snapshot_json,checked_at,last_error) VALUES(?,?,?,?,?,\'\')
                ON DUPLICATE KEY UPDATE acs_device_id=VALUES(acs_device_id),serial=VALUES(serial),snapshot_json=VALUES(snapshot_json),checked_at=VALUES(checked_at),last_error=\'\'')
                ->execute([$onuId, $found['id'], $found['snapshot']['serial'], json_encode($found['snapshot'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), OltManager::now()]);
            return $this->device($onuId);
        } catch (Throwable $e) {
            $this->db->prepare('UPDATE acs_devices SET last_error=? WHERE onu_id=?')->execute([$e->getMessage(), $onuId]);
            throw $e;
        }
    }

    public function jobs(int $onuId): array
    {
        $st = $this->db->prepare('SELECT id,onu_id,acs_device_id,external_job_id,operation,status,created_at,updated_at,last_error FROM acs_jobs WHERE onu_id=? ORDER BY id DESC LIMIT 50');
        $st->execute([$onuId]);
        return $st->fetchAll();
    }

    /** A unique request is recorded before a remote mutation; uncertain delivery is never retried automatically. */
    public function queue(int $onuId, string $operation, string $requestId, string $ssid = '', string $password = ''): array
    {
        return $this->connectionLock(fn() => $this->performQueue($onuId, $operation, $requestId, $ssid, $password));
    }

    private function performQueue(int $onuId, string $operation, string $requestId, string $ssid, string $password): array
    {
        $onu = $this->mgr->onu($onuId);
        if (!$onu) throw new RuntimeException('ONU not found.');
        return $this->mgr->withOltLock((int)$onu['olt_id'], function () use ($onu, $onuId, $operation, $requestId, $ssid, $password): array {
            $current = $this->mgr->onu($onuId);
            if (!$current || (int)$current['olt_id'] !== (int)$onu['olt_id']) throw new RuntimeException('The ONU changed while this request was waiting. Read it again.');
            return $this->queueWithStableOnu($onuId, $operation, $requestId, $ssid, $password);
        });
    }

    private function queueWithStableOnu(int $onuId, string $operation, string $requestId, string $ssid, string $password): array
    {
        if (!in_array($operation, ['refresh', 'reboot', 'wifi'], true) || !preg_match('/^[a-f0-9]{32}$/D', $requestId)) throw new InvalidArgumentException('Invalid ACS request. Reload the page and try again.');
        if ($operation === 'wifi') AcsClient::validateWifi($ssid, $password);
        $lock = 'acs_' . substr(hash('sha256', $this->db->query('SELECT DATABASE()')->fetchColumn() . ':' . $onuId), 0, 40);
        $st = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $st->execute([$lock]);
        if (!$st->fetchColumn()) throw new RuntimeException('Another ACS request is running for this ONU. Try again shortly.');
        try {
            $st = $this->db->prepare('SELECT * FROM acs_jobs WHERE request_id=?');
            $st->execute([$requestId]);
            if ($existing = $st->fetch()) {
                if ((int)$existing['onu_id'] !== $onuId || $existing['operation'] !== $operation) throw new RuntimeException('This request belongs to another operation.');
                return ['id' => (int)$existing['id'], 'status' => $existing['status'], 'repeated' => true];
            }
            $cache = $this->sync($onuId); // Recheck serial identity before every mutation.
            $this->db->prepare("INSERT INTO acs_jobs(onu_id,acs_device_id,request_id,operation,status,created_at,updated_at) VALUES(?,?,?,?,'preparing',?,?)")
                ->execute([$onuId, $cache['acs_device_id'], $requestId, $operation, OltManager::now(), OltManager::now()]);
            $id = (int)$this->db->lastInsertId();
            $accepted = false;
            try {
                $client = $this->client();
                $r = $operation === 'wifi' ? $client->wifi((int)$cache['acs_device_id'], $ssid, $password) : $client->action((int)$cache['acs_device_id'], $operation, $requestId);
                $accepted = true;
                $this->db->prepare("UPDATE acs_jobs SET status='queued',external_job_id=?,task_ids_json=?,updated_at=? WHERE id=?")
                    ->execute([$r['job_id'], json_encode($r['task_ids']), OltManager::now(), $id]);
                $onu = $this->mgr->onu($onuId);
                $this->mgr->log((int)$onu['olt_id'], 'acs.' . $operation, $onu['sn'], true, 'Queued ACS request #' . $id . '; awaiting device confirmation');
                return ['id' => $id, 'status' => 'queued', 'repeated' => false];
            } catch (Throwable $e) {
                $status = $accepted || ($e instanceof AcsRequestException && $e->uncertain) ? 'unknown' : 'failed';
                $error = $status === 'unknown' ? 'Delivery could not be confirmed. Check the ACS before submitting another change.' : $e->getMessage();
                $this->db->prepare('UPDATE acs_jobs SET status=?,last_error=?,updated_at=? WHERE id=?')->execute([$status, $error, OltManager::now(), $id]);
                throw new RuntimeException($error);
            }
        } finally { $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
    }

    public function refreshJobs(int $onuId): void
    {
        $this->connectionLock(fn() => $this->performRefreshJobs($onuId));
    }

    private function performRefreshJobs(int $onuId): void
    {
        $this->checkConnection();
        $st = $this->db->prepare("SELECT * FROM acs_jobs WHERE onu_id=? AND status IN ('queued','running') ORDER BY id DESC LIMIT 50");
        $st->execute([$onuId]);
        $jobs = $st->fetchAll();
        // Group tasks into one request per remote device, avoiding a network request for every historical job.
        $client = $this->client();
        foreach ($jobs as $job) {
            try {
                $state = $client->taskState((int)$job['acs_device_id'], json_decode($job['task_ids_json'], true) ?: []);
                $error = $state === 'unknown' ? 'The ACS no longer reports all expected tasks. Check the ACS.' : ($state === 'failed' ? 'At least one ACS task failed or was canceled. Check the ACS task details.' : '');
                $this->db->prepare('UPDATE acs_jobs SET status=?,last_error=?,updated_at=? WHERE id=?')->execute([$state, $error, OltManager::now(), $job['id']]);
            } catch (Throwable $e) {
                // Keep the last confirmed state on a read error and expose its age and error.
                $this->db->prepare('UPDATE acs_jobs SET last_error=? WHERE id=?')->execute([$e->getMessage(), $job['id']]);
                throw $e;
            }
        }
    }
}
