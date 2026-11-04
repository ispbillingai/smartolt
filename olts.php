<?php
require_once __DIR__ . '/backend/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = $_POST['do'] ?? '';
    try {
        if ($do === 'save') {
            $id = $mgr->saveOlt($_POST);
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
            back('olts.php', 'success', "{$olt['name']} read: {$s['onus']} ONUs, {$s['online']} online" . ($s['removed'] ? ", {$s['removed']} no longer on the OLT" : '') . '.');
        }
        if ($do === 'delete') {
            $mgr->deleteOlt((int)$_POST['id']);
            back('olts.php', 'success', 'OLT removed. Nothing was changed on the OLT itself.');
        }
    } catch (InvalidArgumentException $e) {
        back('olts.php' . ($do === 'save' ? '?add=1' : ''), 'error', $e->getMessage());
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

$pageTitle = 'OLTs';
include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
?>
<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <?php echo page_heading('OLTs', 'The OLTs SmartOLT logs in to. Each one is read every 5 minutes.',
            $showForm ? '' : '<a href="olts.php?add=1" class="' . UI_BTN_PRIMARY . '">+ Add OLT</a>'); ?>
        <?php echo flash_messages(); ?>

        <?php if ($showForm): ?>
        <?php echo panel_open($editing ? 'Edit ' . $editing['name'] : 'Add OLT'); ?>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4" autocomplete="off" data-busy="Saving and logging in to the OLT...">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="do" value="save">
                <input type="hidden" name="id" value="<?php echo (int)($editing['id'] ?? 0); ?>">
                <div>
                    <label class="<?php echo UI_LABEL; ?>">Name</label>
                    <input class="<?php echo UI_INPUT; ?>" name="name" required maxlength="100" placeholder="For example Main OLT" value="<?php echo e($editing['name'] ?? ''); ?>">
                </div>
                <div>
                    <label class="<?php echo UI_LABEL; ?>">IP or Host Name</label>
                    <input class="<?php echo UI_INPUT; ?>" name="host" required maxlength="190" placeholder="Public IP of the OLT or of your router" value="<?php echo e($editing['host'] ?? ''); ?>">
                </div>
                <div>
                    <label class="<?php echo UI_LABEL; ?>">Telnet Port</label>
                    <input class="<?php echo UI_INPUT; ?>" name="telnet_port" type="number" min="1" max="65535" required value="<?php echo (int)($editing['telnet_port'] ?? 23); ?>">
                </div>
                <div>
                    <label class="<?php echo UI_LABEL; ?>">Username</label>
                    <input class="<?php echo UI_INPUT; ?>" name="username" required maxlength="100" value="<?php echo e($editing['username'] ?? ''); ?>">
                </div>
                <div>
                    <label class="<?php echo UI_LABEL; ?>">Password <?php if ($editing): ?><span class="font-normal text-slate-400">(leave blank to keep the saved one)</span><?php endif; ?></label>
                    <input class="<?php echo UI_INPUT; ?>" name="password" type="password" maxlength="100" autocomplete="new-password" <?php echo $editing ? '' : 'required'; ?>>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="<?php echo UI_BTN_PRIMARY; ?>">Save and Connect</button>
                    <?php if ($olts): ?><a href="olts.php" class="<?php echo UI_BTN_GHOST; ?>">Cancel</a><?php endif; ?>
                </div>
            </form>
            <div class="mt-5 p-4 rounded-xl bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-700 text-sm text-slate-600 dark:text-slate-300 space-y-1.5">
                <p>SmartOLT logs in to your OLT over Telnet, so the OLT must be reachable from the internet. Use its public IP, or forward a port on your MikroTik to the OLT management IP and port 23, then enter your public IP and that port here.</p>
                <p>Supported now: Huawei MA5683T, MA5608T and MA5680T. Use an OLT user that is allowed to configure ONUs. The password is stored encrypted.</p>
            </div>
        <?php echo panel_close(); ?>
        <?php endif; ?>

        <?php if ($olts): ?>
        <?php echo panel_open('Your OLTs'); ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="<?php echo UI_THEAD; ?>">
                        <tr>
                            <th class="<?php echo UI_TH; ?>">OLT</th><th class="<?php echo UI_TH; ?>">Model</th><th class="<?php echo UI_TH; ?>">Boards</th>
                            <th class="<?php echo UI_TH; ?>">ONUs</th><th class="<?php echo UI_TH; ?>">Status</th><th class="<?php echo UI_TH; ?>">Last Read</th>
                            <th class="<?php echo UI_TH; ?> text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <?php foreach ($olts as $o): $p = OltManager::publicOlt($o); ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition align-top">
                            <td class="<?php echo UI_TD; ?> font-medium"><a class="text-brand-600 dark:text-brand-400" href="olt.php?id=<?php echo (int)$o['id']; ?>"><?php echo e($o['name']); ?></a>
                                <div class="text-xs text-slate-400 font-mono"><?php echo e($o['host'] . ':' . $o['telnet_port']); ?></div></td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300"><?php echo $o['model'] !== '' ? e($o['model']) . '<div class="text-xs text-slate-400">' . e($o['version']) . '</div>' : '<span class="text-slate-400">Not read yet</span>'; ?></td>
                            <td class="<?php echo UI_TD; ?> text-xs text-slate-600 dark:text-slate-300">
                                <?php foreach ($p['boards'] as $b): ?>
                                    <div><span class="font-mono">0/<?php echo (int)$b['slot']; ?></span> <?php echo e($b['name']); ?> <?php echo $b['gpon'] ? badge('PON', 'brand') : ''; ?></div>
                                <?php endforeach; ?>
                            </td>
                            <td class="<?php echo UI_TD; ?>"><a class="text-brand-600 dark:text-brand-400" href="onus.php?olt=<?php echo (int)$o['id']; ?>"><b><?php echo (int)$o['onu_online']; ?></b> online of <?php echo (int)$o['onu_count']; ?></a></td>
                            <td class="<?php echo UI_TD; ?>">
                                <?php echo $o['status'] === 'online' ? badge('Connected', 'emerald') : ($o['status'] === 'error' ? badge('Cannot connect', 'rose') . '<div class="text-xs text-rose-600 dark:text-rose-400 mt-1 max-w-xs">' . e($o['last_error']) . '</div>' : badge('Not read yet', 'slate')); ?>
                            </td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300 whitespace-nowrap"><?php echo $o['last_polled_at'] ? e(date('j M H:i', strtotime($o['last_polled_at']))) : 'Never'; ?></td>
                            <td class="<?php echo UI_TD; ?> text-right whitespace-nowrap">
                                <form method="POST" class="inline" data-busy="Reading the OLT. On a big OLT this takes up to a minute...">
                                    <?php echo csrf_field(); ?><input type="hidden" name="do" value="refresh"><input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                                    <button class="<?php echo UI_BTN_PRIMARY; ?>">Refresh</button>
                                </form>
                                <a href="unconfigured.php?olt=<?php echo (int)$o['id']; ?>" class="<?php echo UI_BTN_GREEN; ?>">Unconfigured</a>
                                <a href="olts.php?edit=<?php echo (int)$o['id']; ?>" class="<?php echo UI_BTN_GHOST; ?>">Edit</a>
                                <form method="POST" class="inline" onsubmit="return confirm('Remove <?php echo e(addslashes($o['name'])); ?> from SmartOLT? Nothing changes on the OLT itself and your customers stay connected.');">
                                    <?php echo csrf_field(); ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                                    <button class="<?php echo UI_BTN_RED; ?>">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php echo panel_close(); ?>
        <?php endif; ?>
    </main>
    <?php include __DIR__ . '/backend/templates/footer.php'; ?>
