<?php
require_once __DIR__ . '/backend/bootstrap.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        switch ($_POST['do'] ?? '') {
            case 'rename':
                $desc = $mgr->describe($id, (string)($_POST['description'] ?? ''), (string)($_POST['customer_ref'] ?? ''));
                back("onu.php?id={$id}", 'success', "Saved as {$desc}.");
            case 'reboot':
                $mgr->reboot($id);
                back("onu.php?id={$id}", 'success', 'Reboot sent. The ONU is back in about two minutes.');
            case 'delete':
                $mgr->deleteOnu($id);
                back('onus.php', 'success', 'ONU deleted from the OLT.');
        }
    } catch (Throwable $e) {
        back("onu.php?id={$id}", 'error', $e->getMessage());
    }
}

if (!$mgr->onu($id)) {
    back('onus.php', 'error', 'ONU not found.');
}
$liveError = '';
try {
    [$onu, $detail] = $mgr->liveOnu($id);
} catch (Throwable $e) {
    // The OLT could not be reached: show what we last stored and say why.
    $onu = $mgr->onu($id);
    $detail = [];
    $liveError = $e->getMessage();
}

$pageTitle = 'ONU ' . $onu['sn'];
include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
$num = function ($v, $unit) { return $v === null ? '--' : number_format((float)$v, 2) . ' ' . $unit; };
?>
<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <?php echo page_heading($onu['description'], $onu['olt_name'] . ' · PON port ' . $onu['frame'] . '/' . $onu['slot'] . '/' . $onu['port'] . ' · ONU ID ' . $onu['ont_id'],
            '<a href="onus.php" class="' . UI_BTN_GHOST . '">Back to ONUs</a>'); ?>
        <?php echo flash_messages(); ?>
        <?php if ($liveError !== ''): ?>
            <div class="mb-4 p-3 border rounded-lg text-sm bg-amber-50 dark:bg-amber-900/30 border-amber-200 dark:border-amber-800/50 text-amber-800 dark:text-amber-300">Could not read the ONU live, showing the last saved values. <?php echo e($liveError); ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <?php
            echo stat_card('Status now', $onu['run_state'] === 'online' ? 'Online' : ucfirst($onu['run_state'] ?: 'Unknown'), $onu['run_state'] === 'online' ? 'emerald' : 'rose', 'M13 10V3L4 14h7v7l9-11h-7z');
            echo stat_card('ONU receives', $num($onu['rx_power'], 'dBm'), 'brand', 'M19 14l-7 7m0 0l-7-7m7 7V3');
            echo stat_card('OLT receives', $num($onu['olt_rx_power'], 'dBm'), 'violet', 'M5 10l7-7m0 0l7 7m-7-7v18');
            echo stat_card('Temperature', $onu['temperature'] === null ? '--' : (int)$onu['temperature'] . ' °C', 'amber', 'M12 3v10m0 0a4 4 0 100 8 4 4 0 000-8z');
            ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            <div class="lg:col-span-2">
                <?php echo panel_open('ONU'); ?>
                    <dl class="text-sm divide-y divide-slate-200 dark:divide-slate-700 mb-5">
                        <?php foreach ([
                            'Serial Number' => '<span class="font-mono">' . e($onu['sn']) . '</span>',
                            'Signal'        => signal_html($onu['rx_power']),
                            'VLAN'          => $onu['vlan'] ? (int)$onu['vlan'] : '--',
                            'Customer'      => $onu['customer_ref'] !== '' ? e($onu['customer_ref']) : '--',
                            'Authorized'    => $onu['authorized_at'] ? e(date('j M Y H:i', strtotime($onu['authorized_at']))) : '--',
                        ] as $k => $v): ?>
                            <div class="py-2 flex justify-between gap-4"><dt class="text-slate-500 dark:text-slate-400"><?php echo $k; ?></dt><dd class="text-slate-900 dark:text-slate-100 text-right"><?php echo $v; ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                    <form method="POST" class="space-y-3 mb-4" data-busy="Saving on the OLT...">
                        <?php echo csrf_field(); ?><input type="hidden" name="do" value="rename"><input type="hidden" name="id" value="<?php echo $id; ?>">
                        <div><label class="<?php echo UI_LABEL; ?>">Description</label><input name="description" maxlength="60" class="<?php echo UI_INPUT; ?>" value="<?php echo e($onu['description']); ?>" required></div>
                        <div><label class="<?php echo UI_LABEL; ?>">Customer</label><input name="customer_ref" maxlength="100" class="<?php echo UI_INPUT; ?>" value="<?php echo e($onu['customer_ref']); ?>" placeholder="Customer username"></div>
                        <button class="<?php echo UI_BTN_PRIMARY; ?>">Save</button>
                    </form>
                    <div class="flex flex-wrap gap-2 pt-4 border-t border-slate-200 dark:border-slate-700">
                        <a href="onu.php?id=<?php echo $id; ?>" class="<?php echo UI_BTN_GHOST; ?>">Read Again</a>
                        <form method="POST" class="inline" data-busy="Sending the reboot..." onsubmit="return confirm('Reboot this ONU? The customer is offline for about two minutes.');">
                            <?php echo csrf_field(); ?><input type="hidden" name="do" value="reboot"><input type="hidden" name="id" value="<?php echo $id; ?>">
                            <button class="<?php echo UI_BTN_AMBER; ?>">Reboot</button>
                        </form>
                        <form method="POST" class="inline" data-busy="Deleting the ONU..." onsubmit="return confirm('Delete this ONU from the OLT with its service ports? The customer loses internet until it is authorized again.');">
                            <?php echo csrf_field(); ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?php echo $id; ?>">
                            <button class="<?php echo UI_BTN_RED; ?>">Delete</button>
                        </form>
                    </div>
                <?php echo panel_close(); ?>
            </div>
            <div class="lg:col-span-3">
                <?php echo panel_open('What the OLT reports'); ?>
                    <?php if (!$detail): ?>
                        <p class="py-6 text-center text-sm text-slate-400">No live details.</p>
                    <?php else: ?>
                    <dl class="text-sm divide-y divide-slate-200 dark:divide-slate-700">
                        <?php foreach ($detail as $k => $v): if ($v === '') continue; ?>
                            <div class="py-2 grid grid-cols-5 gap-4"><dt class="col-span-2 text-slate-500 dark:text-slate-400 capitalize"><?php echo e($k); ?></dt><dd class="col-span-3 text-slate-900 dark:text-slate-100"><?php echo e($v); ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                    <?php endif; ?>
                <?php echo panel_close(); ?>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/backend/templates/footer.php'; ?>
