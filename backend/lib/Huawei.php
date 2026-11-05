<?php
// Huawei MA5600T series driver (MA5683T, MA5608T, MA5680T). One instance is one logged-in Telnet
// session in config mode. The parse* functions are pure, so tests run them on captured output.

require_once __DIR__ . '/Telnet.php';

class OltError extends RuntimeException {}

class Huawei
{
    private Telnet $t;
    private string $mode = 'config';

    public function __construct(Telnet $t)
    {
        $this->t = $t;
    }

    public static function connect(string $host, int $port, string $user, string $pass, int $connectTimeout = 8, int $commandTimeout = 25): self
    {
        $t = new Telnet($host, $port, $connectTimeout, $commandTimeout);
        $t->readUntil('/(User ?name|Login|Username)\s*:\s*$/i', $connectTimeout + 7);
        $t->write($user . "\n");
        $t->readUntil('/password\s*:\s*$/i');
        $t->write($pass . "\n", true);
        $out = $t->readUntil('/(invalid|incorrect|fail|Reenter|locked)|' . trim(Telnet::PROMPT, '/') . '/i');
        if (preg_match('/invalid|incorrect|fail|Reenter|locked/i', $out)) {
            throw new OltError('The OLT refused the username or password.');
        }
        $olt = new self($t);
        $olt->raw('enable');
        // Plain output with no parameter prompts and no paging, as far as this firmware allows.
        $olt->raw('undo smart', true);
        $olt->raw('scroll 512', true);
        $olt->raw('config');
        return $olt;
    }

    public function transcript(): string
    {
        return $this->t->transcript();
    }

    public function close(): void
    {
        $this->t->close();
    }

    /** Send one line and return what the OLT printed, without the echoed command and the prompt. */
    public function raw(string $command, bool $allowFail = false): string
    {
        $this->t->write($command . "\n");
        $out = $this->t->readUntil(Telnet::PROMPT);
        $out = self::stripEcho($out, $command);
        if (!$allowFail && ($err = self::findError($out)) !== null) {
            throw new OltError($err);
        }
        return $out;
    }

    /** A command that asks "(y/n)[n]:" before it acts. */
    public function confirm(string $command): string
    {
        $this->t->write($command . "\n");
        $out = $this->t->readUntil('/\(y\/n\)\s*\[[yn]\]\s*:\s*$|' . trim(Telnet::PROMPT, '/') . '/');
        if (preg_match('/\(y\/n\)\s*\[[yn]\]\s*:\s*$/', $out)) {
            $this->t->write("y\n");
            $out .= $this->t->readUntil(Telnet::PROMPT);
        }
        $out = self::stripEcho($out, $command);
        if (($err = self::findError($out)) !== null) {
            throw new OltError($err);
        }
        return $out;
    }

    private function enterPort(int $frame, int $slot): void
    {
        $this->leavePort();
        $this->raw("interface gpon {$frame}/{$slot}");
        $this->mode = 'if';
    }

    private function leavePort(): void
    {
        if ($this->mode === 'if') {
            $this->raw('quit');
            $this->mode = 'config';
        }
    }

    // ----- Reads -----

    public function info(): array
    {
        $this->leavePort();
        $version = self::parseVersion($this->raw('display version', true));
        $boards = self::parseBoards($this->raw('display board 0', true));
        return [
            'model'         => $version['product'],
            'version'       => $version['version'],
            'boards'        => $boards,
            'line_profiles' => self::parseProfiles($this->raw('display ont-lineprofile gpon all', true)),
            'srv_profiles'  => self::parseProfiles($this->raw('display ont-srvprofile gpon all', true)),
            'vlans'         => self::parseVlans($this->raw('display vlan all', true)),
            'traffic'       => $this->trafficTables(),
            'uptime'        => self::parseUptime($this->raw('display sysuptime', true)),
            'temperature'   => $this->temperature($boards),
        ];
    }

    /** Temperature of the active control board ("45" for 45 C), or '' when the OLT does not say. */
    private function temperature(array $boards): string
    {
        foreach ($boards as $b) {
            if (stripos($b['name'], 'SCU') !== false && stripos($b['status'], 'active') !== false) {
                $out = $this->raw("display temperature 0/{$b['slot']}", true);
                return self::findError($out) === null && preg_match('/(-?\d+)\s*(?:C\b|°)/i', $out, $m) ? $m[1] : '';
            }
        }
        return '';
    }

