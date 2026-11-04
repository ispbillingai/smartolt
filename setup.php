<?php
require_once __DIR__ . '/backend/bootstrap.php';

$tabs = ['zones' => 'Zones', 'odbs' => 'ODBs', 'types' => 'ONU Types', 'speeds' => 'Speed Profiles'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'zones';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $tab = isset($tabs[$_POST['tab'] ?? '']) ? $_POST['tab'] : 'zones';
    $return = "setup.php?tab={$tab}";
    try {
        switch ($_POST['do'] ?? '') {
            case 'save_zone':  $mgr->saveZone($_POST); back($return, 'success', 'Zone saved.');
            case 'save_odb':   $mgr->saveOdb($_POST); back($return, 'success', 'ODB saved.');
            case 'save_type':  $mgr->saveOnuType($_POST); back($return, 'success', 'ONU type saved.');
            case 'save_speed': $mgr->saveSpeedProfile($_POST); back($return, 'success', 'Speed profile saved. It is created on an OLT the first time an ONU there gets it.');
            case 'delete':
                $mgr->deleteCatalog((string)($_POST['kind'] ?? ''), (int)($_POST['id'] ?? 0));
                back($return, 'success', 'Removed.');
        }
    } catch (InvalidArgumentException $e) {
        back($return . (!empty($_POST['id']) ? '&edit=' . (int)$_POST['id'] : ''), 'error', $e->getMessage());
    }
}

$zones = $mgr->zones();
$odbs = $mgr->odbs();
$types = $mgr->onuTypes();
$speeds = $mgr->speedProfiles();
$editId = (int)($_GET['edit'] ?? 0);
$pick = function (array $rows) use ($editId) {
    foreach ($rows as $r) if ((int)$r['id'] === $editId) return $r;
    return [];
};

/** Delete button for one row. */
$del = function (string $kind, array $r, string $what) use ($tab): string {
    $warn = (int)($r['onu_count'] ?? 0) ? " {$r['onu_count']} ONUs lose this label, nothing changes on the OLT." : '';
    return '<form method="POST" class="inline" onsubmit="return confirm(\'Remove ' . e(addslashes($r['name'])) . '?' . e(addslashes($warn)) . '\');">'
        . csrf_field() . '<input type="hidden" name="do" value="delete"><input type="hidden" name="tab" value="' . $tab . '">'
        . '<input type="hidden" name="kind" value="' . $kind . '"><input type="hidden" name="id" value="' . (int)$r['id'] . '">'
        . '<button class="' . UI_BTN_RED . '">Remove</button></form>';
};
$editLink = function (array $r) use ($tab): string {
    return '<a href="setup.php?tab=' . $tab . '&edit=' . (int)$r['id'] . '" class="' . UI_BTN_GHOST . '">Edit</a>';
};
$onusLink = function (string $param, array $r): string {
    return '<a class="text-brand-600 dark:text-brand-400" href="onus.php?' . $param . '=' . (int)$r['id'] . '">' . (int)$r['onu_count'] . '</a>';
};

