<?php
require_once __DIR__ . '/backend/bootstrap.php';

$labels = [
    'onu.authorize' => 'Authorize ONU', 'onu.reboot' => 'Reboot ONU', 'onu.delete' => 'Delete ONU',
    'onu.describe' => 'Rename ONU', 'onu.status' => 'Read ONU', 'onu.autofind' => 'Search for ONUs',
    'olt.refresh' => 'Read OLT', 'olt.delete' => 'Remove OLT',
    'onu.service' => 'Change VLAN or speed', 'onu.enable' => 'Enable ONU', 'onu.disable' => 'Disable ONU', 'onu.replace_sn' => 'Replace ONU',
    'onu.bulk_reboot' => 'Reboot many ONUs', 'onu.bulk_disable' => 'Disable many ONUs', 'onu.bulk_enable' => 'Enable many ONUs', 'onu.bulk_speed' => 'Set speed on many ONUs',
];
$actions = $mgr->actions(300);

$pageTitle = 'Activity Log';
include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
?>
<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <?php echo page_heading('Activity Log', 'Everything done on your OLTs, from this panel and from your billing dashboard.'); ?>
        <?php echo panel_open('Latest ' . count($actions)); ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="<?php echo UI_THEAD; ?>">
                        <tr><th class="<?php echo UI_TH; ?>">When</th><th class="<?php echo UI_TH; ?>">OLT</th><th class="<?php echo UI_TH; ?>">What</th><th class="<?php echo UI_TH; ?>">On</th><th class="<?php echo UI_TH; ?>">From</th><th class="<?php echo UI_TH; ?>">Result</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <?php if (!$actions): ?>
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400 dark:text-slate-500">Nothing done on your OLTs yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($actions as $a): ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                            <td class="<?php echo UI_TD; ?> whitespace-nowrap text-slate-600 dark:text-slate-300"><?php echo e(date('j M H:i', strtotime($a['created_at']))); ?></td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300"><?php echo e($a['olt_name'] ?? ''); ?></td>
                            <td class="<?php echo UI_TD; ?> font-medium"><?php echo e($labels[$a['action']] ?? $a['action']); ?></td>
                            <td class="<?php echo UI_TD; ?> font-mono text-xs text-slate-600 dark:text-slate-300"><?php echo e($a['target']); ?></td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300"><?php echo $a['source'] === 'api' ? 'Billing dashboard' : ($a['source'] === 'cron' ? 'Automatic' : 'This panel'); ?></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo $a['ok'] ? badge('Done', 'emerald') : badge('Failed', 'rose') . '<div class="text-xs text-rose-600 dark:text-rose-400 mt-1">' . e($a['message']) . '</div>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php echo panel_close(); ?>
    </main>
    <?php include __DIR__ . '/backend/templates/footer.php'; ?>