    /**
     * Write the running configuration to flash, so ONUs authorized since the last save survive an
     * OLT reboot. Huawei asks "{ <cr>|configuration<K>|data<K> }:", which Telnet answers with Enter.
     */
    public function saveConfig(): string
    {
        $this->leavePort();
        return $this->confirm('save');
    }

    /** The ONT's hardware and software versions as the OLT reports them. */
    public function ontVersion(int $frame, int $slot, int $port, int $ontId): array
    {
        $this->enterPort($frame, $slot);
        $kv = self::parseKeyValues($this->raw("display ont version {$port} {$ontId}"));
        $this->leavePort();
        return $kv;
    }

    /** Put the ONT back to factory settings; the OLT pushes its configuration again when it comes back. */
    public function restoreDefaults(int $frame, int $slot, int $port, int $ontId): void
    {
        $this->enterPort($frame, $slot);
        $this->confirm("ont factory-setting-restore {$port} {$ontId}");
        $this->leavePort();
    }

    /** Create a smart VLAN on the OLT, with a description when given. */
    public function addVlan(int $vlan, string $desc): void
    {
        $this->leavePort();
        $this->raw("vlan {$vlan} smart");
        if ($desc !== '') $this->raw("vlan desc {$vlan} description \"{$desc}\"");
    }

    public function deleteVlan(int $vlan): void
    {
        $this->leavePort();
        $this->confirm("undo vlan {$vlan}");
    }

    /** What the OLT holds for one ONT: its ont add line, port settings and service ports. */
    public function runningConfig(int $frame, int $slot, int $port, int $ontId): string
    {
        $this->leavePort();
        return trim($this->raw("display current-configuration ont {$frame}/{$slot}/{$port} {$ontId}"));
    }

    /**
     * Read only commands the Diag page may run. Each takes numbers only, filled in from the form, so
     * nothing typed by a person reaches the OLT command line.
     */
    const DIAG = [
        'version'   => ['Software version', 'display version', []],
        'uptime'    => ['Uptime', 'display sysuptime', []],
        'boards'    => ['Boards', 'display board 0', []],
        'board'     => ['One board', 'display board {frame}/{slot}', ['frame', 'slot']],
        'autofind'  => ['Unauthorized ONUs (autofind)', 'display ont autofind all', []],
        'summary'   => ['ONUs on a PON port', 'display ont info summary {frame}/{slot}/{port}', ['frame', 'slot', 'port']],
        'onu'       => ['One ONU', 'display ont info {frame} {slot} {port} {ont}', ['frame', 'slot', 'port', 'ont']],
        'optical'   => ['Optical levels on a PON port', 'display ont optical-info {port} all', ['frame', 'slot', 'port'], true],
        'services'  => ['Service ports of a PON port', 'display service-port port {frame}/{slot}/{port}', ['frame', 'slot', 'port']],
        'config'    => ['Running config of one ONU', 'display current-configuration ont {frame}/{slot}/{port} {ont}', ['frame', 'slot', 'port', 'ont']],
        'traffic'   => ['Speed tables', 'display traffic table ip from-index 0', []],
        'vlans'     => ['VLANs', 'display vlan all', []],
        'lineprof'  => ['Line profiles', 'display ont-lineprofile gpon all', []],
        'srvprof'   => ['Service profiles', 'display ont-srvprofile gpon all', []],
    ];

    /**
     * A display command typed on the CLI tab. Only "display ..." is let through, on one line, so the
     * CLI tab can look at anything but change nothing.
     */
    public function displayCommand(string $cmd): string
    {
        $cmd = trim(preg_replace('/\s+/', ' ', $cmd));
        if (!preg_match('/^display [A-Za-z0-9 \/:._-]{1,150}$/', $cmd)) {
            throw new InvalidArgumentException('Only display commands can be run here, for example: display board 0');
        }
        $this->leavePort();
        return rtrim($this->raw($cmd, true));
    }

