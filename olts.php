<?php
require_once __DIR__ . '/backend/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = $_POST['do'] ?? '';
    try {
        if ($do === 'save') {
            $id = $mgr->saveOlt($_POST);
            $saved = $mgr->olt($id);
            if ($saved['conn_type'] === 'vpn' && !$mgr->vpnStatus($saved)['connected']) {
                // Nothing to read until the router dials in; its page has the commands for it.
                back("olt.php?id={$id}", 'success', 'OLT saved. Now connect your MikroTik to the SmartOLT VPN with the commands below.');
            }
            // Read it straight away so the ISP sees at once whether the login works.
            $s = $mgr->refresh($mgr->olt($id));
            $mgr->log($id, 'olt.refresh', $_POST['name'] ?? '', true, 'ok');
            back('olts.php', 'success', "OLT saved and read: {$s['onus']} ONUs found, {$s['online']} online.");
        }
        if ($do === 'refresh') {
            $olt = $mgr->olt((int)$_POST['id']);
            if (!$olt) back('olts.php', 'error', 'OLT not found.');
            $s = $mgr->refresh($olt);
            $mgr->log((int)$olt['id'], 'olt.refresh', $olt['name'], true, 'ok');
            $to = ($_POST['back'] ?? '') === 'olt' ? 'olt.php?id=' . (int)$olt['id'] : 'olts.php';
            back($to, 'success', "{$olt['name']} read: {$s['onus']} ONUs, {$s['online']} online" . ($s['removed'] ? ", {$s['removed']} no longer on the OLT" : '') . '.');
        }
        if ($do === 'delete') {
            $mgr->deleteOlt((int)$_POST['id']);
            back('olts.php', 'success', 'OLT removed. Nothing was changed on the OLT itself.');
        }
    } catch (InvalidArgumentException $e) {
        back('olts.php' . ($do === 'save' ? (!empty($_POST['id']) ? '?edit=' . (int)$_POST['id'] : '?add=1') : ''), 'error', $e->getMessage());
    } catch (Throwable $e) {
        if (isset($id) || isset($olt)) {
            $mgr->log((int)($id ?? $olt['id']), 'olt.refresh', (string)($_POST['name'] ?? $olt['name'] ?? ''), false, $e->getMessage());
        }
        back('olts.php', 'error', ($do === 'save' ? 'The OLT was saved, but SmartOLT could not read it: ' : 'Could not read the OLT: ') . $e->getMessage());
    }
}


$olts = $mgr->olts();
$editing = isset($_GET['edit']) ? $mgr->olt((int)$_GET['edit']) : null;
$showForm = $editing || isset($_GET['add']) || !$olts;
$isVpn = ($editing['conn_type'] ?? 'vpn') === 'vpn';

// Filters on the list
$fq = trim((string)($_GET['q'] ?? ''));
$fStatus = (string)($_GET['status'] ?? '');
$fVpn = (string)($_GET['vpn'] ?? '');
$list = array_values(array_filter($olts, function ($o) use ($fq, $fStatus, $fVpn) {
    if ($fq !== '' && stripos($o['name'] . ' ' . $o['host'] . ' ' . $o['lan_ip'] . ' ' . $o['telnet_port'] . ' ' . $o['model'], $fq) === false) return false;
    if ($fStatus !== '' && ($fStatus === 'online') !== ($o['status'] === 'online')) return false;
    if ($fVpn !== '' && ($fVpn === 'yes') !== ($o['conn_type'] === 'vpn')) return false;
    return true;
}));
if (isset($_GET['export'])) {
    csv_download('olts', ['ID', 'Name', 'OLT IP', 'TCP', 'Connection', 'Hardware', 'Software', 'Status', 'ONUs', 'Online'], array_map(function ($o) {
        return [$o['id'], $o['name'], $o['conn_type'] === 'vpn' ? $o['lan_ip'] : $o['host'], $o['conn_type'] === 'vpn' ? $o['lan_port'] : $o['telnet_port'],
            $o['conn_type'] === 'vpn' ? 'VPN' : 'Direct', $o['model'], $o['version'], $o['status'], $o['onu_count'], $o['onu_online']];
    }, $list));
}

