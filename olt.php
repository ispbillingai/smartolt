<?php
require_once __DIR__ . '/backend/bootstrap.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$tabs = ['details' => ['OLT details', 'info-circle'], 'cards' => ['OLT cards', 'th-large'], 'pon' => ['PON ports', 'dot-circle-o'],
    'uplink' => ['Uplink', 'arrow-up'], 'vlans' => ['VLANs', 'tags'], 'profiles' => ['Profiles', 'file-text-o'], 'cli' => ['CLI', 'terminal']];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'details';
$here = "olt.php?id={$id}&tab=";
$cliOut = null;
$cliCmd = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $tab = isset($tabs[$_POST['tab'] ?? '']) ? $_POST['tab'] : $tab;
    try {
        switch ($_POST['do'] ?? '') {
            case 'snmp':
                $target = $mgr->olt($id);
                if (!$target) throw new InvalidArgumentException('OLT not found.');
                $result = $mgr->pollSnmp($target);
                back($here . 'uplink', $result['status'] === 'online' ? 'success' : 'error', $result['status'] === 'online' ? 'SNMP read completed.' : ($result['error'] ?? 'SNMP is disabled.'));
            case 'vlan_add':
                $mgr->setVlan($id, (int)($_POST['vlan'] ?? 0), true, (string)($_POST['desc'] ?? ''));
                back($here . 'vlans', 'success', 'VLAN ' . (int)$_POST['vlan'] . ' added on the OLT. Tag it on your uplink port too, so it reaches your router.');
            case 'vlan_delete':
                $mgr->setVlan($id, (int)($_POST['vlan'] ?? 0), false);
                back($here . 'vlans', 'success', 'VLAN ' . (int)$_POST['vlan'] . ' deleted from the OLT.');
            case 'cli':
                $cliCmd = (string)($_POST['command'] ?? '');
                if (($_POST['check'] ?? '') !== '') {
                    $key = (string)$_POST['check'];
                    $cliCmd = Huawei::DIAG[$key][1] ?? '';
                    $cliOut = $mgr->diag($id, $key, $_POST);
                } else {
                    $cliOut = $mgr->cli($id, $cliCmd);
                }
                break;
        }
    } catch (Throwable $e) {
        if (($_POST['do'] ?? '') === 'cli') { $cliOut = ''; flash('error', $e->getMessage()); } else back($here . $tab, 'error', $e->getMessage());
    }
}

$row = $mgr->olt($id);
if (!$row) {
    back('olts.php', 'error', 'OLT not found.');
}
$olt = OltManager::publicOlt($row);
$counts = $mgr->counts($id);
$ports = $mgr->ports($id);
$vpn = $olt['conn_type'] === 'vpn' ? $mgr->vpnStatus($row) : null;
$script = $vpn !== null ? $mgr->mikrotikScript($row) : '';
$perBoard = [];
foreach ($ports as $p) {
    $perBoard[(int)$p['slot']]['total'] = ($perBoard[(int)$p['slot']]['total'] ?? 0) + (int)$p['total'];
    $perBoard[(int)$p['slot']]['online'] = ($perBoard[(int)$p['slot']]['online'] ?? 0) + (int)$p['online'];
}
$vlanUse = [];
foreach ($mgr->listOnus(['olt_id' => $id]) as $u) if ($u['vlan']) $vlanUse[(int)$u['vlan']] = ($vlanUse[(int)$u['vlan']] ?? 0) + 1;
$profileUse = ['line' => [], 'srv' => []];
foreach ($mgr->listOnus(['olt_id' => $id]) as $u) {
    if ($u['line_profile'] !== null) $profileUse['line'][(int)$u['line_profile']] = ($profileUse['line'][(int)$u['line_profile']] ?? 0) + 1;
}
$masked = '<span style="letter-spacing:2px">**********</span>';