    /** Run one DIAG command with numeric parameters and return its output as printed. */
    public function diag(string $key, array $params): string
    {
        if (!isset(self::DIAG[$key])) throw new InvalidArgumentException('Unknown check.');
        [, $cmd, $need] = self::DIAG[$key];
        $inPort = !empty(self::DIAG[$key][3]);
        foreach ($need as $p) {
            $v = $params[$p] ?? '';
            if (!is_numeric($v) || (int)$v < 0 || (int)$v > 255) throw new InvalidArgumentException('Fill in ' . $p . ' as a number.');
            $cmd = str_replace('{' . $p . '}', (string)(int)$v, $cmd);
        }
        if ($inPort) {
            $this->enterPort((int)$params['frame'], (int)$params['slot']);
        } else {
            $this->leavePort();
        }
        $out = $this->raw($cmd, true);
        $this->leavePort();
        return rtrim($out);
    }

    /**
     * "display ont info summary F/S/P": run state, last up and down time, down cause, model, distance
     * and light levels for every ONT on one port in one command. Null when the firmware lacks it.
     */
    public function portSummary(int $frame, int $slot, int $port): ?array
    {
        $this->leavePort();
        $out = $this->raw("display ont info summary {$frame}/{$slot}/{$port}", true);
        if (self::findError($out) !== null) {
            return stripos($out, 'Unknown command') !== false || stripos($out, 'Parameter error') !== false ? null : [];
        }
        return self::parseSummary($out);
    }

    /** Every GPON service port: which ONT, VLAN and GEM port it carries and its speed tables. */
    public function servicePorts(): array
    {
        $this->leavePort();
        return self::parseServicePortTable($this->raw('display service-port all', true));
    }

    /** IP traffic tables (speed limits) on the OLT: index => ['cir' => kbps, 'pir' => kbps]. */
    public function trafficTables(): array
    {
        $this->leavePort();
        return self::parseTrafficTables($this->raw('display traffic table ip from-index 0', true));
    }

    public function createTrafficTable(int $index, int $kbps): void
    {
        $this->leavePort();
        $this->raw("traffic table ip index {$index} cir {$kbps} pir {$kbps} priority 0 priority-policy local-setting");
    }

    public function autofind(): array
    {
        $this->leavePort();
        $out = $this->raw('display ont autofind all', true);
        if (stripos($out, 'Failure') !== false && stripos($out, 'does not exist') !== false) {
            return [];
        }
        return self::parseAutofind($out);
    }

    /** Every authorized ONT on frame 0, with its run state and description. */
    public function onts(): array
    {
        $this->leavePort();
        $out = $this->raw('display ont info 0 all', true);
        if (($error = self::findError($out)) !== null) {
            // An empty OLT is a valid first installation; unsupported commands/access failures are not.
            if (preg_match('/(?:required\s+)?ONT(?:s)?\s+(?:does?|do)\s+not\s+exist/i', $error)) return [];
            throw new OltError($error);
        }
        return self::parseOntInfo($out);
    }

    /** Optical levels for every ONT on one PON port, keyed by ONT ID. Empty if the firmware cannot list them. */
    public function portOptical(int $frame, int $slot, int $port): array
    {
        $this->enterPort($frame, $slot);
        $out = $this->raw("display ont optical-info {$port} all", true);
        return self::findError($out) === null ? self::parseOpticalTable($out) : [];
    }

    public function ontOptical(int $frame, int $slot, int $port, int $ontId): array
    {
        $this->enterPort($frame, $slot);
        // An offline ONT has no light levels; that is an answer, not an error.
        return self::parseOptical($this->raw("display ont optical-info {$port} {$ontId}", true));
    }

    public function ontDetail(int $frame, int $slot, int $port, int $ontId): array
    {
        $this->enterPort($frame, $slot);
        return self::parseKeyValues($this->raw("display ont info {$port} {$ontId}"));
    }

    // ----- Changes -----

