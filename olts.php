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
$openForm = $editing || isset($_GET['add']) || !$olts;
$isVpn = ($editing['conn_type'] ?? 'vpn') === 'vpn';

$pageTitle = 'OLTs';
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo page_title('OLTs', 'The OLTs SmartOLT logs in to by Telnet. Each one is read every 5 minutes. Supported now: Huawei MA5683T, MA5608T and MA5680T.',
    '<a href="olts.php?add=1" class="so-btn so-btn-green"><i class="fa fa-plus"></i> Add OLT</a>'); ?>
<?php echo flash_messages(); ?>

<?php if (!$olts): ?><div class="so-empty">No OLTs found</div><?php else: ?>
<div class="so-table-wrap"><table class="so-table">
    <thead><tr><th>ID</th><th>Name</th><th>OLT IP</th><th>Hardware</th><th>Software</th><th>Status</th><th>ONUs</th><th>Last read</th><th class="center">Action</th></tr></thead>
    <tbody>
    <?php foreach ($olts as $o): ?>
        <tr>
            <td><?php echo (int)$o['id']; ?></td>
            <td><a href="olt.php?id=<?php echo (int)$o['id']; ?>"><?php echo e($o['name']); ?></a></td>
            <td class="so-mono" style="font-size:14px"><?php echo e(olt_address($o)); ?></td>
            <td><?php echo $o['model'] !== '' ? 'Huawei ' . e($o['model']) : '<span class="so-muted">Not read yet</span>'; ?></td>
            <td style="font-size:14px"><?php echo e($o['version']); ?></td>
            <td><?php echo $o['status'] === 'online' ? badge('Connected', 'green') : ($o['status'] === 'error' ? badge('Cannot connect', 'red') . '<div class="so-help" style="color:var(--red);max-width:260px">' . e($o['last_error']) . '</div>' : badge('Not read yet', 'grey')); ?></td>
            <td><a href="onus.php?olt=<?php echo (int)$o['id']; ?>"><?php echo (int)$o['onu_online']; ?> / <?php echo (int)$o['onu_count']; ?></a></td>
            <td style="font-size:14px"><?php echo $o['last_polled_at'] ? ago($o['last_polled_at']) : 'Never'; ?></td>
            <td class="center" style="white-space:nowrap">
                <?php echo btn('View', 'blue', 'olt.php?id=' . (int)$o['id']); ?>
                <?php echo post_btn('refresh', ['id' => $o['id']], '', 'green', 'refresh', '', 'Reading the OLT. On a big OLT this takes up to a minute...'); ?>
                <?php echo btn('', 'grey', 'olts.php?edit=' . (int)$o['id'], 'pencil', 'title="Edit"'); ?>
                <?php echo post_btn('delete', ['id' => $o['id']], '', 'red', 'trash', 'Remove ' . $o['name'] . ' from SmartOLT? Nothing changes on the OLT itself and your customers stay connected.'); ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<div class="so-modal <?php echo $openForm ? 'show' : ''; ?>" id="oltModal">
    <form method="POST" class="so-modal-box wide" autocomplete="off" data-busy="Saving and logging in to the OLT...">
        <div class="so-modal-h"><?php echo $editing ? 'Edit ' . e($editing['name']) : 'Add OLT'; ?> <a href="olts.php" style="color:var(--muted);font-size:22px">&times;</a></div>
        <div class="so-modal-b">
            <?php echo csrf_field(); ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?php echo (int)($editing['id'] ?? 0); ?>">
            <div class="so-grid2">
                <div class="so-field"><label class="so-label">Name</label><input class="so-input" name="name" required maxlength="100" placeholder="For example Main OLT" value="<?php echo e($editing['name'] ?? ''); ?>"></div>
                <div class="so-field"><label class="so-label">Connection</label>
                    <select name="conn_type" id="connType" class="so-select">
                        <option value="vpn" <?php echo $isVpn ? 'selected' : ''; ?>>Through the SmartOLT VPN (no public IP needed)</option>
                        <option value="direct" <?php echo $isVpn ? '' : 'selected'; ?>>Directly, on a public IP or port forward</option>
                    </select></div>
                <div class="so-field conn-vpn"><label class="so-label">OLT IP in your network</label><input class="so-input" name="lan_ip" maxlength="45" placeholder="For example 192.168.100.1" value="<?php echo e($editing['lan_ip'] ?? ''); ?>"></div>
                <div class="so-field conn-vpn"><label class="so-label">OLT Telnet port</label><input class="so-input" name="lan_port" type="number" min="1" max="65535" value="<?php echo (int)($editing['lan_port'] ?? 23); ?>"></div>
                <div class="so-field conn-direct"><label class="so-label">OLT IP or host name</label><input class="so-input" name="host" maxlength="190" placeholder="Public IP of the OLT or of your router" value="<?php echo e(($editing['conn_type'] ?? '') === 'direct' ? $editing['host'] : ''); ?>"></div>
                <div class="so-field conn-direct"><label class="so-label">Telnet port</label><input class="so-input" name="telnet_port" type="number" min="1" max="65535" value="<?php echo (int)(($editing['conn_type'] ?? '') === 'direct' ? $editing['telnet_port'] : 23); ?>"></div>
                <div class="so-field"><label class="so-label">OLT username</label><input class="so-input" name="username" required maxlength="100" value="<?php echo e($editing['username'] ?? ''); ?>"></div>
                <div class="so-field"><label class="so-label">OLT password</label><input class="so-input" name="password" type="password" maxlength="100" autocomplete="new-password" <?php echo $editing ? 'placeholder="Leave blank to keep the saved one"' : 'required'; ?>></div>
            </div>
            <div class="so-alert a-info" style="margin:0">
                <b>Through the SmartOLT VPN:</b> enter the OLT's own IP as your MikroTik sees it. After saving you get commands to paste into that MikroTik; it connects to SmartOLT by VPN and SmartOLT reaches the OLT through it. Nothing on the OLT is opened to the internet.<br>
                <b>Directly:</b> the OLT must be reachable from the internet on its public IP, or through a port forward on your router to the OLT's port 23.<br>
                Use an OLT user that may configure ONUs. The password is stored encrypted.
            </div>
        </div>
        <div class="so-modal-f"><a href="olts.php" class="so-btn so-btn-dark">Cancel</a><button class="so-btn so-btn-green">Save and connect</button></div>
    </form>
</div>
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
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
