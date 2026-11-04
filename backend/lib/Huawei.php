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
        ];
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
    public function authorize(int $frame, int $slot, int $port, string $sn, int $lineProfile, int $srvProfile, string $desc, ?int $vlan, int $gemport, bool $bridge): int
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
            if ($bridge) {
                // Untagged traffic on the first LAN port joins the VLAN. Router ONUs have no such port setting.
                $this->raw("ont port native-vlan {$port} {$ontId} eth 1 vlan {$vlan} priority 0", true);
            }
            $this->leavePort();
            $this->raw("service-port vlan {$vlan} gpon {$frame}/{$slot}/{$port} ont {$ontId} gemport {$gemport} multi-service user-vlan {$vlan} tag-transform translate");
        }
        $this->leavePort();
        return $ontId;
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
