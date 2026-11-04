<?php
require_once __DIR__ . '/backend/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $return = 'onus.php' . (($_POST['return'] ?? '') !== '' ? '?' . preg_replace('/[^\w=&%.-]/', '', $_POST['return']) : '');
    try {
        if (($_POST['do'] ?? '') === 'reboot') {
            $mgr->reboot($id);
            back($return, 'success', 'Reboot sent. The ONU is back in about two minutes.');
        }
        if (($_POST['do'] ?? '') === 'delete') {
            $n = $mgr->deleteOnu($id);
            back($return, 'success', 'ONU deleted from the OLT' . ($n ? " with its {$n} service port" . ($n > 1 ? 's' : '') : '') . '.');
        }
    } catch (Throwable $e) {
        back($return, 'error', $e->getMessage());
    }
}

$olts = $mgr->olts();
$filter = [
    'olt_id' => (int)($_GET['olt'] ?? 0),
    'status' => in_array($_GET['status'] ?? '', ['online', 'offline', 'weak'], true) ? $_GET['status'] : '',
    'q'      => trim((string)($_GET['q'] ?? '')),
];
$onus = $mgr->listOnus($filter);
$returnQs = http_build_query(array_filter(['olt' => $filter['olt_id'] ?: null, 'status' => $filter['status'] ?: null, 'q' => $filter['q'] ?: null]));

$pageTitle = 'Configured ONUs';
include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
?>
<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <?php echo page_heading('Configured ONUs', 'Status and signal are read from the OLT every 5 minutes. Open an ONU to read it live.'); ?>
        <?php echo flash_messages(); ?>

        <?php echo panel_open(count($onus) . ' ONUs'); ?>
            <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
                <select name="olt" class="<?php echo UI_INPUT; ?> w-auto">
                    <option value="0">All OLTs</option>
                    <?php foreach ($olts as $o): ?><option value="<?php echo (int)$o['id']; ?>" <?php echo (int)$o['id'] === $filter['olt_id'] ? 'selected' : ''; ?>><?php echo e($o['name']); ?></option><?php endforeach; ?>
                </select>
                <select name="status" class="<?php echo UI_INPUT; ?> w-auto">
                    <option value="">Any status</option>
                    <option value="online" <?php echo $filter['status'] === 'online' ? 'selected' : ''; ?>>Online</option>
                    <option value="offline" <?php echo $filter['status'] === 'offline' ? 'selected' : ''; ?>>Offline</option>
                    <option value="weak" <?php echo $filter['status'] === 'weak' ? 'selected' : ''; ?>>Weak signal</option>
                </select>
                <input name="q" value="<?php echo e($filter['q']); ?>" placeholder="Serial, description or customer" class="<?php echo UI_INPUT; ?> w-64">
                <button class="<?php echo UI_BTN_PRIMARY; ?>">Filter</button>
                <?php if ($returnQs !== ''): ?><a href="onus.php" class="<?php echo UI_BTN_GHOST; ?>">Clear</a><?php endif; ?>
            </form>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="<?php echo UI_THEAD; ?>">
                        <tr>
                            <th class="<?php echo UI_TH; ?>">ONU</th><th class="<?php echo UI_TH; ?>">Serial Number</th><th class="<?php echo UI_TH; ?>">PON Port</th>
                            <th class="<?php echo UI_TH; ?>">Status</th><th class="<?php echo UI_TH; ?>">Signal</th><th class="<?php echo UI_TH; ?>">Customer</th>
                            <th class="<?php echo UI_TH; ?> text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <?php if (!$onus): ?>
                        <tr><td colspan="7" class="px-4 py-8 text-center text-slate-400 dark:text-slate-500"><?php echo $olts ? 'No ONUs match. If you just added the OLT, press Refresh on it under OLTs.' : 'Add your OLT first.'; ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($onus as $u): ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                            <td class="<?php echo UI_TD; ?> font-medium"><a class="text-brand-600 dark:text-brand-400" href="onu.php?id=<?php echo (int)$u['id']; ?>"><?php echo e($u['description']); ?></a></td>
                            <td class="<?php echo UI_TD; ?> font-mono text-xs text-slate-600 dark:text-slate-300"><?php echo e($u['sn']); ?></td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300 whitespace-nowrap"><?php echo e($u['olt_name']); ?> <span class="font-mono text-xs"><?php echo (int)$u['frame'] . '/' . (int)$u['slot'] . '/' . (int)$u['port'] . ' ID ' . (int)$u['ont_id']; ?></span></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo run_state_badge($u['run_state']); ?></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo signal_html($u['rx_power']); ?></td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300"><?php echo $u['customer_ref'] !== '' ? e($u['customer_ref']) : '<span class="text-slate-400">--</span>'; ?></td>
                            <td class="<?php echo UI_TD; ?> text-right whitespace-nowrap">
                                <a href="onu.php?id=<?php echo (int)$u['id']; ?>" class="<?php echo UI_BTN_PRIMARY; ?>">View</a>
                                <form method="POST" class="inline" data-busy="Sending the reboot..." onsubmit="return confirm('Reboot this ONU? The customer is offline for about two minutes.');">
                                    <?php echo csrf_field(); ?><input type="hidden" name="do" value="reboot"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><input type="hidden" name="return" value="<?php echo e($returnQs); ?>">
                                    <button class="<?php echo UI_BTN_AMBER; ?>">Reboot</button>
                                </form>
                                <form method="POST" class="inline" data-busy="Deleting the ONU..." onsubmit="return confirm('Delete this ONU from the OLT? The customer loses internet until it is authorized again.');">
                                    <?php echo csrf_field(); ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><input type="hidden" name="return" value="<?php echo e($returnQs); ?>">
                                    <button class="<?php echo UI_BTN_RED; ?>">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php echo panel_close(); ?>
    </main>
    <?php include __DIR__ . '/backend/templates/footer.php'; ?>
