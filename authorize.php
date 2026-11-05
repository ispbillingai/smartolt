<?php
require_once __DIR__ . '/backend/bootstrap.php';

$oltId = (int)($_GET['olt'] ?? $_POST['olt_id'] ?? 0);
$sn = (string)($_GET['sn'] ?? $_POST['sn'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $r = $mgr->authorize($_POST);
        back('onu.php?id=' . $r['id'] . '&live=0', 'success', "ONU authorized as ONU ID {$r['ont_id']}. It comes online within a minute.");
    } catch (Throwable $e) {
        back('authorize.php?olt=' . $oltId . '&sn=' . urlencode($sn), 'error', 'Not authorized: ' . $e->getMessage());
    }
}

$row = $mgr->olt($oltId);
if (!$row) back('unconfigured.php', 'error', 'OLT not found.');
$found = null;
foreach ($mgr->waiting($oltId)[$oltId]['onus'] ?? [] as $u) if ($u['sn'] === $sn) $found = $u;
if (!$found) back('unconfigured.php?olt=' . $oltId, 'error', 'That ONU is no longer waiting on this OLT. Press Refresh to scan again.');

$olt = OltManager::publicOlt($row);
$prof = $olt['profiles'];
[$defLine, $defSrv] = $mgr->defaultProfiles($row);
$vlans = array_values(array_filter($prof['vlans'], function ($v) { return $v['id'] > 1; }));
$vlanUse = array_count_values(array_filter(array_column($mgr->listOnus(['olt_id' => $oltId]), 'vlan')));
arsort($vlanUse);
$defVlan = $vlanUse ? (int)array_key_first($vlanUse) : (count($vlans) === 1 ? (int)$vlans[0]['id'] : '');
$types = $mgr->onuTypes();
$typeId = $mgr->typeForModel($found['equipment'] ?? '');
$typeMode = array_column($types, 'default_mode', 'id');
$mode = $typeId ? ($typeMode[$typeId] ?? 'bridge') : 'bridge';
$presets = $mgr->presets();
$pending = null;
foreach ($mgr->pendingOnus() as $p) if ($p['sn'] === $sn) $pending = $p;

$pageTitle = 'Authorize ONU';
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo page_title('Authorize ONU', 'The ONU gets its place on the OLT, a name and, with a VLAN, its internet service.'); ?>
<?php echo flash_messages(); ?>
<?php if (!$prof['line'] || !$prof['srv']) echo alert('The profiles of this OLT are not read yet. Open Settings, OLTs and press Refresh on it before you authorize.', 'warn'); ?>

