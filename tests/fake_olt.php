<?php
// A pretend Huawei MA5683T on a TCP port, for tests only. It sends Telnet option negotiation,
// pages long output with "More" screens, asks "{ <cr>|... }:" parameter prompts and the
// "(y/n)[n]:" confirmation, and keeps ONT and service port state across connections.
//   php tests/fake_olt.php 2323

$port = (int)($argv[1] ?? 2323);
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "cannot listen: {$errstr}\n");
    exit(1);
}

$state = [
    'onts' => [
        // "slot/port/id" => [sn, desc, run, last down cause, model, control flag]
        '1/0/0' => ['485754434D2F0001', 'alice_home', 'online', 'dying-gasp', 'EG8145V5', 'active'],
        '1/0/1' => ['485754434D2F0002', 'bob_shop', 'offline', 'dying-gasp', 'HG8546M', 'active'],
        '1/1/0' => ['5A54454700000003', 'carol', 'online', '', 'F660', 'active'],
        '1/1/1' => ['485754434D2F0004', 'erin_flat', 'offline', 'LOS', 'HG8010H', 'active'],
    ],
    // traffic table index => kbps (0 to 6 are the OLT's own defaults)
    'tt' => [0 => 1024, 6 => 1024000],
    'autofind' => [
        ['1/0', '485754434D2F5A9A', 'HWTC', 'EG8145V5'],
        ['1/2', '5A544547C0FFEE01', 'ZTEG', 'F660'],
    ],
    'sp' => [ // index => ["slot/port/id", vlan, gem port, inbound table, outbound table]
        10 => ['1/0/0', 100, 1, '-', '-'], 11 => ['1/0/1', 100, 1, '-', '-'], 12 => ['1/1/0', 100, 1, '-', '-'], 13 => ['1/1/1', 100, 1, '-', '-'],
    ],
];

function prompt_for(string $mode): string
{
    return ['user' => 'MA5683T>', 'enable' => 'MA5683T#', 'config' => 'MA5683T(config)#'][$mode] ?? "MA5683T(config-if-gpon-0/{$mode})#";
}

function send($c, string $s): void
{
    fwrite($c, str_replace("\n", "\r\n", $s));
}

function readline_from($c): ?string
{
    $line = '';
    while (true) {
        $ch = fread($c, 1);
        if ($ch === false || $ch === '') {
            if (feof($c)) return null;
            usleep(5000);
            continue;
        }
        if ($ch === "\xFF") { fread($c, 2); continue; } // swallow IAC replies
        if ($ch === "\n") return rtrim($line, "\r");
        $line .= $ch;
    }
}

/** Send long output a page at a time, waiting for a space after each "More". */
function paged($c, string $text, int $pageLines = 8): void
{
    $lines = explode("\n", $text);
    foreach (array_chunk($lines, $pageLines) as $i => $chunk) {
        if ($i > 0) {
            send($c, "  ---- More ( Press 'Q' to break ) ----");
            fread($c, 1);
            send($c, "\x1b[37D                                     \x1b[37D");
        }
        send($c, implode("\n", $chunk) . "\n");
    }
}

function ont_info_table(array $onts, ?string $only = null): string
{
    $s = "  -----------------------------------------------------------------------------\n"
       . "  F/S/P   ONT         SN         Control     Run      Config   Match    Protect\n"
       . "          ID                     flag        state    state    state    side \n"
       . "  -----------------------------------------------------------------------------\n";
    foreach ($onts as $k => [$sn, $desc, $run]) {
        [$sl, $p, $id] = explode('/', $k);
        if ($only !== null && "{$sl}/{$p}" !== $only) continue;
        $s .= sprintf("  0/ %d/%d  %3d  %s  %-11s %-8s normal   match    no \n", $sl, $p, $id, $sn, $onts[$k][5], $run);
    }
    $s .= "  -----------------------------------------------------------------------------\n"
        . "  F/S/P   ONT        Description                                           \n"
        . "          ID                                                               \n"
        . "  -----------------------------------------------------------------------------\n";
    foreach ($onts as $k => [$sn, $desc, $run]) {
        [$sl, $p, $id] = explode('/', $k);
        if ($only !== null && "{$sl}/{$p}" !== $only) continue;
        $s .= sprintf("  0/ %d/%d  %3d  %s\n", $sl, $p, $id, $desc);
    }
    $s .= "  -----------------------------------------------------------------------------\n"
        . "  In port 0/1/0 , the total of ONTs are: " . count($onts) . ", online: 2\n";
    return $s;
}

