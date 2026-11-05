<?php
require_once __DIR__ . '/backend/bootstrap.php';

$olts = $mgr->olts();
$oltId = (int)($_POST['olt_id'] ?? $_GET['olt'] ?? ($olts[0]['id'] ?? 0));
$key = (string)($_POST['check'] ?? 'version');
$params = ['frame' => $_POST['frame'] ?? '0', 'slot' => $_POST['slot'] ?? '', 'port' => $_POST['port'] ?? '', 'ont' => $_POST['ont'] ?? ''];
$output = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $output = $mgr->diag($oltId, $key, $params);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$olt = $oltId ? $mgr->olt($oltId) : null;
$vpn = $olt && $olt['conn_type'] === 'vpn' ? $mgr->vpnStatus($olt) : null;

$pageTitle = 'Diag';
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo page_title('Diagnostics', 'Read information straight from the OLT. Only display commands are offered here, nothing on the OLT changes.'); ?>
<?php if (!$olts): ?><div class="so-empty">No OLT yet.</div><?php else: ?>
<div class="so-two">
    <div>
        <form method="POST" class="so-panel" data-busy="Asking the OLT...">
            <div class="so-panel-h"><span><i class="fa fa-terminal"></i> Run a check</span></div>
            <div class="so-panel-b">
                <?php echo csrf_field(); ?>
                <div class="so-grid2">
                    <div class="so-field"><label class="so-label">OLT</label><select name="olt_id" class="so-select"><?php echo olt_options($olts, $oltId); ?></select></div>
                    <div class="so-field"><label class="so-label">Check</label><select name="check" id="check" class="so-select">
                        <?php foreach (Huawei::DIAG as $k => $d): ?><option value="<?php echo $k; ?>" data-need="<?php echo e(implode(',', $d[2])); ?>" <?php echo $k === $key ? 'selected' : ''; ?>><?php echo e($d[0]); ?></option><?php endforeach; ?>
                    </select></div>
                </div>
                <div class="so-inline" id="params">
                    <?php foreach (['frame' => 'Frame', 'slot' => 'Board', 'port' => 'Port', 'ont' => 'ONU ID'] as $p => $l): ?>
                        <span class="so-inline p-<?php echo $p; ?>"><label class="so-label"><?php echo $l; ?></label><input name="<?php echo $p; ?>" type="number" min="0" max="255" value="<?php echo e($params[$p]); ?>" class="so-input" style="width:80px;min-width:0"></span>
                    <?php endforeach; ?>
                </div>
                <p class="so-muted so-mono" style="font-size:13px" id="cmd"></p>
                <button class="so-btn so-btn-green"><i class="fa fa-play"></i> Run</button>
            </div>
        </form>
        <?php if ($error !== '') echo alert($error, 'err'); ?>
        <?php if ($output !== null): ?>
            <?php echo panel_open(Huawei::DIAG[$key][0] . ' on ' . ($olt['name'] ?? ''), '<button type="button" data-copy="diagOut"><i class="fa fa-copy"></i> Copy</button>', 'file-text-o'); ?>
                <pre class="so-pre" id="diagOut"><?php echo e($output !== '' ? $output : 'The OLT returned nothing.'); ?></pre>
            <?php echo panel_close(); ?>
        <?php endif; ?>
    </div>
    <div>
        <?php echo panel_open('Connection', '', 'plug'); ?>
            <?php if ($olt): ?>
            <dl class="so-dl">
                <dt>OLT</dt><dd><?php echo (int)$olt['id'] . ' - ' . e($olt['name']); ?></dd>
                <dt>Reached at</dt><dd class="so-mono"><?php echo e(olt_address($olt)); ?></dd>
                <dt>Status</dt><dd><?php echo $olt['status'] === 'online' ? badge('Connected', 'green') : ($olt['status'] === 'error' ? badge('Cannot connect', 'red') : badge('Not read yet', 'grey')); ?></dd>
                <?php if ($vpn !== null): ?><dt>VPN</dt><dd><?php echo $vpn['connected'] ? badge('Connected', 'green') . ' ' . e($vpn['from']) : badge('Router not connected', 'orange'); ?></dd><?php endif; ?>
                <dt>Uptime</dt><dd><?php echo e($olt['uptime'] ?: '-'); ?><?php echo $olt['temperature'] !== '' ? ', ' . e($olt['temperature']) . '°C' : ''; ?></dd>
                <dt>Last read</dt><dd><?php echo ago($olt['last_polled_at']); ?></dd>
            </dl>
            <?php if ($olt['status'] === 'error') echo '<div class="so-alert a-err" style="margin:10px 0 0">' . e($olt['last_error']) . '</div>'; ?>
            <?php endif; ?>
        <?php echo panel_close(); ?>
    </div>
</div>
<script>
(function () {
    var sel = document.getElementById('check');
    var cmds = <?php echo json_encode(array_map(function ($d) { return $d[1]; }, Huawei::DIAG)); ?>;
    function show() {
        var need = (sel.options[sel.selectedIndex].getAttribute('data-need') || '').split(',');
        ['frame', 'slot', 'port', 'ont'].forEach(function (p) { document.querySelector('.p-' + p).classList.toggle('hidden', need.indexOf(p) < 0); });
        document.getElementById('cmd').textContent = cmds[sel.value];
    }
    sel.addEventListener('change', show);
    show();
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
