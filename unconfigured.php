<?php
require_once __DIR__ . '/backend/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'authorize') {
    require_csrf();
    $oltId = (int)($_POST['olt_id'] ?? 0);
    try {
        $r = $mgr->authorize($_POST);
        back('onu.php?id=' . $r['id'], 'success', "ONU authorized as ONU ID {$r['ont_id']}. It comes online within a minute.");
    } catch (Throwable $e) {
        back("unconfigured.php?olt={$oltId}&find=1", 'error', 'Not authorized: ' . $e->getMessage());
    }
}

$olts = $mgr->olts();
$oltId = (int)($_GET['olt'] ?? ($olts[0]['id'] ?? 0));
$olt = $oltId ? $mgr->olt($oltId) : null;
$found = null;
$foundAt = null;
$findError = '';
if ($olt && isset($_GET['find'])) {
    try {
        $found = $mgr->autofind($oltId);
        $foundAt = OltManager::now();
    } catch (Throwable $e) {
        $findError = $e->getMessage();
    }
}
if ($olt && $found === null) {
    // What the last 5 minute read saw, so the page answers at once.
    $cache = $mgr->waiting($oltId)[$oltId] ?? null;
    if ($cache) {
        $found = $cache['onus'];
        $foundAt = $cache['at'];
    }
}
$prof = $olt ? OltManager::publicOlt($olt)['profiles'] : ['line' => [], 'srv' => [], 'vlans' => []];
// Default to the profiles other ONUs already use; profile 0 rarely carries internet.
$busiest = function (array $ps) {
    usort($ps, function ($a, $b) { return $b['bindings'] <=> $a['bindings']; });
    return $ps[0]['id'] ?? null;
};
$defLine = $busiest($prof['line']);
$defSrv = $busiest($prof['srv']);
$vlans = array_values(array_filter($prof['vlans'], function ($v) { return $v['id'] > 1; }));
// The VLAN most ONUs on this OLT already use.
$vlanUse = array_count_values(array_filter(array_column($mgr->listOnus(['olt_id' => $oltId]), 'vlan')));
arsort($vlanUse);
$defVlan = $vlanUse ? (int)array_key_first($vlanUse) : (count($vlans) === 1 ? (int)$vlans[0]['id'] : '');
$zones = $mgr->zones();
$odbs = $mgr->odbs();
$types = $mgr->onuTypes();
$speeds = $mgr->speedProfiles();
$typeMode = array_column($types, 'default_mode', 'id');