    /**
     * Authorize an ONT by serial number and give it internet on a VLAN.
     * Returns the ONT ID the OLT assigned.
     */
    public function authorize(int $frame, int $slot, int $port, string $sn, int $lineProfile, int $srvProfile, string $desc, ?int $vlan, int $gemport, bool $bridge, ?int $upTable = null, ?int $downTable = null): int
    {
        $this->enterPort($frame, $slot);
        $out = $this->raw(sprintf(
            'ont add %d sn-auth %s omci ont-lineprofile-id %d ont-srvprofile-id %d desc "%s"',
            $port, $sn, $lineProfile, $srvProfile, $desc
        ));
        $ontId = self::parseOntAdd($out);
        if ($ontId === null) {
            throw new OltError('The OLT did not say which ONT ID it gave the ONU.');
        }
        if ($vlan !== null) {
            $this->addService($frame, $slot, $port, $ontId, $vlan, $gemport, $bridge, $upTable, $downTable);
        }
        $this->leavePort();
        return $ontId;
    }

    /** The service port that carries an ONT's internet on a VLAN, optionally speed limited by traffic tables. */
    private function addService(int $frame, int $slot, int $port, int $ontId, int $vlan, int $gemport, bool $bridge, ?int $upTable, ?int $downTable): void
    {
        if ($bridge) {
            // Untagged traffic on the first LAN port joins the VLAN. Router ONUs have no such port setting.
            $this->enterPort($frame, $slot);
            $this->raw("ont port native-vlan {$port} {$ontId} eth 1 vlan {$vlan} priority 0", true);
        }
        $this->leavePort();
        $cmd = "service-port vlan {$vlan} gpon {$frame}/{$slot}/{$port} ont {$ontId} gemport {$gemport} multi-service user-vlan {$vlan} tag-transform translate";
        if ($upTable !== null && $downTable !== null) {
            // inbound = from the customer (upload), outbound = to the customer (download)
            $cmd .= " inbound traffic-table index {$upTable} outbound traffic-table index {$downTable}";
        }
        $this->raw($cmd);
    }

    /** Replace an ONT's service ports with one on the new VLAN and speed. Returns how many were replaced. */
    public function setService(int $frame, int $slot, int $port, int $ontId, int $vlan, int $gemport, bool $bridge, ?int $upTable, ?int $downTable): int
    {
        $this->leavePort();
        $indexes = self::parseServicePorts($this->raw("display service-port port {$frame}/{$slot}/{$port} ont {$ontId}", true));
        foreach ($indexes as $i) {
            $this->raw("undo service-port {$i}");
        }
        $this->addService($frame, $slot, $port, $ontId, $vlan, $gemport, $bridge, $upTable, $downTable);
        return count($indexes);
    }

    /** Disable (the ONT stays authorized but gets no service) or enable it again. */
    public function setActive(int $frame, int $slot, int $port, int $ontId, bool $active): void
    {
        $this->enterPort($frame, $slot);
        $this->raw(($active ? 'ont activate' : 'ont deactivate') . " {$port} {$ontId}");
        $this->leavePort();
    }

    /** Put a new ONU in place of a broken one: same ONT ID, profiles, VLAN and speed, new serial. */
    public function replaceSn(int $frame, int $slot, int $port, int $ontId, string $sn): void
    {
        $this->enterPort($frame, $slot);
        $this->raw("ont modify {$port} {$ontId} sn {$sn}");
        $this->leavePort();
    }

    public function reboot(int $frame, int $slot, int $port, int $ontId): void
    {
        $this->enterPort($frame, $slot);
        $this->confirm("ont reset {$port} {$ontId}");
        $this->leavePort();
    }

    /** Remove the ONT and the service ports that carry its traffic. Returns how many service ports went. */
    public function delete(int $frame, int $slot, int $port, int $ontId): int
    {
        $this->leavePort();
        $indexes = self::parseServicePorts($this->raw("display service-port port {$frame}/{$slot}/{$port} ont {$ontId}", true));
        foreach ($indexes as $i) {
            $this->raw("undo service-port {$i}");
        }
        $this->enterPort($frame, $slot);
        $this->raw("ont delete {$port} {$ontId}");
        $this->leavePort();
        return count($indexes);
    }

    public function setDescription(int $frame, int $slot, int $port, int $ontId, string $desc): void
    {
        $this->enterPort($frame, $slot);
        $this->raw(sprintf('ont modify %d %d desc "%s"', $port, $ontId, $desc));
        $this->leavePort();
    }

    // ----- Parsers -----

    public static function stripEcho(string $out, string $command): string
    {
        $lines = explode("\n", $out);
        if ($lines && strpos($lines[0], trim($command)) !== false) {
            array_shift($lines);
        }
        if ($lines && preg_match(Telnet::PROMPT, "\n" . end($lines))) {
            array_pop($lines);
        }
        return rtrim(implode("\n", $lines));
    }

