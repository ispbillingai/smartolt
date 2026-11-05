<?php
// Everything the pages and the API do with an OLT: stored passwords, the per OLT lock, the action
// log, the full read that brings the database copy up to date, history, speed profiles and the
// zone, ODB and ONU type lists.

require_once __DIR__ . '/Huawei.php';
require_once __DIR__ . '/SnmpClient.php';
require_once __DIR__ . '/SnmpRates.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/../config/database.php';

class OltManager
{
    private PDO $db;
    private Database $config;
    private ?string $lastTranscript = null;
    public string $source = 'panel';
    // Who is acting, for the Events page: the signed in username; empty for the API and the cron.
    public string $user = '';
    // Where the person acting connected from; 127.0.0.1 for the cron.
    public string $ip = '127.0.0.1';

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
            sp.name AS speed_name, sp.down_kbps, sp.up_kbps, sc.name AS sector_name, pr.name AS preset_name
        FROM onus u JOIN olts o ON o.id=u.olt_id
        LEFT JOIN zones z ON z.id=u.zone_id LEFT JOIN odbs d ON d.id=u.odb_id
        LEFT JOIN onu_types t ON t.id=u.onu_type_id LEFT JOIN speed_profiles sp ON sp.id=u.speed_profile_id
        LEFT JOIN sectors sc ON sc.id=u.sector_id LEFT JOIN presets pr ON pr.id=u.preset_id';

