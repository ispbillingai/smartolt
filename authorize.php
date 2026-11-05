<?php
require_once __DIR__ . '/backend/bootstrap.php';

$oltId = (int)($_GET['olt'] ?? $_POST['olt_id'] ?? 0);
$sn = (string)($_GET['sn'] ?? $_POST['sn'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $r = $mgr->authorize($_POST);
        back('onu.php?id=' . $r['id'], 'success', "ONU authorized as ONU ID {$r['ont_id']}. It comes online within a minute.");
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
[$defLine, $defSrv] = $mgr->defaultProfiles($row);
// The VLAN most ONUs on this OLT already use.
$vlanUse = array_count_values(array_filter(array_column($mgr->listOnus(['olt_id' => $oltId]), 'vlan')));
arsort($vlanUse);
$vlans = array_values(array_filter($olt['profiles']['vlans'], function ($v) { return $v['id'] > 1; }));
$typeId = $mgr->typeForModel($found['equipment'] ?? '');
$typeMode = array_column($mgr->onuTypes(), 'default_mode', 'id');
$sel = ['line_profile' => $defLine, 'srv_profile' => $defSrv, 'onu_type_id' => $typeId ?? '',
    'vlan' => $vlanUse ? (int)array_key_first($vlanUse) : (count($vlans) === 1 ? (int)$vlans[0]['id'] : ''),
    'mode' => $typeId ? ($typeMode[$typeId] ?? 'bridge') : 'bridge'];
foreach ($mgr->pendingOnus() as $p) {
    if ($p['sn'] !== $sn) continue;
    foreach (['description', 'customer_ref', 'zone_id', 'sector_id', 'odb_id', 'odb_port', 'address', 'onu_type_id', 'vlan', 'speed_profile_id', 'lat', 'lng', 'preset_id'] as $k) {
        if ($p[$k] !== null && $p[$k] !== '') $sel[$k] = $p[$k];
    }
    if ($p['mode'] !== '') $sel['mode'] = $p['mode'];
}
$form = 'authorize';
$olts = $mgr->olts();

$pageTitle = 'Authorize ONU';
include __DIR__ . '/backend/templates/header.php';
?>
<div class="so-pagehead"><h1><i class="fa fa-magic"></i> Authorize ONU</h1><?php echo btn('Back', 'grey', 'unconfigured.php?olt=' . $oltId, 'list'); ?></div>
<?php echo flash_messages(); ?>
<?php if (!$olt['profiles']['line'] || !$olt['profiles']['srv']) echo alert('The profiles of this OLT are not read yet. Open Settings, OLTs and press Refresh on it before you authorize.', 'warn'); ?>
<form method="POST" class="so-section so-form" style="max-width:none" data-busy="Authorizing the ONU on the OLT...">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="olt_id" value="<?php echo $oltId; ?>"><input type="hidden" name="sn" value="<?php echo e($sn); ?>">
    <input type="hidden" name="model" value="<?php echo e($found['equipment']); ?>">
    <input type="hidden" name="frame" value="<?php echo (int)$found['frame']; ?>"><input type="hidden" name="slot" value="<?php echo (int)$found['slot']; ?>"><input type="hidden" name="port" value="<?php echo (int)$found['port']; ?>">
    <?php include __DIR__ . '/backend/templates/onu_form.php'; ?>
    <div class="so-row"><label></label><div><button class="so-btn so-btn-green"><i class="fa fa-floppy-o"></i> Save</button> <a href="unconfigured.php?olt=<?php echo $oltId; ?>" style="margin-left:10px">Cancel</a></div></div>
</form>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