$pageTitle = 'OLT settings';
include __DIR__ . '/backend/templates/header.php';
?>
<p style="margin:6px 0 4px"><?php echo btn('Back to OLTs list', 'blue', 'olts.php'); ?></p>
<?php echo flash_messages(); ?>
<?php if ($olt['status'] === 'error') echo alert('OLT unreachable. ' . $olt['last_error'], 'err'); ?>
<div class="so-tabbar">
    <?php foreach ($tabs as $k => [$label, $icon]): ?><a href="<?php echo e($here . $k); ?>" class="<?php echo $tab === $k ? 'on' : ''; ?>"><i class="fa fa-<?php echo $icon; ?>"></i> <?php echo $label; ?></a><?php endforeach; ?>
</div>

<?php if ($tab === 'details'): ?>
<div class="so-inline" style="margin-bottom:14px">
    <?php echo btn('Edit OLT settings', 'blue', 'olts.php?edit=' . $id); ?>
    <?php echo post_btn('refresh', ['id' => $id, 'back' => 'olt'], 'Refresh', 'blue', 'refresh', '', 'Reading the OLT. On a big OLT this takes up to a minute...', 'olts.php'); ?>
    <?php echo btn('See history', 'blue', 'info.php?olt=' . $id); ?>
    <?php echo btn('CLI', 'green', $here . 'cli', 'terminal'); ?>
    <a href="#" data-open="soSave" class="so-btn so-btn-blue"><i class="fa fa-floppy-o"></i> Save config</a>
    <?php echo btn('Unconfigured', 'green', 'unconfigured.php?olt=' . $id); ?>
</div>
<div class="so-two">
    <div>
        <table class="so-table" style="font-size:14px">
            <thead><tr><th style="width:40%">OLT setting</th><th>Value</th></tr></thead>
            <tbody>
                <tr><td>Name</td><td><?php echo e($olt['name']); ?></td></tr>
                <tr><td>OLT IP</td><td><?php echo e($olt['conn_type'] === 'vpn' ? $olt['lan_ip'] : $olt['host']); ?></td></tr>
                <tr><td>Connection</td><td><?php if ($vpn !== null): ?>VPN tunnel <span class="so-mono"><?php echo e($olt['vpn_user']); ?></span>, tunnel IP <?php echo badge($olt['vpn_ip'], 'blue'); ?>
                    <?php echo $vpn['connected'] ? '<span style="color:var(--green)">VPN connected</span>' : '<span style="color:var(--red)">VPN disconnected</span> <a href="#vpn">Diagnose</a>'; ?>
                    <?php else: ?>Direct, Telnet to <?php echo e($olt['host'] . ':' . $olt['telnet_port']); ?><?php endif; ?></td></tr>
                <tr><td>Connection protocol</td><td>Telnet</td></tr>
                <tr><td>SNMP monitoring</td><td><?php echo empty($olt['snmp_enabled']) ? 'Disabled' : badge($olt['snmp_status'], $olt['snmp_status'] === 'online' ? 'green' : 'orange') . ' · UDP ' . (int)$olt['snmp_port'] . ' · <a href="' . e($here . 'uplink') . '">Test and view interfaces</a>'; ?></td></tr>
                <?php if (!empty($olt['snmp_enabled'])): ?><tr><td>SNMP last checked</td><td><?php echo e($olt['snmp_checked_at'] ?: 'Not checked yet'); ?><?php if ($olt['snmp_error']) echo '<br>' . e($olt['snmp_error']); ?></td></tr><?php endif; ?>
                <tr><td>Telnet TCP port</td><td><?php echo (int)($olt['conn_type'] === 'vpn' ? $olt['lan_port'] : $olt['telnet_port']); ?></td></tr>
                <tr><td>OLT telnet username</td><td><?php echo e($olt['username']); ?></td></tr>
                <tr><td>OLT telnet password</td><td><?php echo $masked; ?></td></tr>
                <tr><td>IPTV module</td><td>Disabled</td></tr>
                <tr><td>OLT hardware version</td><td><?php echo $olt['model'] !== '' ? 'Huawei-' . e($olt['model']) : 'Not detected yet'; ?></td></tr>
                <tr><td>OLT software version</td><td><?php echo $olt['version'] !== '' ? e($olt['version']) . ' (Detected)' : 'Not detected yet'; ?></td></tr>
                <tr><td>Supported PON types</td><td>GPON</td></tr>
                <tr><td>Signal RX level "warning" (dBm)</td><td><?php echo OltManager::SIGNAL_WARNING; ?></td></tr>
                <tr><td>Signal RX level "critical" (dBm)</td><td><?php echo OltManager::SIGNAL_CRITICAL; ?></td></tr>
                <tr><td>Last read</td><td><?php echo ago($olt['last_polled_at']); ?></td></tr>
                <tr><td>Configuration saved</td><td><?php echo $olt['saved_at'] ? ago($olt['saved_at']) : 'Not from SmartOLT yet'; ?></td></tr>
                <tr><td>ONUs</td><td><?php echo (int)$counts['online']; ?> online of <?php echo (int)$counts['total']; ?>, <?php echo (int)$counts['waiting']; ?> waiting authorization</td></tr>
            </tbody>
        </table>
    </div>
    <div>
        <div class="so-olt-pic" style="padding-top:0"><?php echo olt_picture(max(8, min(21, count($olt['boards']) ?: 16))); ?></div>
        <div class="so-kv" style="margin-bottom:8px"><span><i class="fa fa-cogs"></i> Uptime</span><i><?php echo e($olt['uptime'] ?: 'N/A'); ?></i></div>
        <div class="so-kv"><span><i class="fa fa-thermometer-half"></i> Temperature</span><i><?php echo $olt['temperature'] !== '' ? e($olt['temperature']) . '°C' : 'N/A'; ?></i></div>
    </div>
