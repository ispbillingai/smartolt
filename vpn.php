<?php
require_once __DIR__ . '/backend/bootstrap.php';

$vpnOlts = array_values(array_filter($mgr->olts(), function ($o) { return $o['conn_type'] === 'vpn'; }));

$pageTitle = 'VPN & TR069';
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo page_title('VPN & TR069', 'OLTs without a public IP reach SmartOLT through a VPN from the MikroTik they sit behind. Choose "Through the SmartOLT VPN" when you add the OLT and paste the commands it gives you.',
    btn('Add OLT', 'green', 'olts.php?add=1', 'plus')); ?>
<div class="so-half">
    <div><?php echo panel_open('SmartOLT VPN server', '', 'lock'); ?>
        <dl class="so-dl">
            <dt>Server</dt><dd class="so-mono"><?php echo e($mgr->vpnServer()); ?></dd>
            <dt>Port</dt><dd>1194 TCP</dd>
            <dt>Type</dt><dd>OpenVPN client (MikroTik RouterOS 6 and 7)</dd>
            <dt>Cipher</dt><dd>AES 256 CBC, SHA1</dd>
            <dt>Tunnel network</dt><dd class="so-mono">17.0.0.0/16</dd>
            <dt>Login</dt><dd>One per OLT, made for you, with its own password</dd>
        </dl>
        <p class="so-muted" style="font-size:14px;margin-bottom:0">The router only forwards the OLT's Telnet port to SmartOLT. Nothing else on your network is reachable through the tunnel, and the tunnel cannot reach this server's other services.</p>
    <?php echo panel_close(); ?></div>
    <div><?php echo panel_open('TR069', '', 'cloud'); ?>
        <div class="so-empty">Not connected yet</div>
        <p class="so-muted" style="font-size:14px;margin:0">TR069 lets SmartOLT manage the ONU itself (WiFi name and password, WiFi signal, LAN ports). It is not connected to SmartOLT yet; the OLT side (authorize, VLAN, speed, status and signal) works without it.</p>
    <?php echo panel_close(); ?></div>
</div>
<?php echo panel_open('OLTs on the VPN', '', 'server'); ?>
    <?php if (!$vpnOlts): ?><p class="so-muted" style="margin:0">No OLT uses the VPN.</p><?php else: ?>
    <div class="so-table-wrap"><table class="so-table">
        <thead><tr><th>OLT</th><th>Login</th><th>Tunnel IP</th><th>OLT behind the router</th><th>Router</th><th></th></tr></thead>
        <tbody><?php foreach ($vpnOlts as $o): $s = $mgr->vpnStatus($o); ?>
            <tr><td><?php echo (int)$o['id'] . ' - ' . e($o['name']); ?></td><td class="so-mono"><?php echo e($o['vpn_user']); ?></td><td class="so-mono"><?php echo e($o['vpn_ip']); ?></td>
                <td class="so-mono"><?php echo e($o['lan_ip'] . ':' . $o['lan_port']); ?></td>
                <td><?php echo $s['connected'] ? badge('Connected', 'green') . ' <small>' . e($s['from']) . '</small>' : badge('Not connected', 'orange'); ?></td>
                <td class="num"><?php echo btn('Router commands', 'blue', 'olt.php?id=' . (int)$o['id']); ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
<?php echo panel_close(); ?>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