$pageTitle = 'Network Setup';
include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
?>
<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <?php echo page_heading('Network Setup', 'Zones, splitter boxes, ONU models and speeds you pick from when you authorize an ONU.'); ?>
        <?php echo flash_messages(); ?>
        <?php echo tabs(array_combine(array_map(function ($k) { return "setup.php?tab={$k}"; }, array_keys($tabs)), array_values($tabs)), "setup.php?tab={$tab}"); ?>

        <?php if ($tab === 'zones'): $ed = $pick($zones); ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div><?php echo panel_open($ed ? 'Edit zone' : 'Add zone'); ?>
                <form method="POST" class="space-y-4">
                    <?php echo csrf_field(); ?><input type="hidden" name="do" value="save_zone"><input type="hidden" name="tab" value="zones"><input type="hidden" name="id" value="<?php echo (int)($ed['id'] ?? 0); ?>">
                    <div><label class="<?php echo UI_LABEL; ?>">Name</label><input name="name" required maxlength="100" class="<?php echo UI_INPUT; ?>" value="<?php echo e($ed['name'] ?? ''); ?>" placeholder="An area, estate or town"></div>
                    <div class="flex gap-2"><button class="<?php echo UI_BTN_PRIMARY; ?>">Save</button><?php if ($ed): ?><a href="setup.php?tab=zones" class="<?php echo UI_BTN_GHOST; ?>">Cancel</a><?php endif; ?></div>
                </form>
            <?php echo panel_close(); ?></div>
            <div class="lg:col-span-2"><?php echo panel_open(count($zones) . ' zones'); ?>
                <table class="w-full text-sm text-left">
                    <thead class="<?php echo UI_THEAD; ?>"><tr><th class="<?php echo UI_TH; ?>">Zone</th><th class="<?php echo UI_TH; ?>">ONUs</th><th class="<?php echo UI_TH; ?> text-right">Action</th></tr></thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <?php if (!$zones): ?><tr><td colspan="3" class="px-4 py-8 text-center text-slate-400">No zones yet. Zones group your ONUs by area so you can filter and count them.</td></tr><?php endif; ?>
                    <?php foreach ($zones as $r): ?>
                        <tr><td class="<?php echo UI_TD; ?> font-medium"><?php echo e($r['name']); ?></td><td class="<?php echo UI_TD; ?>"><?php echo $onusLink('zone', $r); ?></td>
                            <td class="<?php echo UI_TD; ?> text-right whitespace-nowrap"><?php echo $editLink($r) . ' ' . $del('zone', $r, 'zone'); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php echo panel_close(); ?></div>
        </div>

        <?php elseif ($tab === 'odbs'): $ed = $pick($odbs); ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div><?php echo panel_open($ed ? 'Edit ODB' : 'Add ODB'); ?>
                <form method="POST" class="space-y-4">
                    <?php echo csrf_field(); ?><input type="hidden" name="do" value="save_odb"><input type="hidden" name="tab" value="odbs"><input type="hidden" name="id" value="<?php echo (int)($ed['id'] ?? 0); ?>">
                    <div><label class="<?php echo UI_LABEL; ?>">Name</label><input name="name" required maxlength="100" class="<?php echo UI_INPUT; ?>" value="<?php echo e($ed['name'] ?? ''); ?>" placeholder="For example ODB 12 Main Road"></div>
                    <div><label class="<?php echo UI_LABEL; ?>">Zone</label><select name="zone_id" class="<?php echo UI_INPUT; ?>"><?php echo options($zones, $ed['zone_id'] ?? '', 'No zone'); ?></select></div>
                    <div><label class="<?php echo UI_LABEL; ?>">Ports</label><input name="ports" type="number" min="1" max="64" class="<?php echo UI_INPUT; ?>" value="<?php echo (int)($ed['ports'] ?? 16); ?>"></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="<?php echo UI_LABEL; ?>">Latitude</label><input name="lat" class="<?php echo UI_INPUT; ?>" value="<?php echo e($ed['lat'] ?? ''); ?>" placeholder="-1.2921"></div>
                        <div><label class="<?php echo UI_LABEL; ?>">Longitude</label><input name="lng" class="<?php echo UI_INPUT; ?>" value="<?php echo e($ed['lng'] ?? ''); ?>" placeholder="36.8219"></div>
                    </div>
                    <div class="flex gap-2"><button class="<?php echo UI_BTN_PRIMARY; ?>">Save</button><?php if ($ed): ?><a href="setup.php?tab=odbs" class="<?php echo UI_BTN_GHOST; ?>">Cancel</a><?php endif; ?></div>
                </form>
            <?php echo panel_close(); ?></div>
            <div class="lg:col-span-2"><?php echo panel_open(count($odbs) . ' ODBs'); ?>
                <div class="overflow-x-auto"><table class="w-full text-sm text-left">
                    <thead class="<?php echo UI_THEAD; ?>"><tr><th class="<?php echo UI_TH; ?>">ODB</th><th class="<?php echo UI_TH; ?>">Zone</th><th class="<?php echo UI_TH; ?>">Ports used</th><th class="<?php echo UI_TH; ?>">Location</th><th class="<?php echo UI_TH; ?> text-right">Action</th></tr></thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <?php if (!$odbs): ?><tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">No ODBs yet. An ODB is the splitter box a customer's drop cable plugs into.</td></tr><?php endif; ?>
                    <?php foreach ($odbs as $r): $full = (int)$r['onu_count'] >= (int)$r['ports']; ?>
                        <tr><td class="<?php echo UI_TD; ?> font-medium"><?php echo e($r['name']); ?></td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300"><?php echo $r['zone_name'] !== null ? e($r['zone_name']) : '--'; ?></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo $onusLink('odb', $r); ?> of <?php echo (int)$r['ports']; ?> <?php echo $full ? badge('Full', 'rose') : ''; ?></td>
                            <td class="<?php echo UI_TD; ?> font-mono text-xs"><?php echo $r['lat'] !== null ? e(rtrim(rtrim($r['lat'], '0'), '.') . ', ' . rtrim(rtrim($r['lng'], '0'), '.')) : '--'; ?></td>
                            <td class="<?php echo UI_TD; ?> text-right whitespace-nowrap"><?php echo $editLink($r) . ' ' . $del('odb', $r, 'ODB'); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php echo panel_close(); ?></div>
        </div>

        <?php elseif ($tab === 'types'): $ed = $pick($types); ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div><?php echo panel_open($ed ? 'Edit ONU type' : 'Add ONU type'); ?>
                <form method="POST" class="space-y-4">
                    <?php echo csrf_field(); ?><input type="hidden" name="do" value="save_type"><input type="hidden" name="tab" value="types"><input type="hidden" name="id" value="<?php echo (int)($ed['id'] ?? 0); ?>">
                    <div><label class="<?php echo UI_LABEL; ?>">Model</label><input name="name" required maxlength="60" class="<?php echo UI_INPUT; ?>" value="<?php echo e($ed['name'] ?? ''); ?>" placeholder="As the OLT reports it, for example HG8546M"></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="<?php echo UI_LABEL; ?>">LAN ports</label><input name="eth_ports" type="number" min="0" max="24" class="<?php echo UI_INPUT; ?>" value="<?php echo (int)($ed['eth_ports'] ?? 1); ?>"></div>
                        <div><label class="<?php echo UI_LABEL; ?>">Phone ports</label><input name="pots_ports" type="number" min="0" max="8" class="<?php echo UI_INPUT; ?>" value="<?php echo (int)($ed['pots_ports'] ?? 0); ?>"></div>
                    </div>
                    <div class="flex gap-6 text-sm">
                        <label class="flex items-center gap-2"><input type="checkbox" name="wifi" value="1" <?php echo !empty($ed['wifi']) ? 'checked' : ''; ?>> WiFi</label>
                        <label class="flex items-center gap-2"><input type="checkbox" name="catv" value="1" <?php echo !empty($ed['catv']) ? 'checked' : ''; ?>> CATV</label>
                    </div>
                    <div><label class="<?php echo UI_LABEL; ?>">Usual mode</label><select name="default_mode" class="<?php echo UI_INPUT; ?>">
                        <option value="bridge">Bridge</option><option value="router" <?php echo ($ed['default_mode'] ?? '') === 'router' ? 'selected' : ''; ?>>Router</option></select></div>
                    <div class="flex gap-2"><button class="<?php echo UI_BTN_PRIMARY; ?>">Save</button><?php if ($ed): ?><a href="setup.php?tab=types" class="<?php echo UI_BTN_GHOST; ?>">Cancel</a><?php endif; ?></div>
                </form>
            <?php echo panel_close(); ?></div>
            <div class="lg:col-span-2"><?php echo panel_open(count($types) . ' ONU types'); ?>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">When an ONU is authorized, the type whose model matches what the OLT reports is picked for you.</p>
                <div class="overflow-x-auto"><table class="w-full text-sm text-left">
                    <thead class="<?php echo UI_THEAD; ?>"><tr><th class="<?php echo UI_TH; ?>">Model</th><th class="<?php echo UI_TH; ?>">Ports</th><th class="<?php echo UI_TH; ?>">Usual mode</th><th class="<?php echo UI_TH; ?>">ONUs</th><th class="<?php echo UI_TH; ?> text-right">Action</th></tr></thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <?php foreach ($types as $r): ?>
                        <tr><td class="<?php echo UI_TD; ?> font-medium font-mono"><?php echo e($r['name']); ?></td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300"><?php echo (int)$r['eth_ports']; ?> LAN<?php echo (int)$r['pots_ports'] ? ', ' . (int)$r['pots_ports'] . ' phone' : ''; ?><?php echo $r['wifi'] ? ', WiFi' : ''; ?><?php echo $r['catv'] ? ', CATV' : ''; ?></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo $r['default_mode'] === 'router' ? 'Router' : 'Bridge'; ?></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo $onusLink('type', $r); ?></td>
                            <td class="<?php echo UI_TD; ?> text-right whitespace-nowrap"><?php echo $editLink($r) . ' ' . $del('type', $r, 'ONU type'); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php echo panel_close(); ?></div>
        </div>

        <?php else: $ed = $pick($speeds); ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div><?php echo panel_open($ed ? 'Edit speed profile' : 'Add speed profile'); ?>
                <form method="POST" class="space-y-4">
                    <?php echo csrf_field(); ?><input type="hidden" name="do" value="save_speed"><input type="hidden" name="tab" value="speeds"><input type="hidden" name="id" value="<?php echo (int)($ed['id'] ?? 0); ?>">
                    <div><label class="<?php echo UI_LABEL; ?>">Name</label><input name="name" required maxlength="100" class="<?php echo UI_INPUT; ?>" value="<?php echo e($ed['name'] ?? ''); ?>" placeholder="For example Home 10 Mbps"></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="<?php echo UI_LABEL; ?>">Download Mbps</label><input name="down_mbps" type="number" step="0.01" min="0.07" required class="<?php echo UI_INPUT; ?>" value="<?php echo $ed ? e($ed['down_kbps'] / 1024) : ''; ?>"></div>
                        <div><label class="<?php echo UI_LABEL; ?>">Upload Mbps</label><input name="up_mbps" type="number" step="0.01" min="0.07" required class="<?php echo UI_INPUT; ?>" value="<?php echo $ed ? e($ed['up_kbps'] / 1024) : ''; ?>"></div>
                    </div>
                    <div class="flex gap-2"><button class="<?php echo UI_BTN_PRIMARY; ?>">Save</button><?php if ($ed): ?><a href="setup.php?tab=speeds" class="<?php echo UI_BTN_GHOST; ?>">Cancel</a><?php endif; ?></div>
                </form>
                <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">Editing a speed changes it for ONUs you set from now on. To move ONUs that already have it, tick them under Configured ONUs and choose Set speed.</p>
            <?php echo panel_close(); ?></div>
            <div class="lg:col-span-2"><?php echo panel_open(count($speeds) . ' speed profiles'); ?>
                <div class="overflow-x-auto"><table class="w-full text-sm text-left">
                    <thead class="<?php echo UI_THEAD; ?>"><tr><th class="<?php echo UI_TH; ?>">Profile</th><th class="<?php echo UI_TH; ?>">Download</th><th class="<?php echo UI_TH; ?>">Upload</th><th class="<?php echo UI_TH; ?>">ONUs</th><th class="<?php echo UI_TH; ?> text-right">Action</th></tr></thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <?php if (!$speeds): ?><tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">No speed profiles yet. Add one, then pick it when you authorize an ONU or change its service.</td></tr><?php endif; ?>
                    <?php foreach ($speeds as $r): ?>
                        <tr><td class="<?php echo UI_TD; ?> font-medium"><?php echo e($r['name']); ?></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo fmt_speed($r['down_kbps']); ?></td><td class="<?php echo UI_TD; ?>"><?php echo fmt_speed($r['up_kbps']); ?></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo $onusLink('speed', $r); ?></td>
                            <td class="<?php echo UI_TD; ?> text-right whitespace-nowrap"><?php echo $editLink($r) . ' ' . $del('speed', $r, 'speed profile'); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php echo panel_close(); ?></div>
        </div>
        <?php endif; ?>
    </main>
    <?php include __DIR__ . '/backend/templates/footer.php'; ?>