</div>
<?php if ($vpn !== null): ?>
<div id="vpn" style="margin-top:18px"><?php echo panel_open('SmartOLT VPN', $vpn['connected'] ? badge('Connected', 'green') . ' from ' . e($vpn['from']) . ', since ' . e($vpn['since']) : badge('Waiting for your MikroTik', 'orange'), 'lock'); ?>
    <?php if (!empty($vpn['error'])) echo alert($vpn['error'], 'err'); ?>
    <p style="margin-top:0">Paste this into the terminal of the MikroTik your OLT is connected to (Winbox, New Terminal). It connects that router to SmartOLT by VPN and lets SmartOLT reach the OLT at <span class="so-mono"><?php echo e($olt['lan_ip'] . ':' . $olt['lan_port']); ?></span> through it, and nothing else. Pasting it again is safe.</p>
    <pre class="so-pre" id="vpnScript"><?php echo e($script); ?></pre>
    <div class="so-inline">
        <button type="button" class="so-btn so-btn-grey" data-copy="vpnScript"><i class="fa fa-copy"></i> Copy</button>
        <?php echo btn('Check again', 'blue', 'olt.php?id=' . $id, 'refresh'); ?>
        <?php if (!$vpn['connected']): ?><span class="so-muted" style="font-size:14px">Once connected the router shows <b>smartolt-olt<?php echo $id; ?></b> as running under Interfaces. If not, check it can reach the internet on TCP port 1194.</span><?php endif; ?>
    </div>
<?php echo panel_close(); ?></div>
<?php endif; ?>

