<?php
// Everything the pages and the API do with an OLT: stored passwords, the per OLT lock, the action
// log and the full read that brings the database copy up to date.

require_once __DIR__ . '/Huawei.php';
require_once __DIR__ . '/../config/database.php';

class OltManager
{
    private PDO $db;
    private Database $config;
    private ?string $lastTranscript = null;
    public string $source = 'panel';

    public function __construct()
    {
        // One clock for every stored time, whether a page, the API or the cron wrote it.
        date_default_timezone_set('Africa/Nairobi');
        $this->config = new Database();
        $this->db = $this->config->getConnection();
    }

    public function db(): PDO
    {
        return $this->db;
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    // ----- Stored OLT passwords (AES-256-GCM) -----

    public function encrypt(string $plain): string
    {
        $key = base64_decode($this->config->getSecretKey(), true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $stored): string
    {
        $key = base64_decode($this->config->getSecretKey(), true);
        $raw = base64_decode($stored, true);
        $plain = $raw === false || strlen($raw) < 28 ? false
            : openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('The saved OLT password could not be read. Edit the OLT and enter its password again.');
        }
        return $plain;
    }

    // ----- Rows -----

    public function olts(): array
    {
        return $this->db->query("SELECT o.*, (SELECT COUNT(*) FROM onus u WHERE u.olt_id=o.id) AS onu_count,
            (SELECT COUNT(*) FROM onus u WHERE u.olt_id=o.id AND u.run_state='online') AS onu_online
            FROM olts o ORDER BY o.name")->fetchAll();
    }

    public function olt(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM olts WHERE id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function onu(int $id): ?array
    {
        $st = $this->db->prepare('SELECT u.*, o.name AS olt_name FROM onus u JOIN olts o ON o.id=u.olt_id WHERE u.id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** An OLT row without its password, with boards and profiles decoded. */
    public static function publicOlt(array $o): array
    {
        unset($o['password_enc']);
        $o['boards'] = json_decode((string)$o['boards_json'], true) ?: [];
        $o['profiles'] = json_decode((string)$o['profiles_json'], true) ?: ['line' => [], 'srv' => [], 'vlans' => []];
        unset($o['boards_json'], $o['profiles_json']);
        return $o;
    }

    public function listOnus(array $f): array
    {
        $where = ['1=1'];
        $args = [];
        if (!empty($f['olt_id'])) { $where[] = 'u.olt_id=?'; $args[] = (int)$f['olt_id']; }
        if (($f['status'] ?? '') === 'online') { $where[] = "u.run_state='online'"; }
        if (($f['status'] ?? '') === 'offline') { $where[] = "u.run_state<>'online'"; }
        if (($f['status'] ?? '') === 'weak') { $where[] = 'u.rx_power IS NOT NULL AND u.rx_power < -27'; }
        if (($q = trim((string)($f['q'] ?? ''))) !== '') {
            $where[] = '(u.sn LIKE ? OR u.description LIKE ? OR u.customer_ref LIKE ?)';
            array_push($args, "%{$q}%", "%{$q}%", "%{$q}%");
        }
        $st = $this->db->prepare('SELECT u.*, o.name AS olt_name FROM onus u JOIN olts o ON o.id=u.olt_id WHERE ' . implode(' AND ', $where)
            . ' ORDER BY o.name, u.frame, u.slot, u.port, u.ont_id LIMIT 3000');
        $st->execute($args);
        return $st->fetchAll();
    }

    // ----- Saving an OLT -----

    /** Validate and save. Returns the OLT id or throws InvalidArgumentException with a message for the user. */
    public function saveOlt(array $in): int
    {
        $name = trim((string)($in['name'] ?? ''));
        $host = trim((string)($in['host'] ?? ''));
        $port = (int)($in['telnet_port'] ?? 23);
        $user = trim((string)($in['username'] ?? ''));
        $pass = (string)($in['password'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) throw new InvalidArgumentException('Give the OLT a name.');
        if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^(?=.{1,190}$)([a-z0-9-]+\.)+[a-z]{2,}$/i', $host)) throw new InvalidArgumentException('Enter the OLT address as an IP or a host name.');
        if ($port < 1 || $port > 65535) throw new InvalidArgumentException('The Telnet port must be between 1 and 65535.');
        if ($user === '') throw new InvalidArgumentException('Enter the OLT username.');
        $now = self::now();
        if (!empty($in['id'])) {
            $olt = $this->olt((int)$in['id']);
            if (!$olt) throw new InvalidArgumentException('OLT not found.');
            $enc = $pass !== '' ? $this->encrypt($pass) : $olt['password_enc'];
            $this->db->prepare('UPDATE olts SET name=?, host=?, telnet_port=?, username=?, password_enc=?, updated_at=? WHERE id=?')
                ->execute([$name, $host, $port, $user, $enc, $now, $olt['id']]);
            return (int)$olt['id'];
        }
        if ($pass === '') throw new InvalidArgumentException('Enter the OLT password.');
        $this->db->prepare('INSERT INTO olts (name, host, telnet_port, username, password_enc, created_at, updated_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$name, $host, $port, $user, $this->encrypt($pass), $now, $now]);
        return (int)$this->db->lastInsertId();
    }

    public function deleteOlt(int $id): void
    {
        $olt = $this->olt($id);
        if (!$olt) throw new InvalidArgumentException('OLT not found.');
        $this->db->prepare('DELETE FROM olts WHERE id=?')->execute([$id]);
        $this->log(null, 'olt.delete', $olt['name'], true, 'removed from SmartOLT, nothing changed on the OLT');
    }

    // ----- Talking to the OLT -----

    /**
     * Run $fn with a logged in session while holding that OLT's lock, so the 5 minute read and a
     * person's action never talk to the same OLT at once (Huawei allows only a few Telnet users).
     */
    public function withOlt(array $olt, callable $fn, int $wait = 30)
    {
        $name = $this->db->quote('smartolt_' . $this->config->getSubdomain() . '_' . (int)$olt['id']);
        if ((int)$this->db->query("SELECT GET_LOCK({$name}, " . (int)$wait . ')')->fetchColumn() !== 1) {
            throw new OltError('The OLT is busy with another task. Try again in a moment.');
        }
        $s = null;
        try {
            $s = Huawei::connect($olt['host'], (int)$olt['telnet_port'], $olt['username'], $this->decrypt($olt['password_enc']));
            return $fn($s);
        } finally {
            if ($s) {
                $this->lastTranscript = $s->transcript();
                $s->close();
            }
            $this->db->query("SELECT RELEASE_LOCK({$name})");
        }
    }

    /** withOlt() plus an entry in the activity log either way. Rethrows on failure. */
    public function act(array $olt, string $action, string $target, callable $fn)
    {
        try {
            $result = $this->withOlt($olt, $fn);
            $this->log((int)$olt['id'], $action, $target, true, 'ok');
            return $result;
        } catch (Throwable $e) {
            $this->log((int)$olt['id'], $action, $target, false, $e->getMessage());
            throw $e;
        }
    }

    public function log(?int $oltId, string $action, string $target, bool $ok, string $message): void
    {
        $this->db->prepare('INSERT INTO actions (olt_id, action, target, ok, message, source, transcript, created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$oltId, $action, mb_substr($target, 0, 100), $ok ? 1 : 0, mb_substr($message, 0, 500), $this->source, $this->lastTranscript, self::now()]);
        $this->lastTranscript = null;
    }

    /** Read boards, profiles, every ONT and their light levels, then store it all. Returns a summary. */
    public function refresh(array $olt, bool $withSignal = true): array
    {
        try {
            $data = $this->withOlt($olt, function (Huawei $s) use ($withSignal) {
                $info = $s->info();
                $onts = $s->onts();
                $optical = [];
                if ($withSignal) {
                    $ports = [];
                    foreach ($onts as $o) {
                        $ports["{$o['frame']}/{$o['slot']}/{$o['port']}"] = [$o['frame'], $o['slot'], $o['port']];
                    }
                    foreach ($ports as $key => [$f, $sl, $p]) {
                        $optical[$key] = $s->portOptical($f, $sl, $p);
                    }
                }
                return ['info' => $info, 'onts' => $onts, 'optical' => $optical];
            });
        } catch (Throwable $e) {
            $this->db->prepare("UPDATE olts SET status='error', last_error=?, last_polled_at=?, updated_at=? WHERE id=?")
                ->execute([mb_substr($e->getMessage(), 0, 500), self::now(), self::now(), $olt['id']]);
            throw $e;
        }

        $info = $data['info'];
        $now = self::now();
        $this->db->prepare("UPDATE olts SET status='online', last_error='', model=?, version=?, boards_json=?, profiles_json=?, last_polled_at=?, updated_at=? WHERE id=?")
            ->execute([$info['model'], $info['version'], json_encode($info['boards']),
                json_encode(['line' => $info['line_profiles'], 'srv' => $info['srv_profiles'], 'vlans' => $info['vlans']]), $now, $now, $olt['id']]);

        $up = $this->db->prepare('INSERT INTO onus (olt_id, frame, slot, port, ont_id, sn, description, control_flag, run_state, config_state, match_state, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE sn=VALUES(sn), description=VALUES(description), control_flag=VALUES(control_flag),
                run_state=VALUES(run_state), config_state=VALUES(config_state), match_state=VALUES(match_state), updated_at=VALUES(updated_at)');
        $sig = $this->db->prepare('UPDATE onus SET rx_power=?, tx_power=?, olt_rx_power=?, temperature=?, signal_at=? WHERE olt_id=? AND frame=? AND slot=? AND port=? AND ont_id=?');
        $seen = [];
        foreach ($data['onts'] as $o) {
            $up->execute([$olt['id'], $o['frame'], $o['slot'], $o['port'], $o['ont_id'], $o['sn'], $o['description'],
                $o['control_flag'], $o['run_state'], $o['config_state'], $o['match_state'], $now]);
            $seen["{$o['frame']}/{$o['slot']}/{$o['port']}/{$o['ont_id']}"] = true;
            $lv = $data['optical']["{$o['frame']}/{$o['slot']}/{$o['port']}"][$o['ont_id']] ?? null;
            if ($lv) {
                $sig->execute([$lv['rx_power'], $lv['tx_power'], $lv['olt_rx_power'], $lv['temperature'], $now,
                    $olt['id'], $o['frame'], $o['slot'], $o['port'], $o['ont_id']]);
            }
        }

        // ONTs deleted on the OLT itself leave the list too. An empty read is never trusted for this:
        // output we failed to parse must not wipe the list.
        $removed = 0;
        if ($seen) {
            $rows = $this->db->prepare('SELECT id, frame, slot, port, ont_id FROM onus WHERE olt_id=?');
            $rows->execute([$olt['id']]);
            $del = $this->db->prepare('DELETE FROM onus WHERE id=?');
            foreach ($rows->fetchAll() as $r) {
                if (empty($seen["{$r['frame']}/{$r['slot']}/{$r['port']}/{$r['ont_id']}"])) {
                    $del->execute([$r['id']]);
                    $removed++;
                }
            }
        }
        $online = count(array_filter($data['onts'], function ($o) { return strtolower($o['run_state']) === 'online'; }));
        return ['onus' => count($data['onts']), 'online' => $online, 'removed' => $removed, 'model' => $info['model']];
    }

    // ----- ONU actions -----

    /** Descriptions go inside double quotes on the OLT command line, so keep them to safe characters. */
    public static function cleanDesc(string $s): string
    {
        $s = preg_replace('/[^A-Za-z0-9 _.@-]/', '', $s);
        return mb_substr(trim(preg_replace('/\s+/', '_', trim($s))), 0, 60);
    }

    /** Validate, authorize on the OLT and store. Returns ['ont_id' => n, 'id' => row id]. */
    public function authorize(array $in): array
    {
        $olt = $this->olt((int)($in['olt_id'] ?? 0));
        if (!$olt) throw new InvalidArgumentException('OLT not found.');
        $sn = Huawei::normaliseSn((string)($in['sn'] ?? ''));
        if ($sn === null) throw new InvalidArgumentException('That serial number does not look right.');
        $frame = (int)($in['frame'] ?? 0); $slot = (int)($in['slot'] ?? -1); $port = (int)($in['port'] ?? -1);
        if ($slot < 0 || $slot > 21 || $port < 0 || $port > 15) throw new InvalidArgumentException('Choose the board and PON port.');
        $line = (int)($in['line_profile'] ?? -1); $srv = (int)($in['srv_profile'] ?? -1);
        if ($line < 0 || $srv < 0) throw new InvalidArgumentException('Choose a line profile and a service profile.');
        $vlan = isset($in['vlan']) && $in['vlan'] !== '' ? (int)$in['vlan'] : null;
        if ($vlan !== null && ($vlan < 1 || $vlan > 4094)) throw new InvalidArgumentException('The VLAN must be between 1 and 4094.');
        $gem = (int)($in['gemport'] ?? 1);
        $desc = self::cleanDesc((string)($in['description'] ?? ''));
        if ($desc === '') $desc = 'onu_' . substr($sn, -8);
        $bridge = ($in['mode'] ?? 'bridge') !== 'router';

        $ontId = $this->act($olt, 'onu.authorize', "{$frame}/{$slot}/{$port} {$sn}", function (Huawei $s) use ($frame, $slot, $port, $sn, $line, $srv, $desc, $vlan, $gem, $bridge) {
            return $s->authorize($frame, $slot, $port, $sn, $line, $srv, $desc, $vlan, $gem, $bridge);
        });
        $now = self::now();
        $this->db->prepare('INSERT INTO onus (olt_id, frame, slot, port, ont_id, sn, description, run_state, vlan, line_profile, srv_profile, customer_ref, authorized_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE sn=VALUES(sn), description=VALUES(description), vlan=VALUES(vlan), line_profile=VALUES(line_profile),
                srv_profile=VALUES(srv_profile), customer_ref=VALUES(customer_ref), authorized_at=VALUES(authorized_at), updated_at=VALUES(updated_at)')
            ->execute([$olt['id'], $frame, $slot, $port, $ontId, $sn, $desc, '', $vlan, $line, $srv,
                mb_substr((string)($in['customer_ref'] ?? ''), 0, 100), $now, $now]);
        $st = $this->db->prepare('SELECT id FROM onus WHERE olt_id=? AND frame=? AND slot=? AND port=? AND ont_id=?');
        $st->execute([$olt['id'], $frame, $slot, $port, $ontId]);
        return ['ont_id' => $ontId, 'id' => (int)$st->fetchColumn()];
    }

    public function autofind(int $oltId): array
    {
        $olt = $this->olt($oltId);
        if (!$olt) throw new InvalidArgumentException('OLT not found.');
        return $this->act($olt, 'onu.autofind', $olt['name'], function (Huawei $s) {
            return $s->autofind();
        });
    }

    private function onuAndOlt(int $id): array
    {
        $onu = $this->onu($id);
        if (!$onu) throw new InvalidArgumentException('ONU not found.');
        return [$onu, $this->olt((int)$onu['olt_id']), "{$onu['frame']}/{$onu['slot']}/{$onu['port']}/{$onu['ont_id']}"];
    }

    /** Read one ONU live and update its stored state. Returns [onu row, detail key values]. */
    public function liveOnu(int $id): array
    {
        [$onu, $olt, $pos] = $this->onuAndOlt($id);
        $live = $this->act($olt, 'onu.status', $pos, function (Huawei $s) use ($onu) {
            return [
                'detail'  => $s->ontDetail($onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id']),
                'optical' => $s->ontOptical($onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id']),
            ];
        });
        $d = $live['detail'];
        $lv = $live['optical'];
        $run = strtolower($d['run state'] ?? $onu['run_state']);
        $this->db->prepare('UPDATE onus SET run_state=?, rx_power=?, tx_power=?, olt_rx_power=?, temperature=?, signal_at=?, updated_at=? WHERE id=?')
            ->execute([$run, $lv['rx_power'], $lv['tx_power'], $lv['olt_rx_power'], $lv['temperature'], self::now(), self::now(), $onu['id']]);
        return [array_merge($onu, $lv, ['run_state' => $run]), $d];
    }

    public function reboot(int $id): void
    {
        [$onu, $olt, $pos] = $this->onuAndOlt($id);
        $this->act($olt, 'onu.reboot', $pos, function (Huawei $s) use ($onu) {
            $s->reboot($onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id']);
        });
    }

    public function deleteOnu(int $id): int
    {
        [$onu, $olt, $pos] = $this->onuAndOlt($id);
        $ports = $this->act($olt, 'onu.delete', "{$pos} {$onu['sn']}", function (Huawei $s) use ($onu) {
            return $s->delete($onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id']);
        });
        $this->db->prepare('DELETE FROM onus WHERE id=?')->execute([$onu['id']]);
        return $ports;
    }

    public function describe(int $id, string $description, ?string $customerRef): string
    {
        [$onu, $olt, $pos] = $this->onuAndOlt($id);
        $desc = self::cleanDesc($description);
        if ($desc === '') throw new InvalidArgumentException('Enter a description.');
        $this->act($olt, 'onu.describe', $pos, function (Huawei $s) use ($onu, $desc) {
            $s->setDescription($onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id'], $desc);
        });
        $this->db->prepare('UPDATE onus SET description=?, customer_ref=?, updated_at=? WHERE id=?')
            ->execute([$desc, mb_substr($customerRef ?? $onu['customer_ref'], 0, 100), self::now(), $onu['id']]);
        return $desc;
    }

    public function actions(int $limit = 200): array
    {
        return $this->db->query('SELECT a.id, a.action, a.target, a.ok, a.message, a.source, a.created_at, o.name AS olt_name
            FROM actions a LEFT JOIN olts o ON o.id=a.olt_id ORDER BY a.id DESC LIMIT ' . (int)$limit)->fetchAll();
    }

    /** The token the billing dashboard sends: HMAC of this site's subdomain with the fleet secret. */
    public function apiToken(): string
    {
        return hash_hmac('sha256', $this->config->getSubdomain(), $this->config->getFleetSecret());
    }
}