while (true) {
    $c = @stream_socket_accept($server, -1);
    if (!$c) continue;
    // Option negotiation first, the way real OLTs open a session.
    fwrite($c, "\xFF\xFB\x01\xFF\xFB\x03\xFF\xFD\x18\xFF\xFD\x1F");
    send($c, "\n\n>>User name:");
    $user = readline_from($c);
    send($c, "\n>>User password:");
    $pass = readline_from($c);
    if ($user !== 'root' || $pass !== 'admin123') {
        send($c, "\n  Username or password invalid.\n");
        fclose($c);
        continue;
    }
    send($c, "\n\n  Huawei Integrated Access Software (MA5683T).\n\nMA5683T>");
    $mode = 'user';
    while (($cmd = readline_from($c)) !== null) {
        $cmd = trim($cmd);
        send($c, $cmd . "\n"); // echo
        $out = '';
        if ($cmd === '') {
        } elseif ($cmd === 'enable') { $mode = 'enable';
        } elseif ($cmd === 'undo smart' || $cmd === 'scroll 512') {
        } elseif ($cmd === 'config') { $mode = 'config';
        } elseif ($cmd === 'quit') {
            if ($mode === 'user' || $mode === 'enable') { fclose($c); continue 2; }
            $mode = $mode === 'config' ? 'enable' : 'config';
        } elseif ($cmd === 'display version') {
            $out = "\n  VERSION : MA5600V800R018C10\n  PATCH   : SPH210\n  PRODUCT : MA5683T\n\n  Active Mainboard Running Area Information:\n";
        } elseif ($cmd === 'display board 0') {
            paged($c, "  -------------------------------------------------------------------------\n"
                . "  SlotID  BoardName  Status          SubType0 SubType1    Online/Offline\n"
                . "  -------------------------------------------------------------------------\n"
                . "  0\n  1       H805GPFD   Normal\n  2       H805GPFD   Normal\n  3\n  4\n  5\n  6\n"
                . "  7       H801SCUN   Active_normal\n  8       H801SCUN   Standby_normal\n  9       H801GICF   Normal\n"
                . "  -------------------------------------------------------------------------\n");
        } elseif ($cmd === 'display ont-lineprofile gpon all') {
            $out = "  -----------------------------------------------------------------------------\n"
                . "  Profile-ID  Profile-name                                Binding times\n"
                . "  -----------------------------------------------------------------------------\n"
                . "  0           line-profile_default_0                      0\n"
                . "  10          INTERNET_100                                3\n"
                . "  -----------------------------------------------------------------------------\n  Total: 2\n";
        } elseif ($cmd === 'display ont-srvprofile gpon all') {
            $out = "  -----------------------------------------------------------------------------\n"
                . "  Profile-ID  Profile-name                                Binding times\n"
                . "  -----------------------------------------------------------------------------\n"
                . "  0           srv-profile_default_0                       0\n"
                . "  20          ALL_PORTS                                   3\n"
                . "  -----------------------------------------------------------------------------\n  Total: 2\n";
        } elseif ($cmd === 'display vlan all') {
            $out = "  VLAN   Type      Attribute     STND-Port NUM  SERVICE-Port NUM  VLAN-Con NUM\n"
                . "  --------------------------------------------------------------------------\n"
                . "     1   smart     common             1               0              -\n"
                . "   100   smart     common             2               3              -\n"
                . "  --------------------------------------------------------------------------\n  Total: 2\n";
        } elseif ($cmd === 'display ont autofind all') {
            if (!$state['autofind']) {
                $out = "  Failure: The automatically found ONTs do not exist\n";
            } else {
                $s = '';
                foreach ($state['autofind'] as $i => [$sp, $sn, $v, $eq]) {
                    $s .= "   ----------------------------------------------------------------------------\n"
                        . "   Number              : " . ($i + 1) . "\n   F/S/P               : 0/{$sp}\n"
                        . "   Ont SN              : {$sn} ({$v}-" . substr($sn, 8) . ")\n   Password            : 0x00000000000000000000\n"
                        . "   Loid                : \n   VendorID            : {$v}\n   Ont Version         : 10C7.A\n"
                        . "   Ont SoftwareVersion : V5R019C00S050\n   Ont EquipmentID     : {$eq}\n"
                        . "   Ont autofind time   : 2026-11-04 08:30:1{$i}+03:00\n";
                }
                paged($c, $s . "   ----------------------------------------------------------------------------\n   The number of GPON autofind ONT is " . count($state['autofind']) . "\n");
            }
        } elseif ($cmd === 'display ont info 0') {
            // Real OLTs stop and ask for the rest of the parameters.
            send($c, "{ <cr>|ontid<U><0,127>|all<K> }:");
            readline_from($c);
            $out = "  % Parameter error, the error locates at '^'\n";
        } elseif ($cmd === 'display ont info 0 all' && $mode === 'config') {
            paged($c, ont_info_table($state['onts']));
        } elseif (preg_match('/^interface gpon 0\/(\d+)$/', $cmd, $m) && $mode === 'config') {
            $mode = $m[1];
        } elseif (preg_match('/^display ont optical-info (\d+) all$/', $cmd, $m) && is_numeric($mode)) {
            $s = "  -------------------------------------------------------------------------\n"
               . "  ONT    Rx      Tx      OLT Rx   Temper- Voltage Current\n"
               . "  ID     power   power   ONT      ature   (V)     (mA)\n"
               . "         (dBm)   (dBm)   power    (C)\n"
               . "                         (dBm)\n"
               . "  -------------------------------------------------------------------------\n";
            foreach ($state['onts'] as $k => [$sn, $desc, $run]) {
                [$sl, $p, $id] = explode('/', $k);
                if ($sl != $mode || $p != $m[1] || $run !== 'online') continue;
                $s .= sprintf("  %-6d -%d.03  2.27    -21.55   48      3.280   9\n", $id, 19 + $id * 9);
            }
            $out = $s . "  -------------------------------------------------------------------------\n";
        } elseif (preg_match('/^display ont optical-info (\d+) (\d+)$/', $cmd, $m) && is_numeric($mode)) {
            $k = "{$mode}/{$m[1]}/{$m[2]}";
            if (!isset($state['onts'][$k])) $out = "  Failure: The ONT does not exist\n";
            elseif ($state['onts'][$k][2] !== 'online') $out = "  Failure: The ONT is not online\n";
            else $out = "  -----------------------------------------------------------------------------\n"
                . "  ONU NNI port ID                        : 0\n  Module type                            : GPON\n"
                . "  Rx optical power(dBm)                  : -19.03\n  Tx optical power(dBm)                  : 2.27\n"
                . "  Laser bias current(mA)                 : 9\n  Temperature(C)                         : 48\n"
                . "  Voltage(V)                             : 3.280\n  OLT Rx ONT optical power(dBm)          : -21.55\n";
        } elseif (preg_match('/^display ont info (\d+) (\d+)$/', $cmd, $m) && is_numeric($mode)) {
            $k = "{$mode}/{$m[1]}/{$m[2]}";
            if (!isset($state['onts'][$k])) $out = "  Failure: The ONT does not exist\n";
            else {
                [$sn, $desc, $run] = $state['onts'][$k];
                paged($c, "  -----------------------------------------------------------------------------\n"
                    . "  F/S/P                   : 0/{$mode}/{$m[1]}\n  ONT-ID                  : {$m[2]}\n"
                    . "  Control flag            : {$state['onts'][$k][5]}\n  Run state               : {$run}\n  Config state            : normal\n"
                    . "  Match state             : match\n  SN                      : {$sn} (HWTC-" . substr($sn, 8) . ")\n"
                    . "  Description             : {$desc}\n  Last down cause         : " . ($state['onts'][$k][3] ?: '-') . "\n"
                    . "  Last up time            : 2026-11-04 07:10:02+03:00\n  Last down time          : 2026-11-04 07:05:44+03:00\n"
                    . "  ONT online duration     : 0 day(s), 1 hour(s), 20 minute(s), 3 second(s)\n"
                    . "  Line profile ID         : 10\n  Line profile name       : INTERNET_100\n");
            }
        } elseif (preg_match('/^ont add (\d+) sn-auth ([0-9A-F]{16}) omci ont-lineprofile-id (\d+) ont-srvprofile-id (\d+) desc "([^"]*)"$/', $cmd, $m) && is_numeric($mode)) {
            $exists = false;
            foreach ($state['onts'] as $o) if ($o[0] === $m[2]) $exists = true;
            if ($exists) {
                $out = "  Failure: SN already exists\n";
            } else {
                $id = 0;
                while (isset($state['onts']["{$mode}/{$m[1]}/{$id}"])) $id++;
                $model = '';
                foreach ($state['autofind'] as $a) if ($a[1] === $m[2]) $model = $a[3];
                $state['onts']["{$mode}/{$m[1]}/{$id}"] = [$m[2], $m[5], 'online', '', $model, 'active'];
                $state['autofind'] = array_values(array_filter($state['autofind'], function ($a) use ($m) { return $a[1] !== $m[2]; }));
                $out = "  Number of ONTs that can be added: 1, success: 1\n  PortID :{$m[1]}, ONTID :{$id}\n";
            }
        } elseif (preg_match('/^ont port native-vlan (\d+) (\d+) eth 1 vlan (\d+) priority 0$/', $cmd) && is_numeric($mode)) {
        } elseif (preg_match('/^service-port vlan (\d+) gpon 0\/(\d+)\/(\d+) ont (\d+) gemport (\d+) multi-service user-vlan \d+ tag-transform translate( inbound traffic-table index (\d+) outbound traffic-table index (\d+))?$/', $cmd, $m) && $mode === 'config') {
            if (isset($m[7]) && (!isset($state['tt'][(int)$m[7]]) || !isset($state['tt'][(int)$m[8]]))) $out = "  Failure: The traffic table does not exist\n";
            else $state['sp'][max(array_keys($state['sp']) ?: [0]) + 1] = ["{$m[2]}/{$m[3]}/{$m[4]}", (int)$m[1], (int)$m[5], $m[7] ?? '-', $m[8] ?? '-'];
        } elseif ((preg_match('/^display service-port port 0\/(\d+)\/(\d+) ont (\d+)$/', $cmd, $m) || $cmd === 'display service-port all') && $mode === 'config') {
            $s = "  INDEX VLAN VLAN     PORT F/ S/ P VPI  VCI   FLOW  FLOW       RX   TX   STATE\n"
               . "        ID   ATTR     TYPE                    TYPE  PARA\n"
               . "  -----------------------------------------------------------------------------\n";
            $n = 0;
            foreach ($state['sp'] as $i => [$k, $vlan, $gem, $in, $outT]) {
                if ($m && $k !== "{$m[1]}/{$m[2]}/{$m[3]}") continue;
                [$sl, $p, $id] = explode('/', $k);
                $s .= sprintf("  %5d %4d common   gpon 0/%d /%d  %-4d %-5d vlan  %-10d %-4s %-4s up\n", $i, $vlan, $sl, $p, $id, $gem, $vlan, $in, $outT);
                $n++;
            }
            $out = $n ? $s . "  -----------------------------------------------------------------------------\n   Total : {$n}  (Up/Down :    {$n}/0)\n" : "  Failure: No service virtual port can be operated\n";
            if ($n > 8) { paged($c, $out); $out = ''; }
        } elseif (preg_match('/^undo service-port (\d+)$/', $cmd, $m) && $mode === 'config') {
            unset($state['sp'][(int)$m[1]]);
        } elseif (preg_match('/^ont delete (\d+) (\d+)$/', $cmd, $m) && is_numeric($mode)) {
            $k = "{$mode}/{$m[1]}/{$m[2]}";
            if (in_array($k, array_column($state['sp'], 0), true)) $out = "  Failure: This configured object has some service virtual ports\n";
            elseif (!isset($state['onts'][$k])) $out = "  Failure: The ONT does not exist\n";
            else { unset($state['onts'][$k]); $out = "  Number of ONTs that can be deleted: 1, success: 1\n"; }
        } elseif (preg_match('/^ont reset (\d+) (\d+)$/', $cmd, $m) && is_numeric($mode)) {
            send($c, "  Are you sure to reset the ONT(s)? (y/n)[n]:");
            $a = readline_from($c);
            send($c, $a . "\n");
            if (!isset($state['onts']["{$mode}/{$m[1]}/{$m[2]}"])) $out = "  Failure: The ONT does not exist\n";
        } elseif (preg_match('/^ont modify (\d+) (\d+) desc "([^"]*)"$/', $cmd, $m) && is_numeric($mode)) {
            $k = "{$mode}/{$m[1]}/{$m[2]}";
            if (!isset($state['onts'][$k])) $out = "  Failure: The ONT does not exist\n"; else $state['onts'][$k][1] = $m[3];
        } elseif (preg_match('/^display ont info summary 0\/(\d+)\/(\d+)$/', $cmd, $m) && $mode === 'config') {
            $rows = array_filter($state['onts'], function ($k) use ($m) { return strpos($k, "{$m[1]}/{$m[2]}/") === 0; }, ARRAY_FILTER_USE_KEY);
            if (!$rows) {
                $out = "  Failure: There is no ONT in the port\n";
            } else {
                $s = "  ----------------------------------------------------------------------------\n"
                   . "  In port 0/{$m[1]}/{$m[2]}, the total of ONTs are: " . count($rows) . ", online: " . count(array_filter($rows, function ($o) { return $o[2] === 'online'; })) . "\n"
                   . "  ----------------------------------------------------------------------------\n"
                   . "  ONT  Run     Last                Last                Last\n"
                   . "  ID   State   UpTime              DownTime            DownCause\n"
                   . "  ----------------------------------------------------------------------------\n";
                foreach ($rows as $k => $o) {
                    $id = (int)explode('/', $k)[2];
                    $s .= sprintf("  %-4d %-7s %-19s %-19s %s\n", $id, $o[2], '2026-11-04 07:10:02+03:00', $o[3] ? '2026-11-04 07:05:44+03:00' : '-', $o[3] ?: '-');
                }
                $s .= "  ----------------------------------------------------------------------------\n"
                    . "  ONT        SN        Type          Distance Rx/Tx power  Description\n"
                    . "  ID                                    (m)      (dBm)\n"
                    . "  ----------------------------------------------------------------------------\n";
                foreach ($rows as $k => $o) {
                    $id = (int)explode('/', $k)[2];
                    $pw = $o[2] === 'online' ? sprintf('-%d.03/2.27', 19 + $id * 9) : '-/-';
                    $s .= sprintf("  %-3d %s %-15s %-6s %-12s %s\n", $id, $o[0], $o[4] ?: '-', $o[2] === 'online' ? 1250 + $id * 300 : '-', $pw, $o[1]);
                }
                paged($c, $s . "  ----------------------------------------------------------------------------\n");
            }
        } elseif ($cmd === 'display traffic table ip from-index 0' && $mode === 'config') {
            $s = "  ----------------------------------------------------------------------------\n"
               . "  TID CIR(kbps)  CBS(bytes)  PIR(kbps)  PBS(bytes)  Pri  Copy-policy  Pri-Policy\n"
               . "  ----------------------------------------------------------------------------\n";
            foreach ($state['tt'] as $i => $k) $s .= sprintf("  %3d %9d %11d %10d %11d    0  -            local-pri\n", $i, $k, $k * 32, $k, $k * 64);
            $out = $s . "  ----------------------------------------------------------------------------\n  Total Num : " . count($state['tt']) . "\n";
        } elseif (preg_match('/^traffic table ip index (\d+) cir (\d+) pir (\d+) priority 0 priority-policy local-setting$/', $cmd, $m) && $mode === 'config') {
            if (isset($state['tt'][(int)$m[1]])) $out = "  Failure: The traffic table already exists\n";
            else { $state['tt'][(int)$m[1]] = (int)$m[2]; ksort($state['tt']); $out = "  Create traffic descriptor record successfully\n  TD Index : {$m[1]}\n"; }
        } elseif (preg_match('/^ont (activate|deactivate) (\d+) (\d+)$/', $cmd, $m) && is_numeric($mode)) {
            $k = "{$mode}/{$m[2]}/{$m[3]}";
            if (!isset($state['onts'][$k])) $out = "  Failure: The ONT does not exist\n";
            else $state['onts'][$k][5] = $m[1] === 'activate' ? 'active' : 'deactivated';
        } elseif (preg_match('/^ont modify (\d+) (\d+) sn ([0-9A-F]{16})$/', $cmd, $m) && is_numeric($mode)) {
            $k = "{$mode}/{$m[1]}/{$m[2]}";
            if (!isset($state['onts'][$k])) $out = "  Failure: The ONT does not exist\n";
            else { $state['onts'][$k][0] = $m[3]; $state['onts'][$k][4] = ''; }
        } else {
            $out = "                  ^\n  % Unknown command, the error locates at '^'\n";
        }
        send($c, $out . "\n" . prompt_for($mode));
    }
    @fclose($c);
}