    /** The OLT's own error line, or null when the command went through. */
    public static function findError(string $out): ?string
    {
        if (preg_match('/^\s*(% ?(Unknown command|Parameter error|Incomplete command|Too many parameters|Ambiguous command)[^\n]*|Failure:[^\n]*|Error:[^\n]*)/mi', $out, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public static function parseVersion(string $out): array
    {
        preg_match('/VERSION\s*:\s*(\S+)/i', $out, $v);
        preg_match('/PRODUCT\s*:\s*(\S+)/i', $out, $p);
        return ['version' => $v[1] ?? '', 'product' => $p[1] ?? ''];
    }

    /** "System up time: 120 day 5 hour 33 minute 21 second" as "120d 05:33". */
    public static function parseUptime(string $out): string
    {
        if (!preg_match('/up\s*time\s*:?\s*(?:(\d+)\s*days?)?[,\s]*(?:(\d+)\s*hours?)?[,\s]*(?:(\d+)\s*min)?/i', $out, $m) || !isset($m[1])) return '';
        $d = (int)($m[1] ?? 0);
        return ($d ? $d . 'd ' : '') . sprintf('%02d:%02d', (int)($m[2] ?? 0), (int)($m[3] ?? 0));
    }

    public static function parseBoards(string $out): array
    {
        $boards = [];
        preg_match_all('/^\s*(\d+)\s+(H\w+)\s+(\S+)/m', $out, $m, PREG_SET_ORDER);
        foreach ($m as $r) {
            $boards[] = [
                'slot'   => (int)$r[1],
                'name'   => $r[2],
                'status' => $r[3],
                'gpon'   => (bool)preg_match('/GP/i', $r[2]),
            ];
        }
        return $boards;
    }

    public static function parseProfiles(string $out): array
    {
        $profiles = [];
        preg_match_all('/^\s*(\d+)\s+(\S.*?)\s+(\d+)\s*$/m', $out, $m, PREG_SET_ORDER);
        foreach ($m as $r) {
            $profiles[] = ['id' => (int)$r[1], 'name' => $r[2], 'bindings' => (int)$r[3]];
        }
        return $profiles;
    }

    public static function parseVlans(string $out): array
    {
        $vlans = [];
        preg_match_all('/^\s*(\d+)\s+(smart|mux|standard|super)\s+(\S+)/mi', $out, $m, PREG_SET_ORDER);
        foreach ($m as $r) {
            $vlans[] = ['id' => (int)$r[1], 'type' => strtolower($r[2]), 'attribute' => $r[3]];
        }
        return $vlans;
    }

    public static function parseAutofind(string $out): array
    {
        $found = [];
        foreach (preg_split('/^\s*-{10,}\s*$/m', $out) as $block) {
            if (!preg_match('/F\/S\/P\s*:\s*(\d+)\/(\d+)\/(\d+)/', $block, $fsp)) {
                continue;
            }
            $kv = self::parseKeyValues($block);
            preg_match('/^([0-9A-Fa-f]{16})(?:\s*\(([^)]+)\))?/', $kv['ont sn'] ?? '', $sn);
            if (!$sn) {
                continue;
            }
            $found[] = [
                'frame'     => (int)$fsp[1],
                'slot'      => (int)$fsp[2],
                'port'      => (int)$fsp[3],
                'sn'        => strtoupper($sn[1]),
                'sn_label'  => $sn[2] ?? '',
                'vendor'    => $kv['vendorid'] ?? '',
                'equipment' => $kv['ont equipmentid'] ?? '',
                'software'  => $kv['ont softwareversion'] ?? '',
                'found_at'  => $kv['ont autofind time'] ?? '',
            ];
        }
        return $found;
    }

    /** "display ont info ... all": a state table and a description table for each port. */
    public static function parseOntInfo(string $out): array
    {
        $onts = [];
        $table = '';
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/F\/S\/P\s+ONT\s/', $line)) {
                $table = stripos($line, 'Description') !== false ? 'desc' : 'state';
                continue;
            }
            if (!preg_match('/^\s*(\d+)\/\s*(\d+)\/\s*(\d+)\s+(\d+)\s*(.*)$/', $line, $r)) {
                continue;
            }
            $key = "{$r[1]}/{$r[2]}/{$r[3]}/{$r[4]}";
            if ($table === 'state' && preg_match('/^([0-9A-Fa-f]{16})\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)/', $r[5], $s)) {
                $onts[$key] = [
                    'frame' => (int)$r[1], 'slot' => (int)$r[2], 'port' => (int)$r[3], 'ont_id' => (int)$r[4],
                    'sn' => strtoupper($s[1]), 'control_flag' => $s[2], 'run_state' => $s[3],
                    'config_state' => $s[4], 'match_state' => $s[5], 'description' => '',
                ];
            } elseif ($table === 'desc' && isset($onts[$key])) {
                $onts[$key]['description'] = trim($r[5]);
            }
        }
        return array_values($onts);
    }

    public static function parseOptical(string $out): array
    {
        $kv = self::parseKeyValues($out);
        $num = function ($k) use ($kv) {
            return isset($kv[$k]) && is_numeric($kv[$k]) ? (float)$kv[$k] : null;
        };
        return [
            'rx_power'     => $num('rx optical power(dbm)'),
            'tx_power'     => $num('tx optical power(dbm)'),
            'olt_rx_power' => $num('olt rx ont optical power(dbm)'),
            'temperature'  => isset($kv['temperature(c)']) && is_numeric($kv['temperature(c)']) ? (int)$kv['temperature(c)'] : null,
        ];
    }

    /** "display ont optical-info <port> all": ONT ID, Rx, Tx, OLT Rx, temperature per row. */
    public static function parseOpticalTable(string $out): array
    {
        $rows = [];
        $n = '(-?\d+(?:\.\d+)?|-+)';
        preg_match_all("/^\s*(\d+)\s+{$n}\s+{$n}\s+{$n}\s+{$n}/m", $out, $m, PREG_SET_ORDER);
        foreach ($m as $r) {
            if (strpos($r[2], '.') === false && strpos($r[3], '.') === false) {
                continue; // not a level row
            }
            $f = function ($v) { return is_numeric($v) ? (float)$v : null; };
            $rows[(int)$r[1]] = [
                'rx_power' => $f($r[2]), 'tx_power' => $f($r[3]), 'olt_rx_power' => $f($r[4]),
                'temperature' => is_numeric($r[5]) ? (int)$r[5] : null,
            ];
        }
        return $rows;
    }

    /**
     * "display ont info summary": a state table (ID, run state, last up, last down, down cause) and a
     * device table (ID, SN, type, distance, Rx/Tx). Times may carry a "+03:00" zone, absent ones are "-".
     */
    public static function parseSummary(string $out): array
    {
        $rows = [];
        $table = '';
        $time = '(\d{4}-\d\d-\d\d\s+\d\d:\d\d:\d\d(?:[+-]\d\d:\d\d)?|-+)';
        $num = '(-?\d+(?:\.\d+)?|-+)';
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/DownCause|Last\s+Last/i', $line)) { $table = 'state'; continue; }
            if (preg_match('/Distance/i', $line)) { $table = 'device'; continue; }
            if ($table === 'state' && preg_match("/^\s*(\d+)\s+([a-z]+)\s+{$time}\s+{$time}\s+(\S+)?\s*$/i", $line, $r)) {
                $id = (int)$r[1];
                $rows[$id] = ($rows[$id] ?? []) + [
                    'run_state'  => strtolower($r[2]),
                    'last_up'    => self::summaryTime($r[3]),
                    'last_down'  => self::summaryTime($r[4]),
                    'down_cause' => isset($r[5]) && trim($r[5], '-') !== '' ? $r[5] : '',
                ];
            } elseif ($table === 'device' && preg_match("/^\s*(\d+)\s+([0-9A-Fa-f]{16})\s+(\S+)\s+{$num}\s+{$num}\/{$num}\s*(.*)$/", $line, $r)) {
                $id = (int)$r[1];
                $f = function ($v) { return is_numeric($v) ? (float)$v : null; };
                $rows[$id] = ($rows[$id] ?? []) + [
                    'sn'          => strtoupper($r[2]),
                    'model'       => trim($r[3], '-'),
                    'distance_m'  => is_numeric($r[4]) ? (int)$r[4] : null,
                    'rx_power'    => $f($r[5]),
                    'tx_power'    => $f($r[6]),
                    'description' => trim($r[7]),
                ];
            }
        }
        return $rows;
    }