<?php elseif ($tab === 'cards'): ?>
<div class="so-inline" style="margin-bottom:14px"><?php echo post_btn('refresh', ['id' => $id, 'back' => 'olt'], 'Refresh OLT cards info', 'blue', '', '', 'Reading the OLT...', 'olts.php'); ?></div>
<?php if (!$olt['boards']): ?><p class="so-muted">Not read yet. Press Refresh OLT cards info.</p><?php else: ?>
<div class="so-table-wrap"><table class="so-table" style="font-size:14px">
    <thead><tr><th>Slot</th><th>Card</th><th>Status</th><th>Role</th><th>PON ports</th><th>ONUs online</th></tr></thead>
    <tbody><?php foreach ($olt['boards'] as $b): $pb = $perBoard[(int)$b['slot']] ?? null; ?>
        <tr><td>0/<?php echo (int)$b['slot']; ?></td><td><?php echo e($b['name']); ?></td>
            <td><?php echo stripos($b['status'], 'normal') !== false ? badge(str_replace('_', ' ', $b['status']), 'green') : badge(str_replace('_', ' ', $b['status']), 'red'); ?></td>
            <td><?php echo $b['gpon'] ? 'GPON service board' : (stripos($b['name'], 'SCU') !== false ? 'Control board' : 'Uplink or power'); ?></td>
            <td><?php echo $b['gpon'] ? (preg_match('/GPB[DH]/i', $b['name']) ? 8 : 16) : ''; ?></td>
            <td><?php echo $b['gpon'] ? (int)($pb['online'] ?? 0) . ' / ' . (int)($pb['total'] ?? 0) : ''; ?></td></tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<?php elseif ($tab === 'pon'): ?>
<div class="so-inline" style="margin-bottom:14px">
    <?php echo post_btn('refresh', ['id' => $id, 'back' => 'olt'], 'Refresh PON ports info', 'blue', '', '', 'Reading the OLT...', 'olts.php'); ?>
    <?php echo btn('Unconfigured ONUs', 'green', 'unconfigured.php?olt=' . $id); ?>
</div>
<div class="so-table-wrap"><table class="so-table" style="font-size:14px">
    <thead><tr><th>PON port</th><th>Type</th><th>ONUs</th><th>Online</th><th>Load</th><th>Power fail</th><th>LOS</th><th>Average signal</th><th>Weakest</th><th></th></tr></thead>
    <tbody>
    <?php $occ = array_filter($mgr->ponOccupancy($id), function ($p) { return true; });
    if (!$occ): ?><tr><td colspan="10" class="so-muted">No PON ports read yet.</td></tr><?php endif;
    $byPon = [];
    foreach ($ports as $p) $byPon["{$p['frame']}/{$p['slot']}/{$p['port']}"] = $p;
    usort($occ, function ($a, $b) { return [$a['slot'], $a['port']] <=> [$b['slot'], $b['port']]; });
    foreach ($occ as $p): $pon = "{$p['frame']}/{$p['slot']}/{$p['port']}"; $x = $byPon[$pon] ?? null; $pct = round($p['total'] * 100 / 128); ?>
        <tr><td class="so-mono"><?php echo $pon; ?></td><td>GPON</td><td><?php echo (int)$p['total']; ?></td><td><?php echo (int)$p['online']; ?></td>
            <td><div class="so-bar" style="width:90px"><span style="width:<?php echo max(2, $pct); ?>%;background:<?php echo $pct >= 90 ? 'var(--red)' : ($pct >= 70 ? 'var(--orange)' : 'var(--green)'); ?>"></span></div><small><?php echo (int)$p['total']; ?> / 128</small></td>
            <td><?php echo $x && (int)$x['power_fail'] ? '<a href="onus.php?olt=' . $id . '&pon=' . $pon . '&status=power_fail">' . (int)$x['power_fail'] . '</a>' : '0'; ?></td>
            <td><?php echo $x && (int)$x['los'] ? '<a href="onus.php?olt=' . $id . '&pon=' . $pon . '&status=los" style="color:var(--red)">' . (int)$x['los'] . '</a>' : '0'; ?></td>
            <td><?php echo $x ? signal_html($x['avg_rx']) : '-'; ?></td><td><?php echo $x ? signal_html($x['min_rx']) : '-'; ?></td>
            <td class="num"><?php echo $p['total'] ? btn('ONUs', 'blue', 'onus.php?olt=' . $id . '&pon=' . $pon) . ' ' . btn('', 'grey', 'graphs.php?olt=' . $id . '&pon=' . $pon, 'area-chart', 'title="Graph"') : ''; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<?php elseif ($tab === 'uplink'): ?>