<form method="POST" class="so-panel" data-busy="Authorizing the ONU on the OLT..." style="max-width:860px">
    <div class="so-panel-b">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="olt_id" value="<?php echo $oltId; ?>"><input type="hidden" name="sn" value="<?php echo e($sn); ?>">
        <input type="hidden" name="model" value="<?php echo e($found['equipment']); ?>">
        <input type="hidden" name="frame" value="<?php echo (int)$found['frame']; ?>"><input type="hidden" name="slot" value="<?php echo (int)$found['slot']; ?>"><input type="hidden" name="port" value="<?php echo (int)$found['port']; ?>">
        <?php if ($presets): ?>
        <div class="so-field"><label class="so-label">Preset</label>
            <select id="preset" class="so-select"><option value="">None, fill in below</option><?php foreach ($presets as $p): ?><option value="<?php echo (int)$p['id']; ?>"><?php echo e($p['name']); ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <dl class="so-dl" style="margin-bottom:16px">
            <dt>OLT</dt><dd><?php echo (int)$oltId . ' - ' . e($olt['name']); ?></dd>
            <dt>PON type</dt><dd>GPON</dd>
            <dt>Board / Port</dt><dd><?php echo (int)$found['slot'] . ' / ' . (int)$found['port']; ?></dd>
            <dt>SN</dt><dd class="so-mono"><?php echo e($sn); ?> <?php if (($found['sn_label'] ?? '') !== '') echo '<span class="so-muted">' . e($found['sn_label']) . '</span>'; ?></dd>
            <dt>Reported model</dt><dd><?php echo e($found['equipment'] ?: '-'); ?> <span class="so-muted"><?php echo e($found['vendor'] ?? ''); ?></span></dd>
        </dl>
        <div class="so-grid2">
            <div class="so-field"><label class="so-label">ONU type</label><select name="onu_type_id" data-k="onu_type_id" class="so-select"><?php echo options($types, $typeId ?? '', 'Not set'); ?></select></div>
            <div class="so-field"><label class="so-label">ONU mode</label>
                <div class="so-inline" style="height:40px">
                    <label class="so-check"><input type="radio" name="mode" value="router" <?php echo $mode === 'router' ? 'checked' : ''; ?>> Routing</label>
                    <label class="so-check"><input type="radio" name="mode" value="bridge" <?php echo $mode === 'bridge' ? 'checked' : ''; ?>> Bridging</label>
                </div></div>
            <div class="so-field"><label class="so-label">User VLAN-ID</label><input name="vlan" data-k="vlan" type="number" min="1" max="4094" list="vlans" class="so-input" value="<?php echo e($defVlan); ?>" placeholder="Empty authorizes without internet">
                <datalist id="vlans"><?php foreach ($vlans as $v): ?><option value="<?php echo (int)$v['id']; ?>"><?php endforeach; ?></datalist></div>
            <div class="so-field"><label class="so-label">Speed profile</label><select name="speed_profile_id" data-k="speed_profile_id" class="so-select"><?php echo options($mgr->speedProfiles(), '', 'No speed limit'); ?></select>
                <div class="so-help">Download and upload. Add profiles under Settings, Speed profiles.</div></div>
            <div class="so-field"><label class="so-label">Zone</label><select name="zone_id" data-k="zone_id" class="so-select"><?php echo options($mgr->zones(), $pending['zone_id'] ?? '', 'None'); ?></select></div>
            <div class="so-field"><label class="so-label">Sector</label><select name="sector_id" data-k="sector_id" class="so-select"><?php echo options($mgr->sectors(), $pending['sector_id'] ?? '', 'None'); ?></select></div>
            <div class="so-field"><label class="so-label">ODB (Splitter)</label><select name="odb_id" class="so-select"><?php echo options($mgr->odbs(), $pending['odb_id'] ?? '', 'None'); ?></select></div>
            <div class="so-field"><label class="so-label">ODB port</label><input name="odb_port" type="number" min="1" max="64" class="so-input" value="<?php echo e($pending['odb_port'] ?? ''); ?>"></div>
            <div class="so-field"><label class="so-label">Name</label><input name="description" maxlength="60" class="so-input" value="<?php echo e($pending['description'] ?? ''); ?>" placeholder="For example the customer name"></div>
            <div class="so-field"><label class="so-label">Address or comment</label><input name="address" maxlength="255" class="so-input" value="<?php echo e($pending['address'] ?? ''); ?>"></div>
            <div class="so-field"><label class="so-label">ONU external ID</label><input name="customer_ref" maxlength="100" class="so-input" value="<?php echo e($pending['customer_ref'] ?? ''); ?>" placeholder="Customer username in your billing"></div>
            <div class="so-field"><label class="so-label">GPS location</label>
                <div class="so-inline"><input name="lat" class="so-input" style="width:120px" placeholder="Latitude"><input name="lng" class="so-input" style="width:120px" placeholder="Longitude">
                    <button type="button" id="useGps" class="so-btn so-btn-grey so-btn-sm"><i class="fa fa-map-marker"></i> Use GPS</button></div></div>
        </div>
        <details style="margin-top:4px"><summary class="so-muted" style="cursor:pointer">OLT profiles</summary>
            <div class="so-grid3" style="margin-top:10px">
                <div class="so-field"><label class="so-label">Line profile</label><select name="line_profile" data-k="line_profile" class="so-select" required>
                    <?php foreach ($prof['line'] as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $p['id'] === $defLine ? 'selected' : ''; ?>><?php echo (int)$p['id'] . ': ' . e($p['name']); ?></option><?php endforeach; ?></select></div>
                <div class="so-field"><label class="so-label">Service profile</label><select name="srv_profile" data-k="srv_profile" class="so-select" required>
                    <?php foreach ($prof['srv'] as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $p['id'] === $defSrv ? 'selected' : ''; ?>><?php echo (int)$p['id'] . ': ' . e($p['name']); ?></option><?php endforeach; ?></select></div>
                <div class="so-field"><label class="so-label">GEM port</label><input name="gemport" data-k="gemport" type="number" min="0" max="1023" value="1" class="so-input"></div>
            </div>
        </details>
    </div>
    <div class="so-modal-f"><a href="unconfigured.php?olt=<?php echo $oltId; ?>" class="so-btn so-btn-dark">Cancel</a><button class="so-btn so-btn-blue">Save</button></div>
</form>
<script>
(function () {
    var presets = <?php echo json_encode(array_column($presets, null, 'id')); ?>;
    var sel = document.getElementById('preset');
    if (sel) sel.addEventListener('change', function () {
        var p = presets[sel.value];
        if (!p) return;
        document.querySelectorAll('[data-k]').forEach(function (el) {
            var v = p[el.getAttribute('data-k')];
            if (v !== null && v !== undefined) el.value = v;
        });
        document.querySelectorAll('input[name=mode]').forEach(function (r) { r.checked = r.value === p.mode; });
    });
    document.getElementById('useGps').addEventListener('click', function () {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(function (pos) {
            document.querySelector('input[name=lat]').value = pos.coords.latitude.toFixed(7);
            document.querySelector('input[name=lng]').value = pos.coords.longitude.toFixed(7);
        });
    });
})();
</script>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