$pageTitle = $showForm ? ($editing ? 'Edit OLT' : 'Add OLT') : 'OLTs';
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo flash_messages(); ?>
<?php if ($showForm): ?>
<div class="so-pagehead"><h1><i class="fa fa-server"></i> <?php echo $editing ? 'Edit OLT settings' : 'Add OLT'; ?></h1><?php echo $olts ? btn('Back to OLTs list', 'grey', 'olts.php', 'list') : ''; ?></div>
<form method="POST" class="so-section so-form" autocomplete="off" data-busy="Saving and logging in to the OLT..." style="max-width:none">
    <?php echo csrf_field(); ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?php echo (int)($editing['id'] ?? 0); ?>">
    <div class="so-row"><label>Name</label><input class="so-input" name="name" required maxlength="100" placeholder="For example Main OLT" value="<?php echo e($editing['name'] ?? ''); ?>"></div>
    <div class="so-row"><label>Connection</label><select name="conn_type" id="connType" class="so-select">
        <option value="vpn" <?php echo $isVpn ? 'selected' : ''; ?>>Through the SmartOLT VPN (no public IP needed)</option>
        <option value="direct" <?php echo $isVpn ? '' : 'selected'; ?>>Directly, on a public IP or port forward</option></select></div>
    <div class="so-row conn-vpn"><label>OLT IP</label><input class="so-input" name="lan_ip" maxlength="45" placeholder="The OLT's own IP as your MikroTik sees it, for example 192.168.100.2" value="<?php echo e($editing['lan_ip'] ?? ''); ?>"></div>
    <div class="so-row conn-vpn"><label>Telnet TCP port</label><input class="so-input" name="lan_port" type="number" min="1" max="65535" value="<?php echo (int)($editing['lan_port'] ?? 23); ?>"></div>
    <div class="so-row conn-direct"><label>OLT IP or FQDN</label><input class="so-input" name="host" maxlength="190" placeholder="Public IP of the OLT or of your router" value="<?php echo e(($editing['conn_type'] ?? '') === 'direct' ? $editing['host'] : ''); ?>"></div>
    <div class="so-row conn-direct"><label>Telnet TCP port</label><input class="so-input" name="telnet_port" type="number" min="1" max="65535" value="<?php echo (int)(($editing['conn_type'] ?? '') === 'direct' ? $editing['telnet_port'] : 23); ?>"></div>
    <div class="so-row"><label>Connection protocol</label><div>Telnet</div></div>
    <div class="so-row"><label>OLT telnet username</label><input class="so-input" name="username" required maxlength="100" value="<?php echo e($editing['username'] ?? ''); ?>"></div>
    <div class="so-row"><label>OLT telnet password</label><input class="so-input" name="password" type="password" maxlength="100" autocomplete="new-password" <?php echo $editing ? 'placeholder="Leave blank to keep the saved one"' : 'required'; ?>></div>
    <div class="so-row"><label>IPTV provisioning</label><div class="so-muted">Not supported by this driver. Existing multi-service ONUs are protected from single-service replacement.</div></div>
    <div class="so-row"><label>SNMP monitoring</label><div><input type="hidden" name="snmp_enabled" value="0"><label class="so-check"><input type="checkbox" name="snmp_enabled" value="1" <?php echo !empty($editing['snmp_enabled']) ? 'checked' : ''; ?>> Enable read-only SNMPv2c</label><div class="so-help">Reads system uptime and Ethernet interface counters. ONU provisioning and optical levels use Telnet.</div></div></div>
    <div class="so-row"><label>SNMP read-only community</label><input class="so-input" name="snmp_community" type="password" maxlength="255" autocomplete="new-password" placeholder="<?php echo !empty($editing['snmp_community_enc']) ? 'Leave blank to keep the saved community' : 'Community configured on the OLT'; ?>"></div>
    <div class="so-row"><label>SNMP UDP port</label><div><input class="so-input" name="snmp_port" type="number" min="1" max="65535" value="<?php echo (int)($editing['snmp_port'] ?? 161); ?>"><div class="so-help">For VPN connections, enter the OLT's LAN SNMP port and paste the updated MikroTik commands after saving. Direct connections need a reachable UDP port.</div></div></div>
    <div class="so-row"><label>Manufacturer</label><div>Huawei</div></div>
    <div class="so-row"><label>OLT hardware version</label><div><?php echo !empty($editing['model']) ? e($editing['model']) : 'Not read yet'; ?><div class="so-help">Detected from the OLT after a successful read.</div></div></div>
    <div class="so-row"><label></label><div><?php echo olt_picture(16); ?></div></div>
    <div class="so-row"><label>Supported PON types</label><div>GPON</div></div>
    <div class="so-row"><label></label><div class="so-inline" style="justify-content:space-between">
        <span><button class="so-btn so-btn-green"><i class="fa fa-floppy-o"></i> Save</button> <a href="olts.php" style="margin-left:10px">Cancel</a></span>
        <span class="so-muted" style="font-size:13px">Saving logs in to the OLT and reads it at once.</span></div></div>
    <div class="so-alert a-info" style="margin:6px 0 0">
        <b>Through the SmartOLT VPN:</b> after saving you get commands to paste into the MikroTik the OLT sits behind; it connects to SmartOLT by VPN and SmartOLT reaches the OLT through it. Nothing on the OLT is opened to the internet.
        <b>Directly:</b> the OLT must be reachable from the internet on its public IP, or through a port forward to its port 23. Use an OLT user that may configure ONUs; the password is stored encrypted.
    </div>