<div class="so-inline" style="margin-bottom:14px"><?php echo btn('Run display command', 'blue', $here . 'cli'); ?></div>
<?php include __DIR__ . '/backend/templates/snmp.php'; ?>

<?php elseif ($tab === 'vlans'): ?>
<div class="so-inline" style="margin-bottom:8px"><button type="button" class="so-btn so-btn-blue" data-open="vlanModal"><i class="fa fa-plus"></i> Add VLAN</button></div>
<p class="so-muted" style="font-size:14px">VLANs added here are not tagged on the uplink ports automatically. Tag them on the uplink interface too, so they reach your router.</p>
<div class="so-table-wrap"><table class="so-table" style="font-size:14px">
    <thead><tr><th>VLAN-ID</th><th>Type</th><th>Description</th><th>ONUs</th><th>Action</th></tr></thead>
    <tbody>
    <?php $vl = array_values(array_filter($olt['profiles']['vlans'] ?? [], function ($v) { return (int)$v['id'] > 1; }));
    if (!$vl): ?><tr><td colspan="5" class="so-muted">No VLANs read from the OLT yet.</td></tr><?php endif;
    foreach ($vl as $v): $n = $vlanUse[(int)$v['id']] ?? 0; ?>
        <tr><td><?php echo (int)$v['id']; ?></td><td><?php echo e($v['type'] ?? 'smart'); ?></td><td><?php echo e($v['desc'] ?? ''); ?></td>
            <td><a href="onus.php?olt=<?php echo $id; ?>&vlan=<?php echo (int)$v['id']; ?>"><?php echo $n; ?></a></td>
            <td><?php echo post_btn('vlan_delete', ['id' => $id, 'vlan' => $v['id'], 'tab' => 'vlans'], 'Delete', 'red', '', 'Delete VLAN ' . (int)$v['id'] . ' from the OLT?', 'Deleting the VLAN on the OLT...'); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<div class="so-modal" id="vlanModal">
    <form method="POST" class="so-modal-box" data-busy="Adding the VLAN on the OLT...">
        <div class="so-modal-h">Add VLAN <button type="button" data-close>&times;</button></div>
        <div class="so-modal-b"><?php echo csrf_field(); ?><input type="hidden" name="do" value="vlan_add"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="tab" value="vlans">
            <div class="so-field"><label class="so-label">VLAN-ID</label><input name="vlan" type="number" min="2" max="4094" required class="so-input"></div>
            <div class="so-field"><label class="so-label">Description</label><input name="desc" maxlength="30" class="so-input" placeholder="For example pppoe vlan"></div></div>
        <div class="so-modal-f"><button type="button" class="so-btn so-btn-dark" data-close>Cancel</button><button class="so-btn so-btn-green">Add VLAN</button></div>
    </form>
</div>

<?php elseif ($tab === 'profiles'): ?>
<div class="so-inline" style="margin-bottom:14px"><?php echo post_btn('refresh', ['id' => $id, 'back' => 'olt'], 'Scan OLT profiles', 'orange', 'search', '', 'Reading the OLT...', 'olts.php'); ?></div>
<h3 style="font-size:16px">Line profiles</h3>
<table class="so-table" style="font-size:14px;margin-bottom:18px"><thead><tr><th>Profile ID</th><th>Profile name</th><th>Bindings on the OLT</th><th>ONUs in SmartOLT</th></tr></thead><tbody>
    <?php foreach ($olt['profiles']['line'] as $p): ?><tr><td><?php echo (int)$p['id']; ?></td><td><?php echo e($p['name']); ?></td><td><?php echo (int)$p['bindings']; ?></td><td><?php echo (int)($profileUse['line'][(int)$p['id']] ?? 0); ?></td></tr><?php endforeach; ?>
    <?php if (!$olt['profiles']['line']) echo '<tr><td colspan="4" class="so-muted">No profiles found on the OLT</td></tr>'; ?>
