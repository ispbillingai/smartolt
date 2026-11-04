<?php
// Everything the pages and the API do with an OLT: stored passwords, the per OLT lock, the action
// log, the full read that brings the database copy up to date, history, speed profiles and the
// zone, ODB and ONU type lists.

require_once __DIR__ . '/Huawei.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/../config/database.php';

class OltManager
{
    private PDO $db;
    private Database $config;
    private ?string $lastTranscript = null;
    public string $source = 'panel';

    // Received light at the ONU, Huawei GPON: below -27 dBm (or above -8, too hot) drops; -25 to -27 is close to it.
    const SIGNAL_CRITICAL = -27.0;
    const SIGNAL_WARNING = -25.0;
    const SIGNAL_HOT = -8.0;
    // A signal point is kept at most this often per ONU, so a month of history stays small.
    const HISTORY_EVERY = 900;

    public function __construct()
    {
        // One clock for every stored time, whether a page, the API or the cron wrote it.
        date_default_timezone_set('Africa/Nairobi');
        $this->config = new Database();
        $this->db = $this->config->getConnection();
        Schema::ensure($this->db);
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

    const ONU_SELECT = 'SELECT u.*, o.name AS olt_name, z.name AS zone_name, d.name AS odb_name, t.name AS type_name,
            sp.name AS speed_name, sp.down_kbps, sp.up_kbps
        FROM onus u JOIN olts o ON o.id=u.olt_id
        LEFT JOIN zones z ON z.id=u.zone_id LEFT JOIN odbs d ON d.id=u.odb_id
        LEFT JOIN onu_types t ON t.id=u.onu_type_id LEFT JOIN speed_profiles sp ON sp.id=u.speed_profile_id';

    public function onu(int $id): ?array
    {
        $st = $this->db->prepare(self::ONU_SELECT . ' WHERE u.id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** An OLT row without its password, with boards and profiles decoded. */
    public static function publicOlt(array $o): array
    {
        unset($o['password_enc']);
        $o['boards'] = json_decode((string)$o['boards_json'], true) ?: [];
        $o['profiles'] = json_decode((string)$o['profiles_json'], true) ?: ['line' => [], 'srv' => [], 'vlans' => []];
        $o['traffic'] = json_decode((string)($o['traffic_json'] ?? ''), true) ?: [];
        unset($o['boards_json'], $o['profiles_json'], $o['traffic_json']);
        return $o;
    }

    /** SQL for the status filters every list shares. */
    private static function statusWhere(string $status): ?string
    {
        $c = self::SIGNAL_CRITICAL; $w = self::SIGNAL_WARNING; $h = self::SIGNAL_HOT;
        switch ($status) {
            case 'online':     return "u.status_group='online'";
            case 'offline':    return "u.run_state<>'online'";
            case 'los':        return "u.status_group='los'";
            case 'power_fail': return "u.status_group='power_fail'";
            case 'other':      return "u.status_group='other'";
            case 'disabled':   return "u.status_group='disabled'";
            case 'critical':   return "u.rx_power IS NOT NULL AND (u.rx_power < {$c} OR u.rx_power > {$h})";
            case 'warning':    return "u.rx_power IS NOT NULL AND u.rx_power >= {$c} AND u.rx_power < {$w}";
            case 'weak':       return "u.rx_power IS NOT NULL AND (u.rx_power < {$w} OR u.rx_power > {$h})";
        }
        return null;
    }

    const STATUSES = ['online', 'offline', 'los', 'power_fail', 'other', 'disabled', 'warning', 'critical', 'weak'];

    public function listOnus(array $f, int $limit = 5000): array
    {
        $where = ['1=1'];
        $args = [];
        foreach (['olt_id' => 'u.olt_id', 'zone_id' => 'u.zone_id', 'odb_id' => 'u.odb_id', 'onu_type_id' => 'u.onu_type_id', 'speed_profile_id' => 'u.speed_profile_id'] as $k => $col) {
            if (!empty($f[$k])) { $where[] = "{$col}=?"; $args[] = (int)$f[$k]; }
        }
        if (!empty($f['vlan'])) { $where[] = 'u.vlan=?'; $args[] = (int)$f['vlan']; }
        if (preg_match('#^(\d+)/(\d+)/(\d+)$#', trim((string)($f['pon'] ?? '')), $m)) {
            $where[] = 'u.frame=? AND u.slot=? AND u.port=?';
            array_push($args, (int)$m[1], (int)$m[2], (int)$m[3]);
        }
        if (($s = self::statusWhere((string)($f['status'] ?? ''))) !== null) { $where[] = $s; }
        if (($q = trim((string)($f['q'] ?? ''))) !== '') {
            $where[] = '(u.sn LIKE ? OR u.description LIKE ? OR u.customer_ref LIKE ? OR u.address LIKE ?)';
            array_push($args, "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%");
        }
        $st = $this->db->prepare(self::ONU_SELECT . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY o.name, u.frame, u.slot, u.port, u.ont_id LIMIT ' . (int)$limit);
        $st->execute($args);
        return $st->fetchAll();
    }

    /** Counts for the dashboard cards, optionally for one OLT. */
    public function counts(int $oltId = 0): array
    {
        $c = self::SIGNAL_CRITICAL; $w = self::SIGNAL_WARNING; $h = self::SIGNAL_HOT;
        $st = $this->db->prepare("SELECT COUNT(*) total,
            SUM(status_group='online') online, SUM(status_group='power_fail') power_fail, SUM(status_group='los') los,
            SUM(status_group='other') other_off, SUM(status_group='disabled') disabled,
            SUM(run_state<>'online') offline,
            SUM(rx_power IS NOT NULL AND rx_power >= {$c} AND rx_power < {$w}) warning,
            SUM(rx_power IS NOT NULL AND (rx_power < {$c} OR rx_power > {$h})) critical
            FROM onus WHERE (?=0 OR olt_id=?)");
        $st->execute([$oltId, $oltId]);
        $r = array_map('intval', $st->fetch() ?: []);
        $r['waiting'] = 0;
        foreach ($this->waiting($oltId) as $list) {
            $r['waiting'] += count($list['onus']);
        }
        return $r;
    }

    /** ONUs waiting for authorization as of each OLT's last read: [olt_id => ['at' => .., 'onus' => [..]]]. */
    public function waiting(int $oltId = 0): array
    {
        $st = $this->db->prepare('SELECT olt_id, onus_json, at FROM autofind_cache WHERE (?=0 OR olt_id=?)');
        $st->execute([$oltId, $oltId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int)$r['olt_id']] = ['at' => $r['at'], 'onus' => json_decode((string)$r['onus_json'], true) ?: []];
        }
        return $out;
    }

    /** Every PON port on an OLT that has ONUs, with its counts and weakest signal. */
    public function ports(int $oltId): array
    {
        $st = $this->db->prepare("SELECT frame, slot, port, COUNT(*) total, SUM(status_group='online') online,
            SUM(status_group='los') los, SUM(status_group='power_fail') power_fail,
            AVG(rx_power) avg_rx, MIN(rx_power) min_rx
            FROM onus WHERE olt_id=? GROUP BY frame, slot, port ORDER BY frame, slot, port");
        $st->execute([$oltId]);
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

    /**
     * Read boards, profiles, speed tables, every ONT with its state, down cause and light levels, and
     * the ONUs waiting for authorization; store it all with history. Returns a summary.
     */
    public function refresh(array $olt, bool $withSignal = true): array
    {
        $known = [];
        $st = $this->db->prepare('SELECT id, frame, slot, port, ont_id, run_state, status_group, last_down_cause, history_at FROM onus WHERE olt_id=?');
        $st->execute([$olt['id']]);
        foreach ($st->fetchAll() as $r) {
            $known["{$r['frame']}/{$r['slot']}/{$r['port']}/{$r['ont_id']}"] = $r;
        }

        try {
            $data = $this->withOlt($olt, function (Huawei $s) use ($withSignal, $known) {
                $info = $s->info();
                $onts = $s->onts();
                $waiting = $s->autofind();
                $services = $s->servicePorts();
                $ports = [];
                foreach ($onts as $o) {
                    $ports["{$o['frame']}/{$o['slot']}/{$o['port']}"] = [$o['frame'], $o['slot'], $o['port']];
                }
                $summary = [];
                $optical = [];
                $hasSummary = true;
                foreach ($ports as $key => [$f, $sl, $p]) {
                    if ($hasSummary) {
                        $sum = $s->portSummary($f, $sl, $p);
                        if ($sum === null) { $hasSummary = false; } else { $summary[$key] = $sum; }
                    }
                    if ($withSignal) {
                        $optical[$key] = $s->portOptical($f, $sl, $p);
                    }
                }
                // Older firmware: ask each ONT that newly went offline why, a few per read.
                $details = [];
                if (!$hasSummary) {
                    $budget = 30;
                    foreach ($onts as $o) {
                        $k = "{$o['frame']}/{$o['slot']}/{$o['port']}/{$o['ont_id']}";
                        $was = $known[$k] ?? null;
                        if (strtolower($o['run_state']) !== 'online' && $budget > 0 && (!$was || $was['run_state'] === 'online' || $was['last_down_cause'] === '')) {
                            $details[$k] = $s->ontDetail($o['frame'], $o['slot'], $o['port'], $o['ont_id']);
                            $budget--;
                        }
                    }
                }
                return compact('info', 'onts', 'waiting', 'services', 'summary', 'optical', 'details');
            });
        } catch (Throwable $e) {
            $this->db->prepare("UPDATE olts SET status='error', last_error=?, last_polled_at=?, updated_at=? WHERE id=?")
                ->execute([mb_substr($e->getMessage(), 0, 500), self::now(), self::now(), $olt['id']]);
            throw $e;
        }

        $info = $data['info'];
        $now = self::now();
        $this->db->prepare("UPDATE olts SET status='online', last_error='', model=?, version=?, boards_json=?, profiles_json=?, traffic_json=?, last_polled_at=?, updated_at=? WHERE id=?")
            ->execute([$info['model'], $info['version'], json_encode($info['boards']),
                json_encode(['line' => $info['line_profiles'], 'srv' => $info['srv_profiles'], 'vlans' => $info['vlans']]),
                json_encode($info['traffic']), $now, $now, $olt['id']]);
        $this->db->prepare('REPLACE INTO autofind_cache (olt_id, onus_json, at) VALUES (?,?,?)')
            ->execute([$olt['id'], json_encode($data['waiting']), $now]);

        $up = $this->db->prepare('INSERT INTO onus (olt_id, frame, slot, port, ont_id, sn, description, control_flag, run_state, config_state, match_state, status_group, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE sn=VALUES(sn), description=VALUES(description), control_flag=VALUES(control_flag),
                run_state=VALUES(run_state), config_state=VALUES(config_state), match_state=VALUES(match_state),
                status_group=VALUES(status_group), updated_at=VALUES(updated_at)');
        $more = $this->db->prepare('UPDATE onus SET model=IF(?<>\'\', ?, model), distance_m=?, last_down_cause=?, last_up_at=COALESCE(?, last_up_at), last_down_at=COALESCE(?, last_down_at)
            WHERE olt_id=? AND frame=? AND slot=? AND port=? AND ont_id=?');
        $sig = $this->db->prepare('UPDATE onus SET rx_power=?, tx_power=?, olt_rx_power=?, temperature=?, signal_at=? WHERE olt_id=? AND frame=? AND slot=? AND port=? AND ont_id=?');
        $noSig = $this->db->prepare('UPDATE onus SET rx_power=NULL, tx_power=NULL, olt_rx_power=NULL WHERE olt_id=? AND frame=? AND slot=? AND port=? AND ont_id=?');
        $idOf = $this->db->prepare('SELECT id, history_at FROM onus WHERE olt_id=? AND frame=? AND slot=? AND port=? AND ont_id=?');
        $event = $this->db->prepare('INSERT INTO status_events (onu_id, state, cause, at) VALUES (?,?,?,?)');
        $hist = $this->db->prepare('INSERT INTO signal_history (onu_id, rx_power, olt_rx_power, at) VALUES (?,?,?,?)');
        $histAt = $this->db->prepare('UPDATE onus SET history_at=? WHERE id=?');

        // VLAN, GEM port and speed as the OLT has them: the first service port of each ONT.
        $service = [];
        foreach ($data['services'] as $sp) {
            $k = "{$sp['frame']}/{$sp['slot']}/{$sp['port']}/{$sp['ont_id']}";
            if (!isset($service[$k]) || $sp['index'] < $service[$k]['index']) $service[$k] = $sp;
        }
        $profileByRate = [];
        foreach ($this->speedProfiles() as $p) $profileByRate["{$p['down_kbps']}/{$p['up_kbps']}"] = (int)$p['id'];
        $svc = $this->db->prepare('UPDATE onus SET vlan=?, gemport=?, speed_profile_id=COALESCE(?, speed_profile_id) WHERE olt_id=? AND frame=? AND slot=? AND port=? AND ont_id=?');

        $seen = [];
        $stats = ['total' => 0, 'online' => 0, 'los' => 0, 'power_fail' => 0, 'other_off' => 0, 'warning' => 0, 'critical' => 0];
        foreach ($data['onts'] as $o) {
            $pos = [$olt['id'], $o['frame'], $o['slot'], $o['port'], $o['ont_id']];
            $pk = "{$o['frame']}/{$o['slot']}/{$o['port']}";
            $k = "{$pk}/{$o['ont_id']}";
            $sum = $data['summary'][$pk][$o['ont_id']] ?? [];
            $run = strtolower($o['run_state']);
            $cause = $sum['down_cause'] ?? ($data['details'][$k]['last down cause'] ?? ($run === 'online' ? '' : ($known[$k]['last_down_cause'] ?? '')));
            $cause = $cause === '-' ? '' : $cause;
            $group = strtolower($o['control_flag']) === 'deactivated' ? 'disabled' : Huawei::downCategory($run, $cause);

            $up->execute(array_merge($pos, [$o['sn'], $o['description'], $o['control_flag'], $run, $o['config_state'], $o['match_state'], $group, $now]));
            $more->execute(array_merge([$sum['model'] ?? '', $sum['model'] ?? '', $sum['distance_m'] ?? null, $run === 'online' ? '' : $cause,
                $sum['last_up'] ?? null, $sum['last_down'] ?? null], $pos));
            $seen[$k] = true;
            if ($sp = $service[$k] ?? null) {
                $down = $info['traffic'][$sp['down_table']]['cir'] ?? null;
                $upk = $info['traffic'][$sp['up_table']]['cir'] ?? null;
                $svc->execute(array_merge([$sp['vlan'], $sp['gemport'], $profileByRate["{$down}/{$upk}"] ?? null], $pos));
            }

            $lv =$data['optical'][$pk][$o['ont_id']] ?? null;
            if ($lv === null && isset($sum['rx_power'])) {
                $lv = ['rx_power' => $sum['rx_power'], 'tx_power' => $sum['tx_power'], 'olt_rx_power' => null, 'temperature' => null];
            }
            if ($lv) {
                $sig->execute(array_merge([$lv['rx_power'], $lv['tx_power'], $lv['olt_rx_power'], $lv['temperature'], $now], $pos));
            } elseif ($run !== 'online') {
                // An offline ONU has no light: do not leave its last good level on the lists.
                $noSig->execute($pos);
            }

            $idOf->execute($pos);
            $row = $idOf->fetch();
            $was = $known[$k] ?? null;
            if ($row && $was && $was['status_group'] !== '' && $was['status_group'] !== $group) {
                $event->execute([$row['id'], $group, $run === 'online' ? '' : $cause, $now]);
            }
            if ($row && $lv && $lv['rx_power'] !== null && (!$row['history_at'] || strtotime($now) - strtotime($row['history_at']) >= self::HISTORY_EVERY - 30)) {
                $hist->execute([$row['id'], $lv['rx_power'], $lv['olt_rx_power'], $now]);
                $histAt->execute([$now, $row['id']]);
            }

            $stats['total']++;
            if ($group === 'online') $stats['online']++;
            elseif ($group === 'los') $stats['los']++;
            elseif ($group === 'power_fail') $stats['power_fail']++;
            else $stats['other_off']++;
            $rx = $lv['rx_power'] ?? null;
            if ($rx !== null) {
                if ($rx < self::SIGNAL_CRITICAL || $rx > self::SIGNAL_HOT) $stats['critical']++;
                elseif ($rx < self::SIGNAL_WARNING) $stats['warning']++;
            }
        }

        // ONTs deleted on the OLT itself leave the list too. An empty read is never trusted for this:
        // output we failed to parse must not wipe the list.
        $removed = 0;
        if ($seen) {
            $del = $this->db->prepare('DELETE FROM onus WHERE id=?');
            foreach ($known as $k => $r) {
                if (empty($seen[$k])) {
                    $del->execute([$r['id']]);
                    $removed++;
                }
            }
        }
        $this->db->prepare('INSERT INTO olt_stats (olt_id, at, total, online, los, power_fail, other_off, warning, critical, waiting) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$olt['id'], $now, $stats['total'], $stats['online'], $stats['los'], $stats['power_fail'], $stats['other_off'], $stats['warning'], $stats['critical'], count($data['waiting'])]);
        return ['onus' => $stats['total'], 'online' => $stats['online'], 'removed' => $removed, 'model' => $info['model'], 'waiting' => count($data['waiting'])];
    }

    /** History older than this is not kept: signal and network points 30 days, status changes 180 days. */
    public function prune(): void
    {
        $this->db->exec('DELETE FROM signal_history WHERE at < NOW() - INTERVAL 30 DAY');
        $this->db->exec('DELETE FROM olt_stats WHERE at < NOW() - INTERVAL 30 DAY');
        $this->db->exec('DELETE FROM status_events WHERE at < NOW() - INTERVAL 180 DAY');
    }

    // ----- ONU actions -----

    /** Descriptions go inside double quotes on the OLT command line, so keep them to safe characters. */
    public static function cleanDesc(string $s): string
    {
        $s = preg_replace('/[^A-Za-z0-9 _.@-]/', '', $s);
        return mb_substr(trim(preg_replace('/\s+/', '_', trim($s))), 0, 60);
    }

    /** Zone, ODB, type, address and location from a form, checked. Only keys present in $in are returned. */
    private function metaFromInput(array $in): array
    {
        $m = [];
        foreach (['zone_id' => 'zones', 'odb_id' => 'odbs', 'onu_type_id' => 'onu_types', 'speed_profile_id' => 'speed_profiles'] as $k => $table) {
            if (!array_key_exists($k, $in)) continue;
            $v = (int)$in[$k];
            if ($v > 0) {
                $st = $this->db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE id=?");
                $st->execute([$v]);
                if (!(int)$st->fetchColumn()) throw new InvalidArgumentException('That ' . str_replace(['_id', '_'], ['', ' '], $k) . ' no longer exists.');
            }
            $m[$k] = $v > 0 ? $v : null;
        }
        if (array_key_exists('odb_port', $in)) {
            $p = $in['odb_port'] === '' || $in['odb_port'] === null ? null : (int)$in['odb_port'];
            if ($p !== null && ($p < 1 || $p > 64)) throw new InvalidArgumentException('The ODB port must be between 1 and 64.');
            $m['odb_port'] = $p;
        }
        if (array_key_exists('customer_ref', $in)) $m['customer_ref'] = mb_substr(trim((string)$in['customer_ref']), 0, 100);
        if (array_key_exists('address', $in)) $m['address'] = mb_substr(trim((string)$in['address']), 0, 255);
        foreach (['lat' => 90, 'lng' => 180] as $k => $max) {
            if (!array_key_exists($k, $in)) continue;
            $v = trim((string)$in[$k]);
            if ($v === '') { $m[$k] = null; continue; }
            if (!is_numeric($v) || abs((float)$v) > $max) throw new InvalidArgumentException('The location must be a latitude and longitude in degrees, for example -1.2921 and 36.8219.');
            $m[$k] = round((float)$v, 7);
        }
        return $m;
    }

    private function saveMetaRow(int $id, array $m): void
    {
        if (!$m) return;
        $sets = implode(', ', array_map(function ($k) { return "`{$k}`=?"; }, array_keys($m)));
        $this->db->prepare("UPDATE onus SET {$sets}, updated_at=? WHERE id=?")->execute(array_merge(array_values($m), [self::now(), $id]));
    }

    public function speedProfile(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM speed_profiles WHERE id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * The traffic table on this OLT that limits to $kbps: the one we made before, one that already
     * matches, or a new one at the lowest free index from 10 (low indexes are the OLT's defaults).
     */
    private function trafficIndex(Huawei $s, array $olt, int $kbps, ?array &$tables): int
    {
        if ($tables === null) $tables = $s->trafficTables();
        $st = $this->db->prepare('SELECT idx FROM olt_traffic_tables WHERE olt_id=? AND kbps=?');
        $st->execute([$olt['id'], $kbps]);
        $idx = $st->fetchColumn();
        if ($idx !== false && isset($tables[(int)$idx]) && $tables[(int)$idx]['cir'] === $kbps) {
            return (int)$idx;
        }
        $idx = null;
        foreach ($tables as $i => $t) {
            if ($i >= 10 && $t['cir'] === $kbps && $t['pir'] === $kbps) { $idx = $i; break; }
        }
        if ($idx === null) {
            for ($i = 10; isset($tables[$i]); $i++);
            if ($i > 1000) throw new OltError('The OLT has no free traffic table index for a new speed.');
            $s->createTrafficTable($i, $kbps);
            $tables[$i] = ['cir' => $kbps, 'pir' => $kbps];
            $idx = $i;
        }
        $this->db->prepare('REPLACE INTO olt_traffic_tables (olt_id, kbps, idx) VALUES (?,?,?)')->execute([$olt['id'], $kbps, $idx]);
        return $idx;
    }

    /** [up table, down table] for a speed profile on this OLT, or [null, null] for no limit. */
    private function speedTables(Huawei $s, array $olt, ?array $speed, ?array &$tables): array
    {
        if (!$speed) return [null, null];
        return [$this->trafficIndex($s, $olt, (int)$speed['up_kbps'], $tables), $this->trafficIndex($s, $olt, (int)$speed['down_kbps'], $tables)];
    }

    /** The ONU type whose name appears in the model the OLT reports, for example EG8145V5. */
    public function typeForModel(string $model): ?int
    {
        $model = strtoupper(trim($model));
        if ($model === '') return null;
        foreach ($this->onuTypes() as $t) {
            if (strtoupper($t['name']) === $model || strpos($model, strtoupper($t['name'])) !== false) return (int)$t['id'];
        }
        return null;
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
        $mode = ($in['mode'] ?? 'bridge') === 'router' ? 'router' : 'bridge';
        $meta = $this->metaFromInput($in);
        $speed = !empty($meta['speed_profile_id']) ? $this->speedProfile($meta['speed_profile_id']) : null;
        if ($speed && $vlan === null) throw new InvalidArgumentException('A speed limit needs an internet VLAN.');

        $ontId = $this->act($olt, 'onu.authorize', "{$frame}/{$slot}/{$port} {$sn}", function (Huawei $s) use ($olt, $frame, $slot, $port, $sn, $line, $srv, $desc, $vlan, $gem, $mode, $speed) {
            $tables = null;
            [$upT, $downT] = $this->speedTables($s, $olt, $speed, $tables);
            return $s->authorize($frame, $slot, $port, $sn, $line, $srv, $desc, $vlan, $gem, $mode === 'bridge', $upT, $downT);
        });
        $now = self::now();
        $this->db->prepare("INSERT INTO onus (olt_id, frame, slot, port, ont_id, sn, description, run_state, vlan, gemport, mode, line_profile, srv_profile, model, authorized_at, updated_at)
            VALUES (?,?,?,?,?,?,?,'',?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE sn=VALUES(sn), description=VALUES(description), vlan=VALUES(vlan), gemport=VALUES(gemport), mode=VALUES(mode),
                line_profile=VALUES(line_profile), srv_profile=VALUES(srv_profile), model=VALUES(model), authorized_at=VALUES(authorized_at), updated_at=VALUES(updated_at)")
            ->execute([$olt['id'], $frame, $slot, $port, $ontId, $sn, $desc, $vlan, $gem, $mode, $line, $srv,
                mb_substr((string)($in['model'] ?? ''), 0, 60), $now, $now]);
        $st = $this->db->prepare('SELECT id FROM onus WHERE olt_id=? AND frame=? AND slot=? AND port=? AND ont_id=?');
        $st->execute([$olt['id'], $frame, $slot, $port, $ontId]);
        $id = (int)$st->fetchColumn();
        $this->saveMetaRow($id, $meta);
        // It no longer waits for authorization.
        $cache = $this->waiting((int)$olt['id'])[(int)$olt['id']] ?? null;
        if ($cache) {
            $left = array_values(array_filter($cache['onus'], function ($u) use ($sn) { return $u['sn'] !== $sn; }));
            $this->db->prepare('UPDATE autofind_cache SET onus_json=? WHERE olt_id=?')->execute([json_encode($left), $olt['id']]);
        }
        return ['ont_id' => $ontId, 'id' => $id];
    }

    public function autofind(int $oltId): array
    {
        $olt = $this->olt($oltId);
        if (!$olt) throw new InvalidArgumentException('OLT not found.');
        $found = $this->act($olt, 'onu.autofind', $olt['name'], function (Huawei $s) {
            return $s->autofind();
        });
        $this->db->prepare('REPLACE INTO autofind_cache (olt_id, onus_json, at) VALUES (?,?,?)')->execute([$oltId, json_encode($found), self::now()]);
        return $found;
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
        $cause = $run === 'online' ? '' : (string)($d['last down cause'] ?? $onu['last_down_cause']);
        $group = strtolower($d['control flag'] ?? $onu['control_flag']) === 'deactivated' ? 'disabled' : Huawei::downCategory($run, $cause);
        if ($onu['status_group'] !== '' && $onu['status_group'] !== $group) {
            $this->db->prepare('INSERT INTO status_events (onu_id, state, cause, at) VALUES (?,?,?,?)')->execute([$onu['id'], $group, $cause, self::now()]);
        }
        $this->db->prepare('UPDATE onus SET run_state=?, status_group=?, last_down_cause=?, rx_power=?, tx_power=?, olt_rx_power=?, temperature=?, signal_at=?, updated_at=? WHERE id=?')
            ->execute([$run, $group, $cause, $lv['rx_power'], $lv['tx_power'], $lv['olt_rx_power'], $lv['temperature'], self::now(), self::now(), $onu['id']]);
        return [array_merge($onu, $lv, ['run_state' => $run, 'status_group' => $group, 'last_down_cause' => $cause]), $d];
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
        if ($desc !== $onu['description']) {
            $this->act($olt, 'onu.describe', $pos, function (Huawei $s) use ($onu, $desc) {
                $s->setDescription($onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id'], $desc);
            });
        }
        $this->db->prepare('UPDATE onus SET description=?, customer_ref=?, updated_at=? WHERE id=?')
            ->execute([$desc, mb_substr($customerRef ?? $onu['customer_ref'], 0, 100), self::now(), $onu['id']]);
        return $desc;
    }

    /** Zone, ODB, ONU type, customer, address and location. Stored here only, nothing changes on the OLT. */
    public function saveMeta(int $id, array $in): void
    {
        if (!$this->onu($id)) throw new InvalidArgumentException('ONU not found.');
        unset($in['speed_profile_id']); // changing speed goes through the OLT, see setService()
        $this->saveMetaRow($id, $this->metaFromInput($in));
    }

    /** New VLAN, speed or mode: the ONT's service ports are replaced on the OLT. */
    public function setService(int $id, array $in): void
    {
        [$onu, $olt, $pos] = $this->onuAndOlt($id);
        $vlan = (int)($in['vlan'] ?? 0);
        if ($vlan < 1 || $vlan > 4094) throw new InvalidArgumentException('The VLAN must be between 1 and 4094.');
        $mode = ($in['mode'] ?? ($onu['mode'] ?: 'bridge')) === 'router' ? 'router' : 'bridge';
        $gem = isset($in['gemport']) && $in['gemport'] !== '' ? (int)$in['gemport'] : (int)($onu['gemport'] ?: 1);
        $meta = $this->metaFromInput(['speed_profile_id' => $in['speed_profile_id'] ?? 0]);
        $speed = $meta['speed_profile_id'] ? $this->speedProfile($meta['speed_profile_id']) : null;
        $this->act($olt, 'onu.service', $pos . " vlan {$vlan}" . ($speed ? " {$speed['name']}" : ''), function (Huawei $s) use ($olt, $onu, $vlan, $gem, $mode, $speed) {
            $tables = null;
            [$upT, $downT] = $this->speedTables($s, $olt, $speed, $tables);
            $s->setService($onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id'], $vlan, $gem, $mode === 'bridge', $upT, $downT);
        });
        $this->db->prepare('UPDATE onus SET vlan=?, gemport=?, mode=?, speed_profile_id=?, updated_at=? WHERE id=?')
            ->execute([$vlan, $gem, $mode, $meta['speed_profile_id'], self::now(), $onu['id']]);
    }

    public function setActive(int $id, bool $active): void
    {
        [$onu, $olt, $pos] = $this->onuAndOlt($id);
        $this->act($olt, $active ? 'onu.enable' : 'onu.disable', $pos, function (Huawei $s) use ($onu, $active) {
            $s->setActive($onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id'], $active);
        });
        $group = $active ? Huawei::downCategory($onu['run_state'], $onu['last_down_cause']) : 'disabled';
        $this->db->prepare('INSERT INTO status_events (onu_id, state, cause, at) VALUES (?,?,?,?)')->execute([$onu['id'], $group, $active ? 'enabled' : 'disabled', self::now()]);
        $this->db->prepare("UPDATE onus SET control_flag=?, status_group=?, updated_at=? WHERE id=?")
            ->execute([$active ? 'active' : 'deactivated', $group, self::now(), $onu['id']]);
    }

    /** Swap the customer's broken ONU for a new one: same place, profiles, VLAN and speed, new serial. */
    public function replaceSn(int $id, string $newSn): string
    {
        [$onu, $olt, $pos] = $this->onuAndOlt($id);
        $sn = Huawei::normaliseSn($newSn);
        if ($sn === null) throw new InvalidArgumentException('That serial number does not look right.');
        if ($sn === $onu['sn']) throw new InvalidArgumentException('That is the serial number it already has.');
        $st = $this->db->prepare('SELECT COUNT(*) FROM onus WHERE sn=? AND id<>?');
        $st->execute([$sn, $onu['id']]);
        if ((int)$st->fetchColumn()) throw new InvalidArgumentException('Another authorized ONU already has that serial number.');
        $this->act($olt, 'onu.replace_sn', "{$pos} {$onu['sn']} to {$sn}", function (Huawei $s) use ($onu, $sn) {
            $s->replaceSn($onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id'], $sn);
        });
        $this->db->prepare("UPDATE onus SET sn=?, model='', updated_at=? WHERE id=?")->execute([$sn, self::now(), $onu['id']]);
        return $sn;
    }

    const BULK_ACTIONS = ['reboot', 'disable', 'enable', 'speed'];

    /**
     * One action on many ONUs, one OLT session per OLT. Returns ['done' => n, 'failed' => [msg, ...]].
     * $param is the speed profile id for 'speed'.
     */
    public function bulk(array $ids, string $action, int $param = 0): array
    {
        if (!in_array($action, self::BULK_ACTIONS, true)) throw new InvalidArgumentException('Unknown action.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) throw new InvalidArgumentException('Tick the ONUs first.');
        if (count($ids) > 500) throw new InvalidArgumentException('At most 500 ONUs at a time.');
        $speed = null;
        if ($action === 'speed') {
            $speed = $this->speedProfile($param);
            if (!$speed) throw new InvalidArgumentException('Choose a speed profile.');
        }
        $st = $this->db->prepare(self::ONU_SELECT . ' WHERE u.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $byOlt = [];
        foreach ($st->fetchAll() as $u) $byOlt[(int)$u['olt_id']][] = $u;

        $done = 0;
        $failed = [];
        foreach ($byOlt as $oltId => $onus) {
            $olt = $this->olt($oltId);
            $okIds = [];
            try {
                $this->withOlt($olt, function (Huawei $s) use ($olt, $onus, $action, $speed, &$okIds, &$failed) {
                    $tables = null;
                    foreach ($onus as $u) {
                        $where = "{$u['description']} ({$u['frame']}/{$u['slot']}/{$u['port']} ID {$u['ont_id']})";
                        try {
                            if ($action === 'reboot') $s->reboot($u['frame'], $u['slot'], $u['port'], $u['ont_id']);
                            elseif ($action === 'disable' || $action === 'enable') $s->setActive($u['frame'], $u['slot'], $u['port'], $u['ont_id'], $action === 'enable');
                            else {
                                if (!$u['vlan']) throw new OltError('no internet VLAN saved for it, set the VLAN on its page first');
                                [$upT, $downT] = $this->speedTables($s, $olt, $speed, $tables);
                                $s->setService($u['frame'], $u['slot'], $u['port'], $u['ont_id'], (int)$u['vlan'], (int)($u['gemport'] ?: 1), $u['mode'] !== 'router', $upT, $downT);
                            }
                            $okIds[] = (int)$u['id'];
                        } catch (OltError $e) {
                            $failed[] = "{$where}: {$e->getMessage()}";
                        }
                    }
                });
            } catch (Throwable $e) {
                foreach ($onus as $u) $failed[] = "{$u['description']}: {$e->getMessage()}";
            }
            $this->log($oltId, "onu.bulk_{$action}", count($okIds) . ' of ' . count($onus) . ' ONUs', count($okIds) === count($onus), count($okIds) === count($onus) ? 'ok' : implode('; ', array_slice($failed, -5)));
            if ($okIds) {
                $in = implode(',', $okIds);
                if ($action === 'disable') $this->db->exec("UPDATE onus SET control_flag='deactivated', status_group='disabled' WHERE id IN ({$in})");
                if ($action === 'enable') $this->db->exec("UPDATE onus SET control_flag='active', status_group=IF(run_state='online','online','other') WHERE id IN ({$in})");
                if ($action === 'speed') $this->db->exec('UPDATE onus SET speed_profile_id=' . (int)$speed['id'] . " WHERE id IN ({$in})");
            }
            $done += count($okIds);
        }
        return ['done' => $done, 'failed' => $failed];
    }

    // ----- History -----

    public function signalHistory(int $id, int $days = 7): array
    {
        $st = $this->db->prepare('SELECT rx_power, olt_rx_power, at FROM signal_history WHERE onu_id=? AND at >= ? ORDER BY at');
        $st->execute([$id, date('Y-m-d H:i:s', time() - $days * 86400)]);
        return $st->fetchAll();
    }

    public function statusEvents(int $id, int $limit = 50): array
    {
        $st = $this->db->prepare('SELECT state, cause, at FROM status_events WHERE onu_id=? ORDER BY id DESC LIMIT ' . (int)$limit);
        $st->execute([$id]);
        return $st->fetchAll();
    }

    /** Online and offline ONUs over time across all OLTs (or one), in 10 minute steps. */
    public function networkStatus(int $hours = 24, int $oltId = 0): array
    {
        $st = $this->db->prepare('SELECT FLOOR(UNIX_TIMESTAMP(at)/600)*600 AS t, olt_id, MAX(online) online, MAX(total - online) offline,
            MAX(los) los, MAX(power_fail) power_fail FROM olt_stats WHERE at >= ? AND (?=0 OR olt_id=?) GROUP BY t, olt_id ORDER BY t');
        $st->execute([date('Y-m-d H:i:s', time() - $hours * 3600), $oltId, $oltId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $t = (int)$r['t'];
            $out[$t] = $out[$t] ?? ['t' => $t, 'online' => 0, 'offline' => 0, 'los' => 0, 'power_fail' => 0];
            foreach (['online', 'offline', 'los', 'power_fail'] as $k) $out[$t][$k] += (int)$r[$k];
        }
        return array_values($out);
    }

    /** Status changes across the network, newest first, for the dashboard. */
    public function recentEvents(int $limit = 15): array
    {
        return $this->db->query('SELECT e.state, e.cause, e.at, u.id AS onu_id, u.description, u.sn, o.name AS olt_name
            FROM status_events e JOIN onus u ON u.id=e.onu_id JOIN olts o ON o.id=u.olt_id ORDER BY e.id DESC LIMIT ' . (int)$limit)->fetchAll();
    }

    // ----- Zones, ODBs, ONU types, speed profiles -----

    public function zones(): array
    {
        return $this->db->query('SELECT z.*, (SELECT COUNT(*) FROM onus u WHERE u.zone_id=z.id) AS onu_count FROM zones z ORDER BY z.name')->fetchAll();
    }

    public function odbs(): array
    {
        return $this->db->query('SELECT d.*, z.name AS zone_name, (SELECT COUNT(*) FROM onus u WHERE u.odb_id=d.id) AS onu_count
            FROM odbs d LEFT JOIN zones z ON z.id=d.zone_id ORDER BY d.name')->fetchAll();
    }

    public function onuTypes(): array
    {
        return $this->db->query('SELECT t.*, (SELECT COUNT(*) FROM onus u WHERE u.onu_type_id=t.id) AS onu_count FROM onu_types t ORDER BY t.name')->fetchAll();
    }

    public function speedProfiles(): array
    {
        return $this->db->query('SELECT s.*, (SELECT COUNT(*) FROM onus u WHERE u.speed_profile_id=s.id) AS onu_count FROM speed_profiles s ORDER BY s.down_kbps, s.name')->fetchAll();
    }

    private static function cleanName(string $s, string $what): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        if ($s === '' || mb_strlen($s) > 100) throw new InvalidArgumentException("Give the {$what} a name.");
        return $s;
    }

    private function saveRow(string $table, ?int $id, array $row): int
    {
        try {
            if ($id) {
                $sets = implode(', ', array_map(function ($k) { return "`{$k}`=?"; }, array_keys($row)));
                $this->db->prepare("UPDATE `{$table}` SET {$sets} WHERE id=?")->execute(array_merge(array_values($row), [$id]));
                return $id;
            }
            $cols = implode(', ', array_map(function ($k) { return "`{$k}`"; }, array_keys($row)));
            $this->db->prepare("INSERT INTO `{$table}` ({$cols}) VALUES (" . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') throw new InvalidArgumentException('That name is already in use.');
            throw $e;
        }
    }

    public function saveZone(array $in): int
    {
        $id = (int)($in['id'] ?? 0) ?: null;
        $row = ['name' => self::cleanName((string)($in['name'] ?? ''), 'zone')];
        if (!$id) $row['created_at'] = self::now();
        return $this->saveRow('zones', $id, $row);
    }

    public function saveOdb(array $in): int
    {
        $id = (int)($in['id'] ?? 0) ?: null;
        $ports = (int)($in['ports'] ?? 16);
        if ($ports < 1 || $ports > 64) throw new InvalidArgumentException('An ODB has between 1 and 64 ports.');
        $meta = $this->metaFromInput(array_intersect_key($in, array_flip(['zone_id', 'lat', 'lng'])));
        $row = ['name' => self::cleanName((string)($in['name'] ?? ''), 'ODB'), 'zone_id' => $meta['zone_id'] ?? null, 'ports' => $ports,
            'lat' => $meta['lat'] ?? null, 'lng' => $meta['lng'] ?? null];
        if (!$id) $row['created_at'] = self::now();
        return $this->saveRow('odbs', $id, $row);
    }

    public function saveOnuType(array $in): int
    {
        $id = (int)($in['id'] ?? 0) ?: null;
        $name = strtoupper(self::cleanName((string)($in['name'] ?? ''), 'ONU type'));
        $eth = (int)($in['eth_ports'] ?? 1); $pots = (int)($in['pots_ports'] ?? 0);
        if ($eth < 0 || $eth > 24 || $pots < 0 || $pots > 8) throw new InvalidArgumentException('Check the number of ports.');
        return $this->saveRow('onu_types', $id, ['name' => $name, 'eth_ports' => $eth, 'pots_ports' => $pots,
            'wifi' => empty($in['wifi']) ? 0 : 1, 'catv' => empty($in['catv']) ? 0 : 1,
            'default_mode' => ($in['default_mode'] ?? '') === 'router' ? 'router' : 'bridge']);
    }

    /** Speeds come in as Mbps from the forms; stored and sent to the OLT as kbps. */
    public function saveSpeedProfile(array $in): int
    {
        $id = (int)($in['id'] ?? 0) ?: null;
        $down = (int)round((float)($in['down_mbps'] ?? 0) * 1024);
        $up = (int)round((float)($in['up_mbps'] ?? 0) * 1024);
        if ($down < 64 || $up < 64 || $down > 10240000 || $up > 10240000) throw new InvalidArgumentException('Speeds go from 0.0625 Mbps to 10000 Mbps.');
        $row = ['name' => self::cleanName((string)($in['name'] ?? ''), 'speed profile'), 'down_kbps' => $down, 'up_kbps' => $up];
        if (!$id) $row['created_at'] = self::now();
        return $this->saveRow('speed_profiles', $id, $row);
    }

    /** Remove a zone, ODB, type or speed profile. ONUs that used it keep working; they just lose the label. */
    public function deleteCatalog(string $kind, int $id): void
    {
        $map = ['zone' => ['zones', 'zone_id'], 'odb' => ['odbs', 'odb_id'], 'type' => ['onu_types', 'onu_type_id'], 'speed' => ['speed_profiles', 'speed_profile_id']];
        if (!isset($map[$kind])) throw new InvalidArgumentException('Unknown list.');
        [$table, $col] = $map[$kind];
        if ($kind === 'speed') {
            $st = $this->db->prepare('SELECT COUNT(*) FROM onus WHERE speed_profile_id=?');
            $st->execute([$id]);
            if ($n = (int)$st->fetchColumn()) throw new InvalidArgumentException("{$n} ONUs use this speed. Move them to another speed first.");
        }
        $this->db->prepare("UPDATE onus SET `{$col}`=NULL WHERE `{$col}`=?")->execute([$id]);
        $this->db->prepare("DELETE FROM `{$table}` WHERE id=?")->execute([$id]);
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