    public function onu(int $id): ?array
    {
        $st = $this->db->prepare(self::ONU_SELECT . ' WHERE u.id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** An OLT row without its password, with boards and profiles decoded. */
    public static function publicOlt(array $o): array
    {
        unset($o['password_enc'], $o['vpn_pass_enc'], $o['snmp_community_enc']);
        $o['snmp'] = json_decode((string)($o['snmp_json'] ?? ''), true) ?: [];
        unset($o['snmp_json']);
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
            case 'good':       return "u.rx_power IS NOT NULL AND u.rx_power >= {$w} AND u.rx_power <= {$h}";
        }
        return null;
    }

    const STATUSES = ['online', 'offline', 'los', 'power_fail', 'other', 'disabled', 'warning', 'critical', 'weak', 'good'];

    public function listOnus(array $f, int $limit = 5000): array
    {
        $where = ['1=1'];
        $args = [];
        foreach (['olt_id' => 'u.olt_id', 'zone_id' => 'u.zone_id', 'odb_id' => 'u.odb_id', 'onu_type_id' => 'u.onu_type_id',
                  'speed_profile_id' => 'u.speed_profile_id', 'sector_id' => 'u.sector_id'] as $k => $col) {
            if (!empty($f[$k])) { $where[] = "{$col}=?"; $args[] = (int)$f[$k]; }
        }
        // Board and port are numbers where 0 is real, so '' means any.
        foreach (['board' => 'u.slot', 'port_no' => 'u.port'] as $k => $col) {
            if (isset($f[$k]) && $f[$k] !== '' && $f[$k] !== null) { $where[] = "{$col}=?"; $args[] = (int)$f[$k]; }
        }
        if (($f['vip'] ?? '') !== '') { $where[] = 'u.vip=?'; $args[] = $f['vip'] ? 1 : 0; }
        foreach (['changed_from' => '>=', 'changed_to' => '<'] as $k => $op) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f[$k] ?? ''))) {
                $where[] = "GREATEST(COALESCE(u.last_up_at, '2000-01-01'), COALESCE(u.last_down_at, '2000-01-01')) {$op} ?";
                $args[] = $k === 'changed_to' ? date('Y-m-d', strtotime($f[$k] . ' +1 day')) : $f[$k];
            }
        }
        if (in_array($f['mode'] ?? '', ['bridge', 'router'], true)) { $where[] = 'u.mode=?'; $args[] = $f['mode']; }
        if (($s = self::statusWhere((string)($f['signal'] ?? ''))) !== null) { $where[] = $s; }
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
        $order = [
            'auth_desc' => 'u.authorized_at IS NULL, u.authorized_at DESC, u.id DESC',
            'auth_asc'  => 'u.authorized_at IS NULL, u.authorized_at, u.id',
            'name'      => 'u.description',
            'signal'    => 'u.rx_power IS NULL, u.rx_power',
            'changed'   => "GREATEST(COALESCE(u.last_up_at, '2000-01-01'), COALESCE(u.last_down_at, '2000-01-01')) DESC",
        ][$f['sort'] ?? ''] ?? 'o.name, u.frame, u.slot, u.port, u.ont_id';
        $st = $this->db->prepare(self::ONU_SELECT . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $order . ' LIMIT ' . (int)$limit);
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
        // Waiting ONUs split the way the dashboard card shows them: D = a serial already authorized
        // somewhere (moved or re-plugged), New = never seen. Resync = ONUs whose OLT setup no longer
        // matches what SmartOLT saved (see mismatches()).
        $r['waiting'] = $r['moved'] = $r['new'] = 0;
        $known = $this->knownSerials();
        foreach ($this->waiting($oltId) as $list) {
            foreach ($list['onus'] as $u) {
                $r['waiting']++;
                isset($known[$u['sn']]) ? $r['moved']++ : $r['new']++;
            }
        }
        $r['resync'] = count($this->mismatches($oltId));
        return $r;
    }

    /**
     * Serials SmartOLT has seen authorized: on an OLT now, or authorized or deleted through SmartOLT
     * before (the action log keeps "F/S/P SN" as the target). [sn => true].
     */
    public function knownSerials(): array
    {
        $known = array_flip($this->db->query('SELECT sn FROM onus')->fetchAll(PDO::FETCH_COLUMN));
        foreach ($this->db->query("SELECT target FROM actions WHERE action IN ('onu.authorize', 'onu.delete', 'onu.replace_sn') AND ok=1")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            if (preg_match_all('/\b([0-9A-F]{16})\b/', $t, $m)) foreach ($m[1] as $sn) $known[$sn] = true;
        }
        return $known;
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

    /**
     * Validate and save. Returns the OLT id or throws InvalidArgumentException with a message for the user.
     * conn_type 'direct': host and telnet_port reach the OLT from the internet. conn_type 'vpn': the OLT
     * sits at lan_ip:lan_port behind the ISP's MikroTik, which dials the SmartOLT VPN; host and port
     * become the router's tunnel IP and VPN_NAT_PORT.
     */
    public function saveOlt(array $in): int
    {
        $name = trim((string)($in['name'] ?? ''));
        $vpn = ($in['conn_type'] ?? 'direct') === 'vpn';
        $host = trim((string)($in['host'] ?? ''));
        $port = (int)($in['telnet_port'] ?? 23);
        $lanIp = trim((string)($in['lan_ip'] ?? ''));
        $lanPort = (int)($in['lan_port'] ?? 23);
        $user = trim((string)($in['username'] ?? ''));
        $pass = (string)($in['password'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) throw new InvalidArgumentException('Give the OLT a name.');
        if ($vpn) {
            if (!filter_var($lanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new InvalidArgumentException('Enter the OLT\'s own IP in your network, for example 192.168.100.1.');
            if ($lanPort < 1 || $lanPort > 65535) throw new InvalidArgumentException('The Telnet port must be between 1 and 65535.');
        } else {
            if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^(?=.{1,190}$)([a-z0-9-]+\.)+[a-z]{2,}$/i', $host)) throw new InvalidArgumentException('Enter the OLT address as an IP or a host name.');
            if ($port < 1 || $port > 65535) throw new InvalidArgumentException('The Telnet port must be between 1 and 65535.');
        }
        if ($user === '') throw new InvalidArgumentException('Enter the OLT username.');
        $now = self::now();
        $olt = !empty($in['id']) ? $this->olt((int)$in['id']) : null;
        if (!empty($in['id']) && !$olt) throw new InvalidArgumentException('OLT not found.');
        if (!$olt && $pass === '') throw new InvalidArgumentException('Enter the OLT password.');
        $snmpEnabled = array_key_exists('snmp_enabled', $in) ? !empty($in['snmp_enabled']) : !empty($olt['snmp_enabled']);
        $snmpPort = (int)($in['snmp_port'] ?? $olt['snmp_port'] ?? 161);
        $community = (string)($in['snmp_community'] ?? '');
        if ($snmpPort < 1 || $snmpPort > 65535) throw new InvalidArgumentException('The SNMP UDP port must be between 1 and 65535.');
        if (strlen($community) > 255 || preg_match('/[\x00-\x1f\x7f]/', $community)) throw new InvalidArgumentException('The SNMP community must be at most 255 bytes without control characters.');
        $snmpEnc = $community !== '' ? $this->encrypt($community) : ($olt['snmp_community_enc'] ?? null);
        if ($snmpEnabled && !$snmpEnc) throw new InvalidArgumentException('Enter the read-only SNMP community configured on the OLT.');
        $enc = $pass !== '' ? $this->encrypt($pass) : $olt['password_enc'];

        if ($olt) {
            $id = (int)$olt['id'];
            $this->db->prepare('UPDATE olts SET name=?, username=?, password_enc=?, updated_at=? WHERE id=?')->execute([$name, $user, $enc, $now, $id]);
        } else {
            $this->db->prepare('INSERT INTO olts (name, host, telnet_port, username, password_enc, created_at, updated_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$name, $vpn ? '0.0.0.0' : $host, $vpn ? self::VPN_NAT_PORT : $port, $user, $enc, $now, $now]);
            $id = (int)$this->db->lastInsertId();
        }

        if ($vpn) {
            try {
                $this->vpnEnsure($id);
            } catch (Throwable $e) {
                if (!$olt) $this->db->prepare('DELETE FROM olts WHERE id=?')->execute([$id]);
                throw new InvalidArgumentException($e->getMessage());
            }
            $this->db->prepare("UPDATE olts SET conn_type='vpn', lan_ip=?, lan_port=? WHERE id=?")->execute([$lanIp, $lanPort, $id]);
        } else {
            if ($olt && $olt['conn_type'] === 'vpn') $this->vpnRemove($olt);
            $this->db->prepare("UPDATE olts SET conn_type='direct', host=?, telnet_port=?, vpn_user='', vpn_pass_enc=NULL, vpn_ip='', lan_ip='' WHERE id=?")
                ->execute([$host, $port, $id]);
        }
        $this->db->prepare('UPDATE olts SET snmp_enabled=?, snmp_port=?, snmp_community_enc=? WHERE id=?')
            ->execute([(int)$snmpEnabled, $snmpPort, $snmpEnc, $id]);
        if (!$snmpEnabled || !$olt || $snmpEnabled !== (bool)$olt['snmp_enabled'] || $community !== '' || $snmpPort !== (int)$olt['snmp_port'] || $host !== $olt['host'] || $lanIp !== $olt['lan_ip']) {
            $this->db->prepare('UPDATE olts SET snmp_status=?, snmp_error=\'\', snmp_checked_at=NULL, snmp_json=NULL WHERE id=?')
                ->execute([$snmpEnabled ? 'pending' : 'disabled', $id]);
        }
        return $id;
    }

    public function deleteOlt(int $id): void
    {
        $olt = $this->olt($id);
        if (!$olt) throw new InvalidArgumentException('OLT not found.');
        if ($olt['conn_type'] === 'vpn') {
            // Retain the row if revocation fails, so the operator can retry with its credentials intact.
            $this->vpnRemove($olt);
        }
        $this->db->prepare('DELETE FROM olts WHERE id=?')->execute([$id]);
        // Nothing left to watch on it, so its alarms end here.
        $this->db->prepare('UPDATE network_events SET resolved_at=?, updated_at=? WHERE olt_id=? AND resolved_at IS NULL')->execute([self::now(), self::now(), $id]);
        $this->log(null, 'olt.delete', $olt['name'], true, 'removed from SmartOLT, nothing changed on the OLT');
    }

    // ----- SmartOLT VPN -----

    // On the router, this port on the tunnel IP is forwarded to the OLT's Telnet port.
    const VPN_NAT_PORT = 2323;
    const VPN_HELPER = '/usr/local/sbin/smartolt-vpn';

    /** Run the root helper (vpn/smartolt-vpn) through sudo. Returns its output or throws. */
    private function vpnHelper(array $args): string
    {
        if (!is_file(self::VPN_HELPER)) {
            throw new RuntimeException('The SmartOLT VPN is not set up on this server yet.');
        }
        exec('sudo -n ' . self::VPN_HELPER . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            throw new RuntimeException('The SmartOLT VPN did not accept the change: ' . trim(implode(' ', $out)));
        }
        return trim(implode("\n", $out));
    }

    public function vpnUsername(int $oltId): string
    {
        $sub = strtolower($this->config->getSubdomain());
        $name = $sub . '-olt' . $oltId;
        // RouterOS OVPN usernames are limited to 27 characters. Keep ordinary names readable.
        return strlen($name) <= 27 ? $name : 's' . substr(hash('sha256', $sub), 0, 12) . '-o' . $oltId;
    }

    /** Make sure this OLT has its VPN login and fixed tunnel IP; keeps an existing password. */
    public function vpnEnsure(int $id): array
    {
        $olt = $this->olt($id);
        $pass = $olt['vpn_pass_enc'] ? $this->decrypt($olt['vpn_pass_enc']) : bin2hex(random_bytes(10));
        $user = $olt['vpn_user'] ?: $this->vpnUsername($id);
        $ip = $this->vpnHelper(['add', $user, hash('sha256', $pass)]);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('The SmartOLT VPN gave no tunnel IP.');
        $this->db->prepare('UPDATE olts SET vpn_user=?, vpn_pass_enc=?, vpn_ip=?, host=?, telnet_port=? WHERE id=?')
            ->execute([$user, $this->encrypt($pass), $ip, $ip, self::VPN_NAT_PORT, $id]);
        return ['user' => $user, 'ip' => $ip];
    }

    private function vpnRemove(array $olt): void
    {
        if ($olt['vpn_user'] !== '') $this->vpnHelper(['remove', $olt['vpn_user']]);
    }

    /** ['connected' => bool, 'from' => router's public IP, 'since' => time] for a VPN OLT. */
    public function vpnStatus(array $olt): array
    {
        if ($olt['conn_type'] !== 'vpn' || $olt['vpn_user'] === '') return ['connected' => false, 'from' => '', 'since' => ''];
        try {
            $s = explode(' ', $this->vpnHelper(['status', $olt['vpn_user']]), 3);
        } catch (Throwable $e) {
            return ['connected' => false, 'from' => '', 'since' => '', 'error' => $e->getMessage()];
        }
        return ['connected' => $s[0] === 'connected', 'from' => $s[1] ?? '', 'since' => $s[2] ?? ''];
    }

    /** The VPN server routers dial: this site's own host name, which points at the SmartOLT box. */
    public function vpnServer(): string
    {
        return (string)(parse_url($this->config->getAppUrl(), PHP_URL_HOST) ?: '');
    }

    /**
     * RouterOS commands that connect the ISP's MikroTik to the SmartOLT VPN and forward the OLT's
     * Telnet. One block, safe to paste again: it replaces what an earlier paste made.
     */
    public function mikrotikScript(array $olt): string
    {
        if ($olt['conn_type'] !== 'vpn' || !$olt['vpn_pass_enc']) return '';
        require_once __DIR__ . '/Mikrotik.php';
        return Mikrotik::script($olt, $this->vpnServer(), $this->decrypt($olt['vpn_pass_enc']));
    }

    // ----- Talking to the OLT -----

    /**
     * Run $fn with a logged in session while holding that OLT's lock, so the 5 minute read and a
     * person's action never talk to the same OLT at once (Huawei allows only a few Telnet users).
     */
    public function withOlt(array $olt, callable $fn, int $wait = 30)
    {
        $name = $this->db->quote('smartolt_' . substr(hash('sha256', $this->config->getSubdomain()), 0, 32) . '_' . (int)$olt['id']);
        if ((int)$this->db->query("SELECT GET_LOCK({$name}, " . (int)$wait . ')')->fetchColumn() !== 1) {
            throw new OltError('The OLT is busy with another task. Try again in a moment.');
        }
        $s = null;
        try {
            if (($olt['conn_type'] ?? 'direct') === 'vpn' && !$this->vpnStatus($olt)['connected']) {
                throw new OltError('Your MikroTik is not connected to the SmartOLT VPN yet. Paste the commands shown on this OLT\'s page into its terminal, then try again.');
            }
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
        $this->db->prepare('INSERT INTO actions (olt_id, action, target, ok, message, source, user, ip, transcript, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$oltId, $action, mb_substr($target, 0, 100), $ok ? 1 : 0, mb_substr($message, 0, 500), $this->source,
                mb_substr($this->user, 0, 60), $this->ip, $this->lastTranscript, self::now()]);
        $this->lastTranscript = null;
    }

    /** Read SNMP independently of Telnet. Keep the last successful sample clearly dated on failure. */
    public function pollSnmp(array $olt): array
    {
        if (empty($olt['snmp_enabled'])) return ['status' => 'disabled', 'error' => 'Enable SNMP in the OLT settings first.'];
        $lock = $this->db->quote('smartolt_snmp_' . substr(hash('sha256', $this->config->getSubdomain()), 0, 32) . '_' . (int)$olt['id']);
        if (!(int)$this->db->query("SELECT GET_LOCK({$lock}, 0)")->fetchColumn()) return ['status' => 'busy', 'error' => 'SNMP is already being read.'];
        try {
            $olt = $this->olt((int)$olt['id']);
            if (!$olt || empty($olt['snmp_enabled'])) return ['status' => 'disabled'];
            $old = json_decode((string)$olt['snmp_json'], true) ?: [];
            try {
                if ($olt['conn_type'] === 'vpn' && !$this->vpnStatus($olt)['connected']) throw new SnmpError('The MikroTik VPN is not connected.');
                $client = new SnmpClient($olt['host'], $olt['conn_type'] === 'vpn' ? 2161 : (int)$olt['snmp_port'], $this->decrypt($olt['snmp_community_enc']));
                $sample = ['system' => $client->systemInfo(), 'interfaces' => []];
                try { $sample['interfaces'] = $client->interfaces(); }
                catch (SnmpError $e) { $sample['interface_error'] = $e->getMessage(); }
                $sample['sampled_at'] = self::now();
                $sample['timestamp'] = microtime(true);
                $elapsed = $sample['timestamp'] - (float)($old['timestamp'] ?? 0);
                $restarted = !isset($old['system']['uptime_ticks']) || $sample['system']['uptime_ticks'] < $old['system']['uptime_ticks'];
                $prior = array_column($old['interfaces'] ?? [], null, 'index');
                $insert = $this->db->prepare('INSERT INTO snmp_samples (olt_id, if_index, at, rx_bps, tx_bps) VALUES (?,?,?,?,?)');
                foreach ($sample['interfaces'] as &$iface) {
                    $iface += SnmpRates::calculate($iface, $prior[$iface['index']] ?? [], $elapsed, $restarted);
                    $insert->execute([$olt['id'], $iface['index'], $sample['sampled_at'], $iface['rx_bps'], $iface['tx_bps']]);
                }
                unset($iface);
                $status = isset($sample['interface_error']) ? 'partial' : 'online';
                $error = $sample['interface_error'] ?? '';
                $json = json_encode($sample, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                $this->db->prepare('UPDATE olts SET snmp_status=?, snmp_error=?, snmp_checked_at=?, snmp_json=? WHERE id=?')
                    ->execute([$status, mb_substr($error, 0, 500), self::now(), $json, $olt['id']]);
                return ['status' => $status, 'error' => $error] + $sample;
            } catch (Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 500);
                $this->db->prepare("UPDATE olts SET snmp_status='error', snmp_error=?, snmp_checked_at=? WHERE id=?")
                    ->execute([$error, self::now(), $olt['id']]);
                return ['status' => 'error', 'error' => $error];
            }
        } finally {
            $this->db->query("SELECT RELEASE_LOCK({$lock})");
        }
    }

    public function snmpHistory(int $oltId, int $hours = 24): array
    {
        $st = $this->db->prepare('SELECT if_index, at, rx_bps, tx_bps FROM snmp_samples WHERE olt_id=? AND at>=? ORDER BY at, id');
        $st->execute([$oltId, date('Y-m-d H:i:s', time() - max(1, min(720, $hours)) * 3600)]);
        return $st->fetchAll();
    }

    /** Configuration can contain credentials; never retain it in the activity transcript. */
    public function readConfigBackup(int $id): string
    {
        $olt = $this->olt($id);
        if (!$olt) throw new InvalidArgumentException('OLT not found.');
        try {
            $output = $this->withOlt($olt, function (Huawei $s) {
                return $s->displayCommand('display current-configuration');
            });
            if (($error = Huawei::findError($output)) !== null) throw new OltError($error);
            if (trim($output) === '' || strlen($output) < 40) throw new OltError('The OLT returned an empty or incomplete configuration.');
            return $output;
        } finally {
            $this->lastTranscript = null;
        }
    }

    /**
     * Read boards, profiles, speed tables, every ONT with its state, down cause and light levels, and
     * the ONUs waiting for authorization; store it all with history. Returns a summary.
     */
    public function refresh(array $olt, bool $withSignal = true): array
    {
        // SNMP has its own health: a failed CLI login must not hide a working monitoring path.
        if (!empty($olt['snmp_enabled'])) $this->pollSnmp($olt);
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
            if (!$e instanceof OltError || stripos($e->getMessage(), 'busy') === false) {
                $this->openEvent((int)$olt['id'], 'olt', 'olt_unreachable:' . $olt['id'], 'critical', 'OLT unreachable', "OLT {$olt['name']} does not respond: " . $e->getMessage());
            }
            throw $e;
        }
        $this->resolveEvent('olt_unreachable:' . $olt['id']);

        $info = $data['info'];
        $now = self::now();
        $this->db->prepare("UPDATE olts SET status='online', last_error='', model=?, version=?, boards_json=?, profiles_json=?, traffic_json=?, uptime=?, temperature=?, last_polled_at=?, updated_at=? WHERE id=?")
            ->execute([$info['model'], $info['version'], json_encode($info['boards']),
                json_encode(['line' => $info['line_profiles'], 'srv' => $info['srv_profiles'], 'vlans' => $info['vlans']]),
                json_encode($info['traffic']), $info['uptime'], $info['temperature'], $now, $now, $olt['id']]);
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
        $serviceCount = [];
        foreach ($data['services'] as $sp) {
            $k = "{$sp['frame']}/{$sp['slot']}/{$sp['port']}/{$sp['ont_id']}";
            if (!isset($service[$k]) || $sp['index'] < $service[$k]['index']) $service[$k] = $sp;
            $serviceCount[$k] = ($serviceCount[$k] ?? 0) + 1;
        }
        $profileByRate = [];
        foreach ($this->speedProfiles() as $p) $profileByRate["{$p['down_kbps']}/{$p['up_kbps']}"] = (int)$p['id'];
        $svc = $this->db->prepare('UPDATE onus SET vlan=?, gemport=?, speed_profile_id=COALESCE(?, speed_profile_id), olt_down_kbps=?, olt_up_kbps=? WHERE olt_id=? AND frame=? AND slot=? AND port=? AND ont_id=?');
        $svcCount = $this->db->prepare('UPDATE onus SET service_ports=? WHERE olt_id=? AND frame=? AND slot=? AND port=? AND ont_id=?');
        $perPon = [];

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
                $svc->execute(array_merge([$sp['vlan'], $sp['gemport'], $profileByRate["{$down}/{$upk}"] ?? null, $down, $upk], $pos));
            }
            // Only trusted when the OLT listed service ports at all: an unparsed table must not flag every ONU.
            if ($data['services']) $svcCount->execute(array_merge([$serviceCount[$k] ?? 0], $pos));
            $perPon[$pk]['total'] = ($perPon[$pk]['total'] ?? 0) + 1;
            $perPon[$pk]['online'] = ($perPon[$pk]['online'] ?? 0) + ($group === 'online' ? 1 : 0);

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
        $ponStat = $this->db->prepare('INSERT INTO pon_stats (olt_id, pon, at, total, online) VALUES (?,?,?,?,?)');
        foreach ($perPon as $pon => $p) $ponStat->execute([$olt['id'], $pon, $now, $p['total'], $p['online']]);
        $this->checkEvents($olt, $info);
        return ['onus' => $stats['total'], 'online' => $stats['online'], 'removed' => $removed, 'model' => $info['model'], 'waiting' => count($data['waiting'])];
    }

    /** History older than this is not kept: signal and network points 30 days, status changes 180 days. */
    public function prune(): void
    {
        $this->db->exec('DELETE FROM snmp_samples WHERE at < NOW() - INTERVAL 30 DAY');
        $this->db->exec('DELETE FROM signal_history WHERE at < NOW() - INTERVAL 30 DAY');
        $this->db->exec('DELETE FROM olt_stats WHERE at < NOW() - INTERVAL 30 DAY');
        $this->db->exec('DELETE FROM pon_stats WHERE at < NOW() - INTERVAL 30 DAY');
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
        foreach (['zone_id' => 'zones', 'odb_id' => 'odbs', 'onu_type_id' => 'onu_types', 'speed_profile_id' => 'speed_profiles', 'sector_id' => 'sectors', 'preset_id' => 'presets'] as $k => $table) {
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
        if (array_key_exists('contact', $in)) $m['contact'] = mb_substr(trim((string)$in['contact']), 0, 100);
        if (array_key_exists('vip', $in)) $m['vip'] = empty($in['vip']) ? 0 : 1;
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
        $this->db->prepare('DELETE FROM pending_onus WHERE sn=?')->execute([$sn]);
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
        $meta = $this->metaFromInput(array_intersect_key($in, array_flip(['zone_id', 'sector_id', 'lat', 'lng'])));
        $row = ['name' => self::cleanName((string)($in['name'] ?? ''), 'splitter'), 'zone_id' => $meta['zone_id'] ?? null,
            'sector_id' => $meta['sector_id'] ?? null, 'ports' => $ports,
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
            'wifi' => max(0, min(8, (int)($in['wifi'] ?? 0))), 'catv' => empty($in['catv']) ? 0 : 1,
            'capability' => ($in['capability'] ?? '') === 'bridge' ? 'bridge' : 'both', 'allow_custom' => empty($in['allow_custom']) ? 0 : 1,
            'default_mode' => ($in['default_mode'] ?? '') === 'router' && ($in['capability'] ?? '') !== 'bridge' ? 'router' : 'bridge']);
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
        $map = ['zone' => ['zones', 'zone_id'], 'odb' => ['odbs', 'odb_id'], 'type' => ['onu_types', 'onu_type_id'],
            'speed' => ['speed_profiles', 'speed_profile_id'], 'sector' => ['sectors', 'sector_id']];
        if ($kind === 'preset') {
            $this->db->prepare('DELETE FROM auto_actions WHERE preset_id=?')->execute([$id]);
            $this->db->prepare('UPDATE pending_onus SET preset_id=NULL WHERE preset_id=?')->execute([$id]);
            $this->db->prepare('DELETE FROM presets WHERE id=?')->execute([$id]);
            return;
        }
        if (!isset($map[$kind])) throw new InvalidArgumentException('Unknown list.');
        [$table, $col] = $map[$kind];
        if ($kind === 'speed') {
            $st = $this->db->prepare('SELECT COUNT(*) FROM onus WHERE speed_profile_id=?');
            $st->execute([$id]);
            if ($n = (int)$st->fetchColumn()) throw new InvalidArgumentException("{$n} ONUs use this speed. Move them to another speed first.");
        }
        $this->db->prepare("UPDATE onus SET `{$col}`=NULL WHERE `{$col}`=?")->execute([$id]);
        if ($kind === 'zone' || $kind === 'sector') $this->db->prepare("UPDATE odbs SET `{$col}`=NULL WHERE `{$col}`=?")->execute([$id]);
        foreach (['zone' => 'zone_id', 'sector' => 'sector_id', 'type' => 'onu_type_id', 'speed' => 'speed_profile_id'] as $k => $c) {
            if ($k === $kind) $this->db->prepare("UPDATE presets SET `{$c}`=NULL WHERE `{$c}`=?")->execute([$id]);
        }
        $this->db->prepare("DELETE FROM `{$table}` WHERE id=?")->execute([$id]);
    }

    /** Remove every zone, sector, splitter, type or speed profile no ONU uses. Returns how many went. */
    public function deleteUnused(string $kind): int
    {
        $rows = ['zone' => $this->zones(), 'sector' => $this->sectors(), 'odb' => $this->odbs(), 'type' => $this->onuTypes(), 'speed' => $this->speedProfiles()][$kind] ?? null;
        if ($rows === null) throw new InvalidArgumentException('Unknown list.');
        $n = 0;
        foreach ($rows as $r) {
            if ((int)$r['onu_count'] === 0) { $this->deleteCatalog($kind, (int)$r['id']); $n++; }
        }
        return $n;
    }

    // ----- Sectors -----

    public function sectors(): array
    {
        return $this->db->query('SELECT s.*, (SELECT COUNT(*) FROM onus u WHERE u.sector_id=s.id) AS onu_count,
            (SELECT COUNT(*) FROM odbs d WHERE d.sector_id=s.id) AS odb_count FROM sectors s ORDER BY s.name')->fetchAll();
    }

    public function saveSector(array $in): int
    {
        $id = (int)($in['id'] ?? 0) ?: null;
        $row = ['name' => self::cleanName((string)($in['name'] ?? ''), 'sector'), 'comment' => mb_substr(trim((string)($in['comment'] ?? '')), 0, 255)];
        if (!$id) $row['created_at'] = self::now();
        return $this->saveRow('sectors', $id, $row);
    }

    // ----- Authorization presets -----

    public function presets(): array
    {
        return $this->db->query('SELECT p.*, t.name AS type_name, s.name AS speed_name, z.name AS zone_name, c.name AS sector_name
            FROM presets p LEFT JOIN onu_types t ON t.id=p.onu_type_id LEFT JOIN speed_profiles s ON s.id=p.speed_profile_id
            LEFT JOIN zones z ON z.id=p.zone_id LEFT JOIN sectors c ON c.id=p.sector_id ORDER BY p.name')->fetchAll();
    }

    public function preset(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM presets WHERE id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function savePreset(array $in): int
    {
        $id = (int)($in['id'] ?? 0) ?: null;
        $meta = $this->metaFromInput(array_intersect_key($in, array_flip(['onu_type_id', 'speed_profile_id', 'zone_id', 'sector_id'])));
        $vlan = trim((string)($in['vlan'] ?? '')) === '' ? null : (int)$in['vlan'];
        if ($vlan !== null && ($vlan < 1 || $vlan > 4094)) throw new InvalidArgumentException('The VLAN must be between 1 and 4094.');
        if (!empty($meta['speed_profile_id']) && $vlan === null) throw new InvalidArgumentException('A speed limit needs an internet VLAN.');
        $prof = function ($v) { return trim((string)$v) === '' ? null : max(0, (int)$v); };
        $row = ['name' => self::cleanName((string)($in['name'] ?? ''), 'preset'), 'mode' => ($in['mode'] ?? '') === 'router' ? 'router' : 'bridge',
            'vlan' => $vlan, 'onu_type_id' => $meta['onu_type_id'] ?? null, 'speed_profile_id' => $meta['speed_profile_id'] ?? null,
            'zone_id' => $meta['zone_id'] ?? null, 'sector_id' => $meta['sector_id'] ?? null,
            'line_profile' => $prof($in['line_profile'] ?? ''), 'srv_profile' => $prof($in['srv_profile'] ?? ''),
            'gemport' => max(0, min(1023, (int)($in['gemport'] ?? 1)))];
        if (!$id) $row['created_at'] = self::now();
        return $this->saveRow('presets', $id, $row);
    }

    /** The line and service profile most ONUs on this OLT already use; profile 0 rarely carries internet. */
    public function defaultProfiles(array $olt): array
    {
        $prof = self::publicOlt($olt)['profiles'];
        $busiest = function (array $ps) {
            usort($ps, function ($a, $b) { return $b['bindings'] <=> $a['bindings']; });
            return $ps[0]['id'] ?? null;
        };
        return [$busiest($prof['line']), $busiest($prof['srv'])];
    }

    /** authorize() input for one waiting ONU from a preset (may be null) and extra details. */
    private function presetInput(array $olt, array $found, ?array $preset, array $extra = []): array
    {
        [$line, $srv] = $this->defaultProfiles($olt);
        $in = [
            'olt_id' => $olt['id'], 'sn' => $found['sn'], 'frame' => $found['frame'], 'slot' => $found['slot'], 'port' => $found['port'],
            'model' => $found['equipment'] ?? '', 'line_profile' => $preset['line_profile'] ?? $line, 'srv_profile' => $preset['srv_profile'] ?? $srv,
            'vlan' => $preset['vlan'] ?? '', 'gemport' => $preset['gemport'] ?? 1, 'mode' => $preset['mode'] ?? 'bridge',
            'onu_type_id' => $preset['onu_type_id'] ?? $this->typeForModel($found['equipment'] ?? '') ?? 0,
            'speed_profile_id' => $preset['speed_profile_id'] ?? 0, 'zone_id' => $preset['zone_id'] ?? 0, 'sector_id' => $preset['sector_id'] ?? 0,
            'preset_id' => $preset['id'] ?? 0,
        ];
        foreach ($extra as $k => $v) {
            if ($v !== null && $v !== '' && $v !== 0) $in[$k] = $v;
        }
        if ($in['line_profile'] === null || $in['srv_profile'] === null) throw new InvalidArgumentException('the OLT profiles are not read yet');
        return $in;
    }

    // ----- Auto actions and ONUs added for later authorization -----

    public function autoActionsOn(): bool
    {
        return $this->db->query("SELECT v FROM meta WHERE k='auto_actions'")->fetchColumn() !== 'off';
    }

    public function setAutoActionsOn(bool $on): void
    {
        $this->db->prepare("REPLACE INTO meta (k, v) VALUES ('auto_actions', ?)")->execute([$on ? 'on' : 'off']);
    }

    public function autoActions(): array
    {
        return $this->db->query('SELECT a.*, o.name AS olt_name, t.name AS type_name, p.name AS preset_name FROM auto_actions a
            LEFT JOIN olts o ON o.id=a.olt_id LEFT JOIN onu_types t ON t.id=a.onu_type_id JOIN presets p ON p.id=a.preset_id ORDER BY a.id')->fetchAll();
    }

    public function saveAutoAction(array $in): int
    {
        if (!$this->preset((int)($in['preset_id'] ?? 0))) throw new InvalidArgumentException('Choose the preset the ONUs are authorized with.');
        $oltId = (int)($in['olt_id'] ?? 0);
        if ($oltId && !$this->olt($oltId)) throw new InvalidArgumentException('OLT not found.');
        $pon = trim((string)($in['pon'] ?? ''));
        if ($pon !== '' && !preg_match('#^\d+/\d+/\d+$#', $pon)) throw new InvalidArgumentException('Write the PON port as frame/board/port, for example 0/1/0.');
        $meta = $this->metaFromInput(['onu_type_id' => $in['onu_type_id'] ?? 0]);
        $id = (int)($in['id'] ?? 0) ?: null;
        $row = ['olt_id' => $oltId ?: null, 'pon' => $pon, 'onu_type_id' => $meta['onu_type_id'], 'preset_id' => (int)$in['preset_id'], 'enabled' => empty($in['enabled']) ? 0 : 1];
        if (!$id) $row['created_at'] = self::now();
        return $this->saveRow('auto_actions', $id, $row);
    }

    public function deleteAutoAction(int $id): void
    {
        $this->db->prepare('DELETE FROM auto_actions WHERE id=?')->execute([$id]);
    }

    public function pendingOnus(): array
    {
        return $this->db->query('SELECT p.*, o.name AS olt_name, s.name AS preset_name, z.name AS zone_name, d.name AS odb_name
            FROM pending_onus p LEFT JOIN olts o ON o.id=p.olt_id LEFT JOIN presets s ON s.id=p.preset_id
            LEFT JOIN zones z ON z.id=p.zone_id LEFT JOIN odbs d ON d.id=p.odb_id ORDER BY p.id DESC')->fetchAll();
    }

    /** An ONU the ISP knows is coming: it is authorized with these details the first time an OLT sees it. */
    public function savePending(array $in): int
    {
        $sn = Huawei::normaliseSn((string)($in['sn'] ?? ''));
        if ($sn === null) throw new InvalidArgumentException('That serial number does not look right.');
        $st = $this->db->prepare('SELECT COUNT(*) FROM onus WHERE sn=?');
        $st->execute([$sn]);
        if ((int)$st->fetchColumn()) throw new InvalidArgumentException('That ONU is already authorized.');
        $oltId = (int)($in['olt_id'] ?? 0);
        if ($oltId && !$this->olt($oltId)) throw new InvalidArgumentException('OLT not found.');
        $presetId = (int)($in['preset_id'] ?? 0);
        if ($presetId && !$this->preset($presetId)) throw new InvalidArgumentException('That preset no longer exists.');
        $meta = $this->metaFromInput(array_intersect_key($in, array_flip(['zone_id', 'sector_id', 'odb_id', 'odb_port', 'address', 'customer_ref', 'onu_type_id', 'speed_profile_id', 'lat', 'lng'])));
        $num = function ($k, $max) use ($in) { $v = trim((string)($in[$k] ?? '')); if ($v === '') return null; if (!ctype_digit($v) || (int)$v > $max) throw new InvalidArgumentException("Check the {$k}."); return (int)$v; };
        $vlan = $num('vlan', 4094);
        if (!empty($meta['speed_profile_id']) && $vlan === null && !$presetId) throw new InvalidArgumentException('A speed limit needs an internet VLAN.');
        $row = array_merge(['sn' => $sn, 'olt_id' => $oltId ?: null, 'preset_id' => $presetId ?: null,
            'slot' => $num('slot', 21), 'port' => $num('port', 15), 'vlan' => $vlan, 'mode' => in_array($in['mode'] ?? '', ['router', 'bridge'], true) ? $in['mode'] : '',
            'description' => self::cleanDesc((string)($in['description'] ?? '')), 'last_error' => '', 'created_at' => self::now()], $meta);
        $this->db->prepare('DELETE FROM pending_onus WHERE sn=?')->execute([$sn]);
        return $this->saveRow('pending_onus', null, $row);
    }

    public function deletePending(int $id): void
    {
        $this->db->prepare('DELETE FROM pending_onus WHERE id=?')->execute([$id]);
    }

    /**
     * Authorize waiting ONUs on this OLT that were added for later authorization or match an auto
     * action. Uses the list from the last read. A failure is not retried for an hour, so a bad preset
     * does not hammer the OLT every 5 minutes. Returns ['done' => n, 'failed' => [msg, ...]].
     */
    public function runAuto(array $olt): array
    {
        $result = ['done' => 0, 'failed' => []];
        $waiting = $this->waiting((int)$olt['id'])[(int)$olt['id']]['onus'] ?? [];
        if (!$waiting) return $result;
        $pending = [];
        foreach ($this->db->query('SELECT * FROM pending_onus')->fetchAll() as $p) $pending[$p['sn']] = $p;
        $rules = $this->autoActionsOn() ? array_filter($this->autoActions(), function ($a) { return (int)$a['enabled'] === 1; }) : [];
        if (!$pending && !$rules) return $result;

        $source = $this->source;
        $this->source = 'auto';
        try {
            foreach ($waiting as $u) {
                $pon = "{$u['frame']}/{$u['slot']}/{$u['port']}";
                $p = $pending[$u['sn']] ?? null;
                if ($p && $p['olt_id'] !== null && (int)$p['olt_id'] !== (int)$olt['id']) $p = null;
                if ($p && (($p['slot'] !== null && (int)$p['slot'] !== (int)$u['slot']) || ($p['port'] !== null && (int)$p['port'] !== (int)$u['port']))) $p = null;
                $rule = null;
                if (!$p) {
                    $type = $this->typeForModel($u['equipment'] ?? '');
                    foreach ($rules as $a) {
                        if (($a['olt_id'] === null || (int)$a['olt_id'] === (int)$olt['id']) && ($a['pon'] === '' || $a['pon'] === $pon)
                            && ($a['onu_type_id'] === null || (int)$a['onu_type_id'] === (int)$type)) { $rule = $a; break; }
                    }
                    if (!$rule) continue;
                }
                $failKey = 'autofail_' . $u['sn'];
                $st = $this->db->prepare('SELECT v FROM meta WHERE k=?');
                $st->execute([$failKey]);
                if (($t = $st->fetchColumn()) && time() - (int)$t < 3600) continue;
                try {
                    if ($p) {
                        $in = $this->presetInput($olt, $u, $p['preset_id'] ? $this->preset((int)$p['preset_id']) : null, [
                            'description' => $p['description'], 'customer_ref' => $p['customer_ref'], 'zone_id' => $p['zone_id'], 'sector_id' => $p['sector_id'],
                            'odb_id' => $p['odb_id'], 'odb_port' => $p['odb_port'], 'address' => $p['address'],
                            'onu_type_id' => $p['onu_type_id'], 'mode' => $p['mode'], 'vlan' => $p['vlan'], 'speed_profile_id' => $p['speed_profile_id'],
                            'lat' => $p['lat'], 'lng' => $p['lng']]);
                    } else {
                        $in = $this->presetInput($olt, $u, $this->preset((int)$rule['preset_id']));
                    }
                    $this->authorize($in);
                    if ($p) $this->deletePending((int)$p['id']);
                    $result['done']++;
                } catch (Throwable $e) {
                    $this->db->prepare('REPLACE INTO meta (k, v) VALUES (?, ?)')->execute([$failKey, (string)time()]);
                    if ($p) $this->db->prepare('UPDATE pending_onus SET last_error=? WHERE id=?')->execute([mb_substr($e->getMessage(), 0, 500), $p['id']]);
                    $result['failed'][] = "{$u['sn']}: {$e->getMessage()}";
                }
            }
        } finally {
            $this->source = $source;
        }
        return $result;
    }

    // ----- OLT wide actions -----

    /** Save the running config to the OLT's flash so it survives a reboot. */
    public function saveConfig(int $oltId): void
    {
        $olt = $this->olt($oltId);
        if (!$olt) throw new InvalidArgumentException('OLT not found.');
        $this->act($olt, 'olt.save', $olt['name'], function (Huawei $s) {
            $s->saveConfig();
        });
        $this->db->prepare('UPDATE olts SET saved_at=? WHERE id=?')->execute([self::now(), $oltId]);
    }

    public function diag(int $oltId, string $key, array $params): string
    {
        $olt = $this->olt($oltId);
        if (!$olt) throw new InvalidArgumentException('OLT not found.');
        if (!isset(Huawei::DIAG[$key])) throw new InvalidArgumentException('Choose what to check.');
        return $this->act($olt, 'olt.diag', Huawei::DIAG[$key][0], function (Huawei $s) use ($key, $params) {
            return $s->diag($key, $params);
        });
    }

    public function cli(int $oltId, string $cmd): string
    {
        $olt = $this->olt($oltId);
        if (!$olt) throw new InvalidArgumentException('OLT not found.');
        if (!preg_match('/^\s*display\s/i', $cmd)) throw new InvalidArgumentException('Only display commands can be run here, for example: display board 0');
        return $this->act($olt, 'olt.cli', mb_substr(trim($cmd), 0, 100), function (Huawei $s) use ($cmd) {
            return $s->displayCommand($cmd);
        });
    }

    public function runningConfig(int $id): string
    {
        [$onu, $olt, $pos] = $this->onuAndOlt($id);
        return $this->act($olt, 'onu.config', $pos, function (Huawei $s) use ($onu) {
            return $s->runningConfig($onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id']);
        });
    }

    public function swInfo(int $id): array
    {
        [$onu, $olt, $pos] = $this->onuAndOlt($id);
        return $this->act($olt, 'onu.sw_info', $pos, function (Huawei $s) use ($onu) {
            return $s->ontVersion($onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id']);
        });
    }

    public function restoreDefaults(int $id): void
    {
        [$onu, $olt, $pos] = $this->onuAndOlt($id);
        $this->act($olt, 'onu.restore', $pos, function (Huawei $s) use ($onu) {
            $s->restoreDefaults($onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id']);
        });
    }

    /** Add or remove a VLAN on the OLT and keep the stored VLAN list in step. */
    public function setVlan(int $oltId, int $vlan, bool $add, string $desc = ''): void
    {
        $olt = $this->olt($oltId);
        if (!$olt) throw new InvalidArgumentException('OLT not found.');
        if ($vlan < 2 || $vlan > 4094) throw new InvalidArgumentException('The VLAN must be between 2 and 4094.');
        $desc = preg_replace('/[^A-Za-z0-9 _.-]/', '', mb_substr(trim($desc), 0, 30));
        if (!$add) {
            $st = $this->db->prepare('SELECT COUNT(*) FROM onus WHERE olt_id=? AND vlan=?');
            $st->execute([$oltId, $vlan]);
            if ($n = (int)$st->fetchColumn()) throw new InvalidArgumentException("{$n} ONUs use VLAN {$vlan}. Move them to another VLAN first.");
        }
        $this->act($olt, $add ? 'olt.vlan_add' : 'olt.vlan_delete', "VLAN {$vlan}", function (Huawei $s) use ($vlan, $add, $desc) {
            $add ? $s->addVlan($vlan, $desc) : $s->deleteVlan($vlan);
        });
        $prof = json_decode((string)$olt['profiles_json'], true) ?: ['line' => [], 'srv' => [], 'vlans' => []];
        $prof['vlans'] = array_values(array_filter($prof['vlans'] ?? [], function ($v) use ($vlan) { return (int)$v['id'] !== $vlan; }));
        if ($add) $prof['vlans'][] = ['id' => $vlan, 'type' => 'smart', 'attribute' => 'common', 'desc' => $desc];
        usort($prof['vlans'], function ($a, $b) { return $a['id'] <=> $b['id']; });
        $this->db->prepare('UPDATE olts SET profiles_json=? WHERE id=?')->execute([json_encode($prof), $oltId]);
    }

    /** Put the VLAN, mode and speed SmartOLT has saved back on the OLT. */
    public function resync(int $id): void
    {
        $onu = $this->onu($id);
        if (!$onu) throw new InvalidArgumentException('ONU not found.');
        if (!$onu['vlan']) throw new InvalidArgumentException('No internet VLAN is saved for this ONU. Set it under Service first.');
        $this->setService($id, ['vlan' => $onu['vlan'], 'mode' => $onu['mode'] ?: 'bridge', 'gemport' => $onu['gemport'], 'speed_profile_id' => $onu['speed_profile_id'] ?? 0]);
    }

    // ----- Network events: alarms that open when something goes wrong and resolve on their own -----

    const EVENT_TYPES = ['olt' => 'OLT', 'pon' => 'PON outage', 'signal' => 'PON signal', 'temp' => 'OLT temp'];

    public function openEvent(?int $oltId, string $type, string $key, string $severity, string $title, string $message): void
    {
        $st = $this->db->prepare('SELECT id FROM network_events WHERE ekey=? AND resolved_at IS NULL');
        $st->execute([$key]);
        if ($id = $st->fetchColumn()) {
            $this->db->prepare('UPDATE network_events SET message=?, updated_at=? WHERE id=?')->execute([mb_substr($message, 0, 500), self::now(), $id]);
            return;
        }
        // A muted kind of event stays muted when it opens again.
        $st = $this->db->prepare('SELECT COUNT(*) FROM network_events WHERE ekey=? AND muted=1');
        $st->execute([$key]);
        $muted = (int)$st->fetchColumn() > 0 ? 1 : 0;
        $this->db->prepare('INSERT INTO network_events (olt_id, type, ekey, severity, title, message, started_at, updated_at, muted) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$oltId, $type, $key, $severity, $title, mb_substr($message, 0, 500), self::now(), self::now(), $muted]);
    }

    public function resolveEvent(string $key): void
    {
        $this->db->prepare('UPDATE network_events SET resolved_at=?, updated_at=? WHERE ekey=? AND resolved_at IS NULL')->execute([self::now(), self::now(), $key]);
    }

    /** After a good read: PON ports gone dark, ports with many critical signals, a hot OLT. */
    private function checkEvents(array $olt, array $info): void
    {
        $id = (int)$olt['id'];
        $st = $this->db->prepare("SELECT frame, slot, port, COUNT(*) total, SUM(status_group='los') los, SUM(run_state<>'online') offline,
            SUM(rx_power IS NOT NULL AND (rx_power < ? OR rx_power > ?)) critical FROM onus WHERE olt_id=? GROUP BY frame, slot, port");
        $st->execute([self::SIGNAL_CRITICAL, self::SIGNAL_HOT, $id]);
        $open = [];
        foreach ($st->fetchAll() as $p) {
            $pon = "{$p['frame']}/{$p['slot']}/{$p['port']}";
            if ((int)$p['total'] >= 2 && (int)$p['offline'] === (int)$p['total'] && (int)$p['los'] > 0) {
                $this->openEvent($id, 'pon', "pon_outage:{$id}:{$pon}", 'critical', 'PON outage', "Every ONU on {$olt['name']} PON {$pon} lost signal ({$p['total']} ONUs), likely a feeder fiber cut");
                $open["pon_outage:{$id}:{$pon}"] = true;
            }
            if ((int)$p['critical'] >= 3) {
                $this->openEvent($id, 'signal', "pon_signal:{$id}:{$pon}", 'warning', 'PON signal', "{$p['critical']} ONUs on {$olt['name']} PON {$pon} have a critical signal");
                $open["pon_signal:{$id}:{$pon}"] = true;
            }
        }
        $temp = (int)($info['temperature'] ?? 0);
        if ($temp >= 70) {
            $this->openEvent($id, 'temp', "olt_temp:{$id}", $temp >= 80 ? 'critical' : 'warning', 'OLT temperature', "{$olt['name']} control board is at {$temp}°C");
            $open["olt_temp:{$id}"] = true;
        }
        $st = $this->db->prepare("SELECT ekey FROM network_events WHERE olt_id=? AND resolved_at IS NULL AND type IN ('pon','signal','temp')");
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $key) {
            if (empty($open[$key])) $this->resolveEvent($key);
        }
    }

    /** Events for the Events page: tab ongoing, muted, history or all; filters olt_id, q, from, to. */
    public function events(array $f, int $limit = 500): array
    {
        [$where, $args] = self::rangeWhere($f, 'e.started_at');
        $tab = $f['tab'] ?? 'ongoing';
        if ($tab === 'ongoing') $where[] = 'e.resolved_at IS NULL AND e.muted=0';
        elseif ($tab === 'muted') $where[] = 'e.muted=1';
        elseif ($tab === 'history') $where[] = 'e.resolved_at IS NOT NULL';
        if (!empty($f['olt_id'])) { $where[] = 'e.olt_id=?'; $args[] = (int)$f['olt_id']; }
        if (($q = trim((string)($f['q'] ?? ''))) !== '') {
            $where[] = '(e.title LIKE ? OR e.message LIKE ? OR o.name LIKE ?)';
            array_push($args, "%{$q}%", "%{$q}%", "%{$q}%");
        }
        $st = $this->db->prepare('SELECT e.*, o.name AS olt_name FROM network_events e LEFT JOIN olts o ON o.id=e.olt_id WHERE '
            . implode(' AND ', $where) . " ORDER BY e.resolved_at IS NULL DESC, e.severity='critical' DESC, e.id DESC LIMIT " . (int)$limit);
        $st->execute($args);
        return $st->fetchAll();
    }

    /** Counters over ongoing, unmuted events for the tiles on the Events page. */
    public function eventCounts(): array
    {
        $r = ['total' => 0, 'critical' => 0, 'warning' => 0, 'olt' => 0, 'pon' => 0, 'signal' => 0, 'temp' => 0];
        foreach ($this->db->query('SELECT type, severity FROM network_events WHERE resolved_at IS NULL AND muted=0')->fetchAll() as $e) {
            $r['total']++;
            $r[$e['severity'] === 'critical' ? 'critical' : 'warning']++;
            $r[$e['type']] = ($r[$e['type']] ?? 0) + 1;
        }
        return $r;
    }

    /** Mute one event, or every event of the same kind on the same thing ($similar). */
    public function muteEvent(int $id, bool $similar, bool $mute = true): void
    {
        $st = $this->db->prepare('SELECT ekey FROM network_events WHERE id=?');
        $st->execute([$id]);
        $key = $st->fetchColumn();
        if ($key === false) throw new InvalidArgumentException('Event not found.');
        if ($similar) $this->db->prepare('UPDATE network_events SET muted=? WHERE ekey=?')->execute([$mute ? 1 : 0, $key]);
        else $this->db->prepare('UPDATE network_events SET muted=? WHERE id=?')->execute([$mute ? 1 : 0, $id]);
    }

    // ----- ONU notes and tickets -----

    public function notes(int $onuId): array
    {
        $st = $this->db->prepare('SELECT * FROM onu_notes WHERE onu_id=? ORDER BY id DESC');
        $st->execute([$onuId]);
        return $st->fetchAll();
    }

    public function addNote(int $onuId, string $note): void
    {
        $note = trim($note);
        if ($note === '') throw new InvalidArgumentException('Write the note first.');
        if (!$this->onu($onuId)) throw new InvalidArgumentException('ONU not found.');
        $this->db->prepare('INSERT INTO onu_notes (onu_id, note, user, created_at) VALUES (?,?,?,?)')->execute([$onuId, mb_substr($note, 0, 5000), $this->user, self::now()]);
    }

    const TICKET_TYPES = ['support' => 'Support', 'installation' => 'Installation', 'repair' => 'Repair', 'relocation' => 'Relocation', 'other' => 'Other'];
    const TICKET_PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

    public function tickets(array $f = []): array
    {
        $where = ['1=1'];
        $args = [];
        if (!empty($f['onu_id'])) { $where[] = 't.onu_id=?'; $args[] = (int)$f['onu_id']; }
        if (in_array($f['status'] ?? '', ['open', 'closed'], true)) { $where[] = 't.status=?'; $args[] = $f['status']; }
        $st = $this->db->prepare('SELECT t.*, u.description AS onu_name, u.sn FROM tickets t LEFT JOIN onus u ON u.id=t.onu_id WHERE '
            . implode(' AND ', $where) . " ORDER BY t.status='open' DESC, t.id DESC LIMIT 500");
        $st->execute($args);
        return $st->fetchAll();
    }

    public function saveTicket(array $in): int
    {
        $subject = trim((string)($in['subject'] ?? ''));
        if ($subject === '') throw new InvalidArgumentException('Give the ticket a subject.');
        $onuId = (int)($in['onu_id'] ?? 0);
        if ($onuId && !$this->onu($onuId)) throw new InvalidArgumentException('ONU not found.');
        $start = trim((string)($in['start_at'] ?? ''));
        $startAt = $start !== '' && strtotime($start) ? date('Y-m-d H:i:s', strtotime($start)) : null;
        $this->db->prepare('INSERT INTO tickets (onu_id, subject, description, type, priority, assignee, start_at, duration_min, user, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$onuId ?: null, mb_substr($subject, 0, 150), mb_substr(trim((string)($in['description'] ?? '')), 0, 5000),
                isset(self::TICKET_TYPES[$in['type'] ?? '']) ? $in['type'] : 'support', isset(self::TICKET_PRIORITIES[$in['priority'] ?? '']) ? $in['priority'] : 'normal',
                mb_substr(trim((string)($in['assignee'] ?? '')), 0, 60), $startAt, max(5, min(1440, (int)($in['duration_min'] ?? 60))), $this->user, self::now()]);
        return (int)$this->db->lastInsertId();
    }

    public function closeTicket(int $id, bool $closed = true): void
    {
        $this->db->prepare('UPDATE tickets SET status=? WHERE id=?')->execute([$closed ? 'closed' : 'open', $id]);
    }

    /** ONUs authorized through SmartOLT per day, for the dashboard chart. [Y-m-d => n] */
    public function authorizationsPerDay(int $days = 14): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) $out[date('Y-m-d', time() - $i * 86400)] = 0;
        $st = $this->db->prepare("SELECT DATE(created_at) d, COUNT(*) n FROM actions WHERE action='onu.authorize' AND ok=1 AND created_at >= ? GROUP BY d");
        $st->execute([date('Y-m-d', time() - ($days - 1) * 86400)]);
        foreach ($st->fetchAll() as $r) $out[$r['d']] = (int)$r['n'];
        return $out;
    }

    // ----- Reports -----

    public static function speedText($kbps): string
    {
        $m = (int)$kbps / 1024;
        return $m >= 1 ? rtrim(rtrim(number_format($m, 2, '.', ''), '0'), '.') . ' Mbps' : (int)$kbps . ' kbps';
    }

    /** ONUs whose setup on the OLT differs from what SmartOLT saved: [onu row + 'issues' => [..]]. */
    public function mismatches(int $oltId = 0): array
    {
        $st = $this->db->prepare(self::ONU_SELECT . ' WHERE (?=0 OR u.olt_id=?) ORDER BY o.name, u.frame, u.slot, u.port, u.ont_id');
        $st->execute([$oltId, $oltId]);
        $out = [];
        foreach ($st->fetchAll() as $u) {
            $issues = [];
            if ($u['service_ports'] !== null && (int)$u['service_ports'] === 0 && $u['status_group'] !== 'disabled') {
                $issues[] = 'No service port on the OLT, so the ONU passes no internet';
            }
            if ($u['speed_profile_id'] !== null && (int)$u['service_ports'] > 0
                && ((int)$u['olt_down_kbps'] !== (int)$u['down_kbps'] || (int)$u['olt_up_kbps'] !== (int)$u['up_kbps'])) {
                $issues[] = 'Speed on the OLT is ' . ($u['olt_down_kbps'] ? self::speedText($u['olt_down_kbps']) . ' down, ' . self::speedText($u['olt_up_kbps']) . ' up' : 'unlimited')
                    . ', saved profile is ' . $u['speed_name'];
            }
            if ($u['type_name'] !== null && $u['model'] !== '' && stripos($u['model'], $u['type_name']) === false) {
                $issues[] = "The OLT reports model {$u['model']}, saved type is {$u['type_name']}";
            }
            if ($issues) $out[] = $u + ['issues' => $issues];
        }
        return $out;
    }

    /** Every PON port on every OLT with how full it is (Huawei GPON takes 128 ONUs per port). */
    public function ponOccupancy(int $oltId = 0): array
    {
        $used = [];
        foreach ($this->db->query("SELECT u.olt_id, u.frame, u.slot, u.port, COUNT(*) total, SUM(u.status_group='online') online,
            COUNT(DISTINCT u.odb_id) splitters FROM onus u GROUP BY u.olt_id, u.frame, u.slot, u.port")->fetchAll() as $r) {
            $used["{$r['olt_id']}:{$r['frame']}/{$r['slot']}/{$r['port']}"] = $r;
        }
        // Free ports of the splitters hanging on each PON port.
        $free = [];
        foreach ($this->db->query('SELECT u.olt_id, u.frame, u.slot, u.port, d.id, d.ports, COUNT(*) n FROM onus u JOIN odbs d ON d.id=u.odb_id
            GROUP BY u.olt_id, u.frame, u.slot, u.port, d.id, d.ports')->fetchAll() as $r) {
            $k = "{$r['olt_id']}:{$r['frame']}/{$r['slot']}/{$r['port']}";
            $free[$k] = ($free[$k] ?? 0) + max(0, (int)$r['ports'] - (int)$r['n']);
        }
        // Every PON port of every GPON board, used or not. GPBD and GPBH boards have 8 ports, the rest 16.
        $out = [];
        foreach ($this->olts() as $o) {
            if ($oltId && (int)$o['id'] !== $oltId) continue;
            $ports = [];
            foreach (json_decode((string)$o['boards_json'], true) ?: [] as $b) {
                if (empty($b['gpon'])) continue;
                $n = preg_match('/GPB[DH]/i', $b['name']) ? 8 : 16;
                for ($p = 0; $p < $n; $p++) $ports["0/{$b['slot']}/{$p}"] = [0, (int)$b['slot'], $p];
            }
            foreach ($used as $k => $r) {
                if ((int)$r['olt_id'] === (int)$o['id']) $ports["{$r['frame']}/{$r['slot']}/{$r['port']}"] = [(int)$r['frame'], (int)$r['slot'], (int)$r['port']];
            }
            uasort($ports, function ($a, $b) { return [$a[1], $a[2]] <=> [$b[1], $b[2]]; });
            foreach ($ports as $pon => [$f, $s, $p]) {
                $k = "{$o['id']}:{$pon}";
                $out[] = ['olt_id' => (int)$o['id'], 'olt_name' => $o['name'], 'frame' => $f, 'slot' => $s, 'port' => $p, 'max' => 128,
                    'total' => (int)($used[$k]['total'] ?? 0), 'online' => (int)($used[$k]['online'] ?? 0),
                    'splitters' => (int)($used[$k]['splitters'] ?? 0), 'empty' => $free[$k] ?? null];
            }
        }
        usort($out, function ($a, $b) { return $b['total'] <=> $a['total']; });
        return $out;
    }

    /**
     * ONUs for the Splitter check report: without a splitter, or further than $far metres from their
     * splitter when both have a location. Filters: reason (none|far), olt_id, board, port_no, zone_id, sector_id.
     */
    public function onuSplitterCheck(array $f, int $far = 300): array
    {
        $out = ['none' => 0, 'far' => 0, 'rows' => []];
        $odbs = array_column($this->odbs(), null, 'id');
        foreach ($this->listOnus(array_intersect_key($f, array_flip(['olt_id', 'board', 'port_no', 'zone_id', 'sector_id']))) as $u) {
            $reason = null;
            $dist = null;
            if ($u['odb_id'] === null) {
                $reason = 'none';
            } elseif ($u['lat'] !== null && ($d = $odbs[$u['odb_id']] ?? null) && $d['lat'] !== null) {
                $dist = (int)round(self::metres((float)$u['lat'], (float)$u['lng'], (float)$d['lat'], (float)$d['lng']));
                if ($dist > $far) $reason = 'far';
            }
            if ($reason === null) continue;
            $out[$reason]++;
            if (($f['reason'] ?? '') !== '' && $f['reason'] !== $reason) continue;
            if (count($out['rows']) < 1000) $out['rows'][] = $u + ['reason' => $reason, 'distance' => $dist];
        }
        return $out;
    }

    private static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /** Automatic keep in sync: once a day the cron puts the saved setup back on ONUs that drifted. */
    public function autoSyncOn(): bool
    {
        return $this->db->query("SELECT v FROM meta WHERE k='auto_sync'")->fetchColumn() === 'on';
    }

    public function setAutoSync(bool $on): void
    {
        $this->db->prepare("REPLACE INTO meta (k, v) VALUES ('auto_sync', ?)")->execute([$on ? 'on' : 'off']);
    }

    /** Resync every mismatched ONU that has a saved VLAN. Returns [done, failed messages]. */
    public function runAutoSync(): array
    {
        $done = 0;
        $failed = [];
        $source = $this->source;
        $this->source = 'auto';
        try {
            foreach ($this->mismatches() as $u) {
                if (!$u['vlan'] || $u['status_group'] === 'disabled') continue;
                try { $this->resync((int)$u['id']); $done++; } catch (Throwable $e) { $failed[] = "{$u['description']}: {$e->getMessage()}"; }
            }
        } finally {
            $this->source = $source;
        }
        return [$done, $failed];
    }

    /** Splitters with problems: over capacity, a port used twice, ONUs with no port, fed from more than one PON port. */
    public function splitterCheck(): array
    {
        $out = [];
        foreach ($this->odbs() as $d) {
            $st = $this->db->prepare('SELECT id, description, odb_port, olt_id, frame, slot, port FROM onus WHERE odb_id=?');
            $st->execute([$d['id']]);
            $onus = $st->fetchAll();
            $issues = [];
            if (count($onus) > (int)$d['ports']) $issues[] = count($onus) . ' ONUs on a ' . (int)$d['ports'] . ' port splitter';
            $byPort = [];
            $noPort = 0;
            $pons = [];
            foreach ($onus as $u) {
                if ($u['odb_port'] === null) $noPort++; else $byPort[(int)$u['odb_port']][] = $u['description'];
                if ((int)$u['odb_port'] > (int)$d['ports']) $issues[] = "{$u['description']} is on port {$u['odb_port']}, the splitter has " . (int)$d['ports'];
                $pons["{$u['olt_id']}:{$u['frame']}/{$u['slot']}/{$u['port']}"] = true;
            }
            foreach ($byPort as $p => $names) {
                if (count($names) > 1) $issues[] = "Port {$p} is given to " . implode(', ', $names);
            }
            if ($noPort) $issues[] = "{$noPort} ONU" . ($noPort > 1 ? 's have' : ' has') . ' no splitter port set';
            if (count($pons) > 1) $issues[] = 'Its ONUs hang on ' . count($pons) . ' different PON ports';
            $out[] = $d + ['used' => count($onus), 'issues' => $issues];
        }
        return $out;
    }

    /** Status changes across the network with filters: olt_id, state, from, to (Y-m-d). */
    public function statusChanges(array $f, int $limit = 500): array
    {
        [$where, $args] = self::rangeWhere($f, 'e.at');
        if (!empty($f['olt_id'])) { $where[] = 'u.olt_id=?'; $args[] = (int)$f['olt_id']; }
        if (!empty($f['state'])) { $where[] = 'e.state=?'; $args[] = (string)$f['state']; }
        $st = $this->db->prepare('SELECT e.state, e.cause, e.at, u.id AS onu_id, u.description, u.sn, u.frame, u.slot, u.port, u.ont_id, o.name AS olt_name
            FROM status_events e JOIN onus u ON u.id=e.onu_id JOIN olts o ON o.id=u.olt_id WHERE ' . implode(' AND ', $where) . ' ORDER BY e.id DESC LIMIT ' . (int)$limit);
        $st->execute($args);
        return $st->fetchAll();
    }

    private static function rangeWhere(array $f, string $col): array
    {
        $where = ['1=1'];
        $args = [];
        foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
            $d = (string)($f[$k] ?? '');
            $exact = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $d);
            if ($exact && $exact->format('Y-m-d H:i:s') === $d) {
                $where[] = $col . ($k === 'to' ? ' <= ?' : ' >= ?');
                $args[] = $d;
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && ($date = DateTimeImmutable::createFromFormat('!Y-m-d', $d)) && $date->format('Y-m-d') === $d) {
                $where[] = "{$col} {$op} ?";
                $args[] = $k === 'to' ? date('Y-m-d', strtotime($d . ' +1 day')) : $d;
            }
        }
        return [$where, $args];
    }

    /** The action log with filters: olt_id, user, action, from, to. */
    public function actionsFiltered(array $f, int $limit = 500): array
    {
        [$where, $args] = self::rangeWhere($f, 'a.created_at');
        if (!empty($f['olt_id'])) { $where[] = 'a.olt_id=?'; $args[] = (int)$f['olt_id']; }
        if (($f['user'] ?? '') !== '') { $where[] = 'a.user=?'; $args[] = (string)$f['user']; }
        if (($f['action'] ?? '') !== '') { $where[] = 'a.action=?'; $args[] = (string)$f['action']; }
        if (!empty($f['tasks'])) $where[] = "(a.source='auto' OR a.action LIKE 'onu.bulk%' OR a.action='olt.save')";
        if (!empty($f['ok'])) $where[] = 'a.ok=1';
        if (($q = trim((string)($f['q'] ?? ''))) !== '') {
            $where[] = '(a.target LIKE ? OR a.action LIKE ? OR a.message LIKE ?)';
            array_push($args, "%{$q}%", "%{$q}%", "%{$q}%");
        }
        $st = $this->db->prepare('SELECT a.id, a.action, a.target, a.ok, a.message, a.source, a.user, a.ip, a.created_at, o.name AS olt_name
            FROM actions a LEFT JOIN olts o ON o.id=a.olt_id WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id DESC LIMIT ' . (int)$limit);
        $st->execute($args);
        return $st->fetchAll();
    }

    /** Usernames that appear in the action log, for the Events filter. */
    public function actionUsers(): array
    {
        return $this->db->query("SELECT DISTINCT user FROM actions WHERE user<>'' ORDER BY user")->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * PON outages for the dashboard: whole ports gone dark (fiber cut), ports with several LOS, area
     * power cuts (several power fails on one port), offline ONUs with no known cause, and ONUs whose
     * signal dropped 3 dB or more against their own week.
     */
    public function outages(): array
    {
        $ports = $this->db->query("SELECT u.olt_id, o.name AS olt_name, u.frame, u.slot, u.port, COUNT(*) total,
            SUM(u.status_group='los') los, SUM(u.status_group='power_fail') pf, SUM(u.run_state<>'online') offline
            FROM onus u JOIN olts o ON o.id=u.olt_id GROUP BY u.olt_id, u.frame, u.slot, u.port")->fetchAll();
        $out = ['variations' => [], 'partial_los' => [], 'full_los' => [], 'power_fail' => [], 'na' => []];
        foreach ($ports as $p) {
            if ((int)$p['total'] >= 2 && (int)$p['offline'] === (int)$p['total'] && (int)$p['los'] > 0) $out['full_los'][] = $p;
            elseif ((int)$p['los'] >= 2) $out['partial_los'][] = $p;
            if ((int)$p['pf'] >= 2) $out['power_fail'][] = $p;
        }
        $out['na'] = $this->listOnus(['status' => 'other'], 50);
        $out['variations'] = $this->db->query('SELECT u.id, u.description, u.sn, u.rx_power, h.avg_rx, o.name AS olt_name FROM onus u
            JOIN olts o ON o.id=u.olt_id
            JOIN (SELECT onu_id, AVG(rx_power) avg_rx FROM signal_history WHERE at >= NOW() - INTERVAL 7 DAY AND at < NOW() - INTERVAL 1 HOUR GROUP BY onu_id) h ON h.onu_id=u.id
            WHERE u.rx_power IS NOT NULL AND u.rx_power <= h.avg_rx - 3 ORDER BY u.rx_power - h.avg_rx LIMIT 50')->fetchAll();
        return $out;
    }

    /** Online ONUs on one PON port over time. */
    public function ponHistory(int $oltId, string $pon, int $hours = 24): array
    {
        $st = $this->db->prepare('SELECT at, total, online FROM pon_stats WHERE olt_id=? AND pon=? AND at >= ? ORDER BY at');
        $st->execute([$oltId, $pon, date('Y-m-d H:i:s', time() - $hours * 3600)]);
        return $st->fetchAll();
    }

    /**
     * Update stored ONU details from a CSV (header row first). Matched by serial number; only
     * details kept in SmartOLT change, nothing is sent to the OLT. Returns [updated, skipped messages].
     */
    public function importOnus(array $rows): array
    {
        $header = array_map(function ($h) { return strtolower(trim((string)$h)); }, array_shift($rows) ?: []);
        $col = array_flip($header);
        $snCol = $col['serial number'] ?? $col['sn'] ?? null;
        if ($snCol === null) throw new InvalidArgumentException('The file needs a column called Serial number.');
        $lookup = function (array $list, string $name) {
            foreach ($list as $r) if (strcasecmp($r['name'], trim($name)) === 0) return (int)$r['id'];
            return null;
        };
        $zones = $this->zones(); $odbs = $this->odbs(); $sectors = $this->sectors(); $types = $this->onuTypes();
        $done = 0;
        $skipped = [];
        foreach ($rows as $i => $r) {
            $sn = Huawei::normaliseSn((string)($r[$snCol] ?? ''));
            if ($sn === null) { if (array_filter($r)) $skipped[] = 'Row ' . ($i + 2) . ': no valid serial number'; continue; }
            $st = $this->db->prepare('SELECT id FROM onus WHERE sn=?');
            $st->execute([$sn]);
            $id = (int)$st->fetchColumn();
            if (!$id) { $skipped[] = "{$sn}: not authorized on any OLT"; continue; }
            $in = [];
            $get = function (string $k) use ($col, $r) { return isset($col[$k]) ? trim((string)($r[$col[$k]] ?? '')) : null; };
            foreach (['zone' => [$zones, 'zone_id'], 'odb' => [$odbs, 'odb_id'], 'splitter' => [$odbs, 'odb_id'], 'sector' => [$sectors, 'sector_id'], 'onu type' => [$types, 'onu_type_id']] as $k => [$list, $field]) {
                $v = $get($k);
                if ($v === null) continue;
                if ($v === '') { $in[$field] = 0; continue; }
                $found = $lookup($list, $v);
                if ($found === null) { $skipped[] = "{$sn}: no {$k} called {$v}"; continue 2; }
                $in[$field] = $found;
            }
            foreach (['odb port' => 'odb_port', 'splitter port' => 'odb_port', 'address' => 'address', 'customer' => 'customer_ref', 'external id' => 'customer_ref', 'latitude' => 'lat', 'longitude' => 'lng'] as $k => $field) {
                if (($v = $get($k)) !== null) $in[$field] = $v;
            }
            try {
                $this->saveMetaRow($id, $this->metaFromInput($in));
                $done++;
            } catch (InvalidArgumentException $e) {
                $skipped[] = "{$sn}: {$e->getMessage()}";
            }
        }
        return [$done, $skipped];
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