</tbody></table>
<h3 style="font-size:16px">Service profiles</h3>
<table class="so-table" style="font-size:14px;margin-bottom:18px"><thead><tr><th>Profile ID</th><th>Profile name</th><th>Bindings on the OLT</th></tr></thead><tbody>
    <?php foreach ($olt['profiles']['srv'] as $p): ?><tr><td><?php echo (int)$p['id']; ?></td><td><?php echo e($p['name']); ?></td><td><?php echo (int)$p['bindings']; ?></td></tr><?php endforeach; ?>
    <?php if (!$olt['profiles']['srv']) echo '<tr><td colspan="3" class="so-muted">No profiles found on the OLT</td></tr>'; ?>
</tbody></table>
<h3 style="font-size:16px">Speed tables (traffic tables)</h3>
<table class="so-table" style="font-size:14px"><thead><tr><th>Index</th><th>CIR</th><th>PIR</th></tr></thead><tbody>
    <?php foreach ($olt['traffic'] as $idx => $t): ?><tr><td><?php echo (int)$idx; ?></td><td><?php echo e(fmt_speed($t['cir'])); ?></td><td><?php echo e(fmt_speed($t['pir'])); ?></td></tr><?php endforeach; ?>
    <?php if (!$olt['traffic']) echo '<tr><td colspan="3" class="so-muted">None read yet</td></tr>'; ?>
</tbody></table>

<?php else: ?>
<div class="so-two">
    <div>
        <form method="POST" class="so-box" data-busy="Asking the OLT...">
            <?php echo csrf_field(); ?><input type="hidden" name="do" value="cli"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="tab" value="cli">
            <label class="so-label">Display command</label>
            <div class="so-inline"><input name="command" class="so-input so-mono" style="flex:1" value="<?php echo e($cliCmd); ?>" placeholder="display board 0" required>
                <button class="so-btn so-btn-green"><i class="fa fa-play"></i> Run</button></div>
            <div class="so-help">Only display commands run here, so nothing on the OLT can change by accident.</div>
        </form>
        <?php if ($cliOut !== null): ?>
            <?php echo panel_open($cliCmd !== '' ? $cliCmd : 'Output', '<button type="button" data-copy="cliOut"><i class="fa fa-copy"></i> Copy</button>', 'terminal'); ?>
                <pre class="so-pre" id="cliOut"><?php echo e($cliOut !== '' ? $cliOut : 'Nothing returned.'); ?></pre>
            <?php echo panel_close(); ?>
        <?php endif; ?>
    </div>
    <div><?php echo panel_open('Quick checks', '', 'list'); ?>
        <form method="POST" data-busy="Asking the OLT...">
            <?php echo csrf_field(); ?><input type="hidden" name="do" value="cli"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="tab" value="cli">
            <div class="so-inline" style="margin-bottom:8px">
                <?php foreach (['frame' => 'Frame', 'slot' => 'Board', 'port' => 'Port', 'ont' => 'ONU'] as $p => $l): ?>
                    <input name="<?php echo $p; ?>" type="number" min="0" max="255" class="so-input" style="width:72px;min-width:0" placeholder="<?php echo $l; ?>" value="<?php echo $p === 'frame' ? '0' : ''; ?>">
                <?php endforeach; ?>
            </div>
            <?php foreach (Huawei::DIAG as $k => $d): ?>
                <button class="so-btn so-btn-line so-btn-sm" style="margin:0 4px 6px 0" name="check" value="<?php echo $k; ?>" title="<?php echo e($d[1]); ?>"><?php echo e($d[0]); ?></button>
            <?php endforeach; ?>
        </form>
    <?php echo panel_close(); ?></div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