    private static function summaryTime(string $s): ?string
    {
        if (!preg_match('/^(\d{4}-\d\d-\d\d)\s+(\d\d:\d\d:\d\d)/', $s, $m)) {
            return null;
        }
        return $m[1] . ' ' . $m[2];
    }

    /**
     * "display service-port": INDEX VLAN ATTR gpon F/S /P VPI(ONT ID) VCI(GEM) FLOW-TYPE FLOW-PARA RX TX STATE.
     * RX and TX are the inbound (upload) and outbound (download) traffic table indexes.
     */
    public static function parseServicePortTable(string $out): array
    {
        $rows = [];
        preg_match_all('/^\s*(\d+)\s+(\d+)\s+\S+\s+gpon\s+(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)\s+(\d+)\s+(\d+)\s+\S+\s+\S+\s+(\S+)\s+(\S+)\s+(\S+)/mi', $out, $m, PREG_SET_ORDER);
        foreach ($m as $r) {
            $rows[] = [
                'index' => (int)$r[1], 'vlan' => (int)$r[2], 'frame' => (int)$r[3], 'slot' => (int)$r[4], 'port' => (int)$r[5],
                'ont_id' => (int)$r[6], 'gemport' => (int)$r[7],
                'up_table' => is_numeric($r[8]) ? (int)$r[8] : null, 'down_table' => is_numeric($r[9]) ? (int)$r[9] : null,
                'state' => strtolower($r[10]),
            ];
        }
        return $rows;
    }