$pageTitle = 'Unconfigured ONUs';
include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
?>
<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <?php echo page_heading('Unconfigured ONUs', 'ONUs plugged into a PON port that the OLT can see but has not authorized yet.'); ?>
        <?php echo flash_messages(); ?>

        <?php if (!$olts): ?>
            <div class="p-5 rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/60 text-sm">Add your OLT first. <a class="text-brand-600 dark:text-brand-400 font-medium" href="olts.php?add=1">Add OLT</a></div>
        <?php else: ?>

        <form method="GET" class="mb-6 flex flex-wrap items-end gap-3" data-busy="Asking the OLT which ONUs are waiting...">
            <input type="hidden" name="find" value="1">
            <div>
                <label class="<?php echo UI_LABEL; ?>">OLT</label>
                <select name="olt" class="<?php echo UI_INPUT; ?>">
                    <?php foreach ($olts as $o): ?><option value="<?php echo (int)$o['id']; ?>" <?php echo (int)$o['id'] === $oltId ? 'selected' : ''; ?>><?php echo e($o['name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <button class="<?php echo UI_BTN_PRIMARY; ?>">Search Now</button>
            <?php if ($foundAt): ?><span class="text-xs text-slate-400 pb-2">List as of <?php echo ago($foundAt); ?></span><?php endif; ?>
        </form>

        <?php if ($findError !== ''): ?>
            <div class="mb-4 p-3 border rounded-lg text-sm bg-rose-50 dark:bg-rose-900/30 border-rose-200 dark:border-rose-800/50 text-rose-700 dark:text-rose-300"><?php echo e($findError); ?></div>
        <?php endif; ?>

        <?php if ($olt && (!$prof['line'] || !$prof['srv'])): ?>
            <div class="mb-4 p-3 border rounded-lg text-sm bg-amber-50 dark:bg-amber-900/30 border-amber-200 dark:border-amber-800/50 text-amber-800 dark:text-amber-300">The profiles of this OLT are not loaded yet. Press Refresh on it under OLTs before you authorize.</div>
        <?php endif; ?>

        <?php echo panel_open($found === null ? 'Waiting ONUs' : count($found) . ' waiting on ' . ($olt['name'] ?? '')); ?>
            <?php if ($found === null): ?>
                <p class="py-8 text-center text-sm text-slate-400">Press Search Now to ask the OLT which ONUs are waiting.</p>
            <?php elseif (!$found): ?>
                <p class="py-8 text-center text-sm text-slate-400">No unconfigured ONUs on this OLT right now. Plug the ONU in, wait a minute and search again.</p>
            <?php else: ?>
            <div class="space-y-3">
            <?php foreach ($found as $i => $u): $typeId = $mgr->typeForModel($u['equipment'] ?? ''); $mode = $typeId ? ($typeMode[$typeId] ?? 'bridge') : 'bridge'; ?>
                <details class="group rounded-xl border border-slate-200 dark:border-slate-700">
                    <summary class="cursor-pointer list-none p-4 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                        <span class="font-mono font-semibold text-slate-900 dark:text-slate-100"><?php echo e($u['sn']); ?></span>
                        <?php if (($u['sn_label'] ?? '') !== ''): ?><span class="text-xs text-slate-400 font-mono"><?php echo e($u['sn_label']); ?></span><?php endif; ?>
                        <span class="text-slate-600 dark:text-slate-300">Port <b><?php echo (int)$u['frame'] . '/' . (int)$u['slot'] . '/' . (int)$u['port']; ?></b></span>
                        <span class="text-slate-600 dark:text-slate-300"><?php echo e($u['equipment']); ?> <span class="text-slate-400"><?php echo e($u['vendor']); ?></span></span>
                        <span class="text-xs text-slate-400"><?php echo e($u['found_at']); ?></span>
                        <span class="ml-auto <?php echo UI_BTN_GREEN; ?> group-open:hidden">Authorize</span>
                    </summary>
                    <form method="POST" class="p-4 pt-0 grid grid-cols-1 md:grid-cols-3 gap-4" data-busy="Authorizing the ONU on the OLT...">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="do" value="authorize">
                        <input type="hidden" name="olt_id" value="<?php echo $oltId; ?>">
                        <input type="hidden" name="sn" value="<?php echo e($u['sn']); ?>">
                        <input type="hidden" name="model" value="<?php echo e($u['equipment']); ?>">
                        <input type="hidden" name="frame" value="<?php echo (int)$u['frame']; ?>">
                        <input type="hidden" name="slot" value="<?php echo (int)$u['slot']; ?>">
                        <input type="hidden" name="port" value="<?php echo (int)$u['port']; ?>">
                        <div>
                            <label class="<?php echo UI_LABEL; ?>">Name</label>
                            <input name="description" maxlength="60" class="<?php echo UI_INPUT; ?>" placeholder="For example the customer name">
                        </div>
                        <div>
                            <label class="<?php echo UI_LABEL; ?>">ONU Type</label>
                            <select name="onu_type_id" class="<?php echo UI_INPUT; ?>"><?php echo options($types, $typeId ?? '', 'Not set'); ?></select>
                        </div>
                        <div>
                            <label class="<?php echo UI_LABEL; ?>">ONU Mode</label>
                            <select name="mode" class="<?php echo UI_INPUT; ?>">
                                <option value="bridge">Bridge (your router behind it gets the internet)</option>
                                <option value="router" <?php echo $mode === 'router' ? 'selected' : ''; ?>>Router (the ONU itself dials the internet)</option>
                            </select>
                        </div>
                        <div>
                            <label class="<?php echo UI_LABEL; ?>">Internet VLAN <span class="font-normal text-slate-400">(empty = authorize only)</span></label>
                            <input name="vlan" type="number" min="1" max="4094" list="vlans" class="<?php echo UI_INPUT; ?>" value="<?php echo e($defVlan); ?>" placeholder="For example 100">
                        </div>
                        <div>
                            <label class="<?php echo UI_LABEL; ?>">Speed</label>
                            <select name="speed_profile_id" class="<?php echo UI_INPUT; ?>"><?php echo options($speeds, '', 'No speed limit'); ?></select>
                        </div>
                        <div>
                            <label class="<?php echo UI_LABEL; ?>">Customer <span class="font-normal text-slate-400">(optional)</span></label>
                            <input name="customer_ref" maxlength="100" class="<?php echo UI_INPUT; ?>" placeholder="Customer username">
                        </div>
                        <div>
                            <label class="<?php echo UI_LABEL; ?>">Zone</label>
                            <select name="zone_id" class="<?php echo UI_INPUT; ?>"><?php echo options($zones, '', 'No zone'); ?></select>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div class="col-span-2">
                                <label class="<?php echo UI_LABEL; ?>">ODB</label>
                                <select name="odb_id" class="<?php echo UI_INPUT; ?>"><?php echo options($odbs, '', 'No ODB'); ?></select>
                            </div>
                            <div>
                                <label class="<?php echo UI_LABEL; ?>">Port</label>
                                <input name="odb_port" type="number" min="1" max="64" class="<?php echo UI_INPUT; ?>">
                            </div>
                        </div>
                        <div>
                            <label class="<?php echo UI_LABEL; ?>">Address</label>
                            <input name="address" maxlength="255" class="<?php echo UI_INPUT; ?>" placeholder="House or building">
                        </div>
                        <details class="md:col-span-3 text-sm">
                            <summary class="cursor-pointer text-slate-500 dark:text-slate-400">OLT profiles</summary>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
                                <div>
                                    <label class="<?php echo UI_LABEL; ?>">Line Profile</label>
                                    <select name="line_profile" class="<?php echo UI_INPUT; ?>" required>
                                        <?php foreach ($prof['line'] as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $p['id'] === $defLine ? 'selected' : ''; ?>><?php echo (int)$p['id'] . ': ' . e($p['name']); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="<?php echo UI_LABEL; ?>">Service Profile</label>
                                    <select name="srv_profile" class="<?php echo UI_INPUT; ?>" required>
                                        <?php foreach ($prof['srv'] as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $p['id'] === $defSrv ? 'selected' : ''; ?>><?php echo (int)$p['id'] . ': ' . e($p['name']); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="<?php echo UI_LABEL; ?>">GEM Port</label>
                                    <input name="gemport" type="number" min="0" max="1023" value="1" class="<?php echo UI_INPUT; ?>">
                                </div>
                            </div>
                        </details>
                        <div class="md:col-span-3 flex items-end">
                            <button class="<?php echo UI_BTN_GREEN; ?>">Authorize ONU</button>
                        </div>
                    </form>
                </details>
            <?php endforeach; ?>
            </div>
            <datalist id="vlans"><?php foreach ($vlans as $v): ?><option value="<?php echo (int)$v['id']; ?>"><?php endforeach; ?></datalist>
            <?php endif; ?>
        <?php echo panel_close(); ?>
        <?php endif; ?>
    </main>
    <?php include __DIR__ . '/backend/templates/footer.php'; ?>