</form>
<script>
(function () {
    var sel = document.getElementById('connType');
    function show() {
        var vpn = sel.value === 'vpn';
        document.querySelectorAll('.conn-vpn').forEach(function (el) { el.classList.toggle('hidden', !vpn); el.querySelector('input').required = vpn && el.querySelector('input').name === 'lan_ip'; });
        document.querySelectorAll('.conn-direct').forEach(function (el) { el.classList.toggle('hidden', vpn); el.querySelector('input').required = !vpn; });
    }
    sel.addEventListener('change', show);
    show();
})();
</script>
<?php else: ?>
<?php echo page_title('OLTs', 'Add and manage the OLTs that SmartOLT connects to.',
    btn('Add OLT', 'green', 'olts.php?add=1', 'plus') . btn('Find config mismatches', 'grey', 'reports.php?r=mismatch', 'search') . btn('Export', 'grey', 'olts.php?export=1', 'upload')); ?>
<form method="GET" class="so-box" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
    <div><label class="so-label">Search</label><div class="so-search"><i class="fa fa-search"></i><input name="q" value="<?php echo e($fq); ?>" class="so-input" style="width:200px" placeholder="Name, IP, port, model"></div></div>
    <div><label class="so-label">Status</label><select name="status" class="so-select" style="width:150px" onchange="this.form.submit()"><option value="">Any</option><option value="online" <?php echo $fStatus === 'online' ? 'selected' : ''; ?>>Connected</option><option value="error" <?php echo $fStatus === 'error' ? 'selected' : ''; ?>>Not connected</option></select></div>
    <div><label class="so-label">VPN</label><select name="vpn" class="so-select" style="width:150px" onchange="this.form.submit()"><option value="">Any</option><option value="yes" <?php echo $fVpn === 'yes' ? 'selected' : ''; ?>>VPN</option><option value="no" <?php echo $fVpn === 'no' ? 'selected' : ''; ?>>Direct</option></select></div>
    <div><span class="so-label">Driver</span><span class="so-badge">Huawei GPON</span></div>
    <a href="olts.php" style="margin-left:auto;color:var(--text);font-weight:700;font-size:14px;padding-bottom:10px"><i class="fa fa-times"></i> Reset filters</a>
</form>
<?php if (!$list): ?><div class="so-empty">No OLTs found</div><?php else: ?>
<div class="so-table-wrap"><table class="so-table" style="font-size:14px">
    <thead><tr><th>View</th><th>ID</th><th><i class="fa fa-circle"></i></th><th>VPN</th><th>Name</th><th>OLT IP</th><th>TCP</th><th>OLT hardware version</th><th>OLT SW version</th><th>ONUs</th><th>Last read</th><th class="center">Action</th></tr></thead>
    <tbody>
    <?php foreach ($list as $o): $v = $o['conn_type'] === 'vpn' ? $mgr->vpnStatus($o) : null; ?>
        <tr>
            <td><?php echo btn('View', 'blue', 'olt.php?id=' . (int)$o['id']); ?></td>
            <td><?php echo (int)$o['id']; ?></td>
            <td><span class="so-dot<?php echo $o['status'] === 'online' ? '' : ' off'; ?>" title="<?php echo $o['status'] === 'online' ? 'Connected' : e($o['last_error'] ?: 'Not read yet'); ?>" <?php echo $o['status'] === 'error' ? 'style="background:var(--red)"' : ''; ?>></span></td>
            <td><?php echo $v === null ? '<span class="so-muted">-</span>' : '<a href="olt.php?id=' . (int)$o['id'] . '#vpn" title="' . ($v['connected'] ? 'Connected' : 'Router not connected') . '" style="color:' . ($v['connected'] ? 'var(--green)' : 'var(--red)') . '"><i class="fa fa-' . ($v['connected'] ? 'check' : 'times') . '-circle"></i><br><small>Tunnel</small></a>'; ?></td>
            <td><?php echo e($o['name']); ?></td>
            <td class="so-mono"><?php echo e($o['conn_type'] === 'vpn' ? $o['lan_ip'] : $o['host']); ?></td>
            <td><?php echo (int)($o['conn_type'] === 'vpn' ? $o['lan_port'] : $o['telnet_port']); ?></td>
            <td><?php echo $o['model'] !== '' ? 'Huawei-' . e($o['model']) : '<span class="so-muted">Not detected yet</span>'; ?></td>
            <td><?php echo $o['version'] !== '' ? e($o['version']) : '<span class="so-muted">Not detected yet</span>'; ?></td>
            <td><a href="onus.php?olt=<?php echo (int)$o['id']; ?>"><?php echo (int)$o['onu_online']; ?> / <?php echo (int)$o['onu_count']; ?></a></td>
            <td><?php echo $o['last_polled_at'] ? ago($o['last_polled_at']) : 'Never'; ?></td>
            <td class="center" style="white-space:nowrap">
                <?php echo post_btn('refresh', ['id' => $o['id']], '', 'grey', 'refresh', '', 'Reading the OLT. On a big OLT this takes up to a minute...'); ?>
                <?php echo btn('', 'grey', 'olts.php?edit=' . (int)$o['id'], 'pencil', 'title="Edit"'); ?>
                <?php echo post_btn('delete', ['id' => $o['id']], '', 'red', 'trash', 'Remove ' . $o['name'] . ' from SmartOLT? Nothing changes on the OLT itself and your customers stay connected.'); ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