    /** "display traffic table ip": TID, CIR, CBS, PIR, PBS ... per row. */
    public static function parseTrafficTables(string $out): array
    {
        $tables = [];
        preg_match_all('/^\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s/m', $out, $m, PREG_SET_ORDER);
        foreach ($m as $r) {
            $tables[(int)$r[1]] = ['cir' => (int)$r[2], 'pir' => (int)$r[4]];
        }
        return $tables;
    }

    /**
     * Why an ONT is offline, as SmartOLT style groups: power_fail (the ONU lost power, Huawei says
     * dying-gasp), los (no light: fibre cut, bent or unplugged), other.
     */
    public static function downCategory(string $runState, string $cause): string
    {
        if (strtolower($runState) === 'online') return 'online';
        $c = strtolower($cause);
        if (strpos($c, 'dying') !== false || strpos($c, 'power') !== false) return 'power_fail';
        if (preg_match('/^(los|losi|lofi|loami|lobi|sufi|drift)/', $c)) return 'los';
        return 'other';
    }

    public static function parseOntAdd(string $out): ?int
    {
        return preg_match('/ONTID\s*:\s*(\d+)/i', $out, $m) ? (int)$m[1] : null;
    }

    public static function parseServicePorts(string $out): array
    {
        preg_match_all('/^\s*(\d+)\s+(\d+)\s+\S+\s+gpon\s/mi', $out, $m);
        return array_map('intval', $m[1]);
    }

    /** "Name : value" lines into a lowercase keyed array. */
    public static function parseKeyValues(string $out): array
    {
        $kv = [];
        preg_match_all('/^\s*([A-Za-z][^:\n]*?)\s*:\s*(.*?)\s*$/m', $out, $m, PREG_SET_ORDER);
        foreach ($m as $r) {
            $kv[strtolower($r[1])] = $r[2];
        }
        return $kv;
    }

    /** Accept "485754434D2F5A9A", "HWTC-4D2F5A9A" or "HWTC4D2F5A9A"; return the 16 digit hex the OLT uses. */
    public static function normaliseSn(string $sn): ?string
    {
        $sn = strtoupper(trim($sn));
        if (preg_match('/^[0-9A-F]{16}$/', $sn)) {
            return $sn;
        }
        if (preg_match('/^([A-Z0-9]{4})-?([0-9A-F]{8})$/', $sn, $m)) {
            return strtoupper(bin2hex($m[1])) . $m[2];
        }
        return null;
    }
}
