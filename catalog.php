<?php
// Settings lists: zones, sectors, splitters, ONU types, speed profiles and authorization presets.
// One page lays them all out the same way: add, delete unused, export, import, search and sort.
require_once __DIR__ . '/backend/bootstrap.php';

$zones = $mgr->zones();
$sectors = $mgr->sectors();
$types = $mgr->onuTypes();
$speeds = $mgr->speedProfiles();
$nameOf = function (array $rows, $id) { foreach ($rows as $r) if ((string)$r['id'] === (string)$id) return $r['name']; return ''; };
$onusLink = function (string $param) { return function ($r) use ($param) { return '<a href="onus.php?' . $param . '=' . (int)$r['id'] . '">' . (int)$r['onu_count'] . '</a>'; }; };
$sel = function (array $rows, string $none = 'None') { return ['select', $rows, $none]; };

// field => [label, input]; input is 'text', 'number', 'check', 'mode' or a select spec.
$kinds = [
    'zones' => [
        'title' => 'Zones', 'one' => 'Zone', 'kind' => 'zone', 'rows' => $zones, 'save' => [$mgr, 'saveZone'],
        'desc' => 'Use zones to group ONUs per town, neighborhood or village. Zones are simple display separations with no influence on the ONU settings.',
        'fields' => ['name' => ['Name', 'text']],
        'cols' => ['Name' => 'name', 'ONUs' => ['onu_count', $onusLink('zone')]],
    ],
    'sectors' => [
        'title' => 'Sectors', 'one' => 'sector', 'kind' => 'sector', 'rows' => $sectors, 'save' => [$mgr, 'saveSector'],
        'desc' => 'Sectors divide your network, for example into branches, resellers, regions or virtual ISP providers. Manage the sectors that splitters and ONUs belong to.',
        'fields' => ['name' => ['Name', 'text'], 'comment' => ['Comment', 'text']],
        'cols' => ['Name' => 'name', 'Comment' => 'comment', 'Splitters' => 'odb_count', 'ONUs' => ['onu_count', $onusLink('sector')]],
    ],
    'odbs' => [
        'title' => 'Splitters', 'one' => 'Splitter', 'kind' => 'odb', 'rows' => $mgr->odbs(), 'save' => [$mgr, 'saveOdb'],
        'desc' => 'Splitters (ODBs) are the boxes customer drop cables plug into. Set their ports and location to keep track of free ports and see them on the map.',
        'fields' => ['name' => ['Name', 'text'], 'zone_id' => ['Zone', $sel($zones)], 'sector_id' => ['Sector', $sel($sectors)],
            'ports' => ['Ports', 'number', 16], 'lat' => ['Latitude', 'text'], 'lng' => ['Longitude', 'text']],
        'cols' => ['Name' => 'name', 'Zone' => ['zone_name'], 'Sector' => ['sector_id', function ($r) use ($nameOf, $sectors) { return e($nameOf($sectors, $r['sector_id'])); }],
            'Ports used' => ['onu_count', function ($r) { return '<a href="onus.php?odb=' . (int)$r['id'] . '">' . (int)$r['onu_count'] . '</a> of ' . (int)$r['ports'] . ((int)$r['onu_count'] >= (int)$r['ports'] ? ' ' . badge('Full', 'red') : ''); }],
            'Location' => ['lat', function ($r) { return $r['lat'] !== null ? '<span class="so-mono" style="font-size:13px">' . e((float)$r['lat'] . ', ' . (float)$r['lng']) . '</span>' : ''; }]],
    ],
    'types' => [
        'title' => 'ONU types', 'one' => 'ONU type', 'kind' => 'type', 'rows' => $types, 'save' => [$mgr, 'saveOnuType'],
        'desc' => 'Define the ONU models, their PON type and their ports. SmartOLT uses the ONU type to set up the ONU on the OLT, and picks it from the model the OLT reports.',
        'fields' => ['name' => ['ONU type', 'text'], 'eth_ports' => ['Ethernet ports', 'number', 4], 'wifi' => ['WiFi SSIDs', 'number', 0],
            'pots_ports' => ['VoIP ports', 'number', 0], 'catv' => ['CATV', 'check'], 'allow_custom' => ['Allow custom profiles', 'check'],
            'capability' => ['Capability', ['select', [['id' => 'bridge', 'name' => 'Bridging'], ['id' => 'both', 'name' => 'Bridging/Routing']], '']],
            'default_mode' => ['Usual mode', 'mode']],
        'cols' => ['PON type' => ['pon', function () { return 'GPON'; }], 'Channels' => ['chan', function () { return 'G'; }], 'ONU type' => 'name',
            'ONUs' => ['onu_count', $onusLink('type')], 'ETH ports' => 'eth_ports', 'WiFi' => 'wifi', 'VoIP ports' => 'pots_ports', 'CATV' => 'catv',
            'Allow custom profiles' => ['allow_custom', function ($r) { return $r['allow_custom'] ? 'Yes' : 'No'; }],
            'Capability' => ['capability', function ($r) { return $r['capability'] === 'bridge' ? 'Bridging' : 'Bridging/Routing'; }]],
    ],
    'speeds' => [
        'title' => 'Speed profiles', 'one' => 'Speed profile', 'kind' => 'speed', 'rows' => $speeds, 'save' => [$mgr, 'saveSpeedProfile'],
        'desc' => 'Download and upload limits. A profile is created on the OLT as a traffic table the first time an ONU there gets it. To move ONUs to a new speed, use Bulk actions under Tasks.',
        'fields' => ['name' => ['Name', 'text'], 'down_mbps' => ['Download Mbps', 'number', ''], 'up_mbps' => ['Upload Mbps', 'number', '']],
        'cols' => ['Name' => 'name', 'Download' => ['down_kbps', function ($r) { return fmt_speed($r['down_kbps']); }], 'Upload' => ['up_kbps', function ($r) { return fmt_speed($r['up_kbps']); }],
            'ONUs' => ['onu_count', $onusLink('speed')]],
    ],
    'presets' => [
        'title' => 'Presets', 'one' => 'Preset', 'kind' => 'preset', 'rows' => $mgr->presets(), 'save' => [$mgr, 'savePreset'],
        'desc' => 'Ready made settings to authorize an ONU in one click. Auto actions and ONUs added for later authorization use them too. Empty profiles mean the ones most ONUs on that OLT use.',
        'fields' => ['name' => ['Name', 'text'], 'onu_type_id' => ['ONU type', $sel($types, 'From the model the OLT reports')], 'mode' => ['ONU mode', 'mode'],
            'vlan' => ['User VLAN-ID', 'number', ''], 'speed_profile_id' => ['Speed profile', $sel($speeds, 'No speed limit')],
            'zone_id' => ['Zone', $sel($zones)], 'sector_id' => ['Sector', $sel($sectors)],
            'line_profile' => ['Line profile ID', 'number', ''], 'srv_profile' => ['Service profile ID', 'number', ''], 'gemport' => ['GEM port', 'number', 1]],
        'cols' => ['Name' => 'name', 'ONU type' => ['type_name'], 'Mode' => ['mode', function ($r) { return badge($r['mode'] === 'router' ? 'Router' : 'Bridge', 'blue'); }],
            'VLAN' => 'vlan', 'Speed' => ['speed_name'], 'Zone' => ['zone_name'],
            'Profiles' => ['line_profile', function ($r) { return $r['line_profile'] === null ? '<span class="so-muted">OLT default</span>' : 'Line ' . (int)$r['line_profile'] . ', service ' . (int)$r['srv_profile']; }]],
    ],
];
$k = isset($kinds[$_GET['k'] ?? '']) ? $_GET['k'] : 'zones';
$K = $kinds[$k];
$self = 'catalog.php?k=' . $k;

/** Form values for one row: what the inputs hold (speeds as Mbps). */
$formValues = function (array $r) use ($K) {
    $v = [];
    foreach ($K['fields'] as $f => $spec) {
        if ($f === 'down_mbps') $v[$f] = $r['down_kbps'] / 1024;
        elseif ($f === 'up_mbps') $v[$f] = $r['up_kbps'] / 1024;
        else $v[$f] = $r[$f] ?? '';
    }
    return $v + ['id' => $r['id']];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        switch ($_POST['do'] ?? '') {
            case 'save':
                call_user_func($K['save'], $_POST);
                back($self, 'success', ucfirst($K['one']) . ' saved.');
            case 'delete':
                $mgr->deleteCatalog($K['kind'], (int)($_POST['id'] ?? 0));
                back($self, 'success', 'Removed.');
            case 'unused':
                $n = $mgr->deleteUnused($K['kind']);
                back($self, 'success', $n ? "Removed {$n} not used by any ONU." : 'Every one is in use, nothing removed.');
            case 'import':
                $rows = csv_upload('file');
                $head = array_map(function ($h) { return strtolower(trim($h)); }, array_shift($rows));
                $done = 0; $skipped = [];
                foreach ($rows as $i => $r) {
                    if (!array_filter($r)) continue;
                    $in = [];
                    foreach ($K['fields'] as $f => [$label, $type]) {
                        $c = array_search(strtolower($label), $head, true);
                        if ($c === false) continue;
                        $val = trim((string)($r[$c] ?? ''));
                        if (is_array($type)) {
                            // Lists are written by name in the file.
                            $id = '';
                            foreach ($type[1] as $opt) if (strcasecmp($opt['name'], $val) === 0) $id = $opt['id'];
                            $in[$f] = $id;
                        } elseif ($type === 'check') {
                            $in[$f] = in_array(strtolower($val), ['1', 'yes', 'true'], true) ? 1 : '';
                        } elseif ($type === 'mode') {
                            $in[$f] = strtolower($val) === 'router' ? 'router' : 'bridge';
                        } else {
                            $in[$f] = $val;
                        }
                    }
                    try { call_user_func($K['save'], $in); $done++; } catch (InvalidArgumentException $e) { $skipped[] = 'Row ' . ($i + 2) . ': ' . $e->getMessage(); }
                }
                if ($skipped) flash('error', count($skipped) . ' rows skipped: ' . implode('; ', array_slice($skipped, 0, 5)));
                back($self, 'success', "Imported {$done}.");
        }
    } catch (InvalidArgumentException $e) {
        back($self, 'error', $e->getMessage());
    }
}

$rows = $K['rows'];
if (($_GET['export'] ?? '') === 'csv') {
    $header = array_map(function ($f) { return $f[0]; }, $K['fields']);
    $out = [];
    foreach ($rows as $r) {
        $v = $formValues($r);
        $line = [];
        foreach ($K['fields'] as $f => [$label, $type]) {
            if (is_array($type)) $line[] = $nameOf($type[1], $v[$f]);
            elseif ($type === 'check') $line[] = $v[$f] ? 'yes' : 'no';
            else $line[] = $v[$f];
        }
        $out[] = $line;
    }
    csv_download($k, array_values($header), $out);
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $rows = array_values(array_filter($rows, function ($r) use ($q) { return stripos($r['name'], $q) !== false || stripos((string)($r['comment'] ?? ''), $q) !== false; }));
}
$sortKeys = [];
foreach ($K['cols'] as $label => $c) $sortKeys[is_array($c) ? $c[0] : $c] = true;
$sort = (string)($_GET['sort'] ?? 'name');
$key = preg_replace('/_desc$/', '', $sort);
if (isset($sortKeys[$key])) {
    usort($rows, function ($a, $b) use ($key) { $x = $a[$key] ?? ''; $y = $b[$key] ?? ''; return is_numeric($x) && is_numeric($y) ? $x <=> $y : strnatcasecmp((string)$x, (string)$y); });
    if ($sort !== $key) $rows = array_reverse($rows);
}

$pageTitle = $K['title'];
include __DIR__ . '/backend/templates/header.php';
$buttons = '<button type="button" class="so-btn so-btn-green" data-open="editModal" data-new="1"><i class="fa fa-plus"></i> Add ' . e($K['one'] === 'sector' ? 'sector' : $K['one']) . '</button>';
if ($k !== 'presets') {
    $buttons .= '<form method="POST" style="display:inline" data-confirm="Remove every ' . e(strtolower($K['one'])) . ' that no ONU uses?">' . csrf_field()
        . '<input type="hidden" name="do" value="unused"><button class="so-btn so-btn-red"><i class="fa fa-trash"></i> Delete unused ' . e(strtolower($K['title'])) . '</button></form>';
}
$buttons .= btn('Export', 'grey', $self . '&export=csv', 'upload') . '<button type="button" class="so-btn so-btn-grey" data-open="importModal"><i class="fa fa-download"></i> Import</button>';
?>
<?php echo page_title($K['title'], $K['desc'], $buttons); ?>
<?php echo flash_messages(); ?>
<form method="GET" class="so-box" style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px">
    <input type="hidden" name="k" value="<?php echo e($k); ?>">
    <div><label class="so-label">Search</label><div class="so-search"><i class="fa fa-search"></i><input name="q" value="<?php echo e($q); ?>" class="so-input" style="width:220px" placeholder="<?php echo $k === 'sectors' ? 'Name or comment' : 'Name'; ?>"></div></div>
    <a href="<?php echo e($self); ?>" style="color:var(--text);font-weight:700;font-size:14px;padding-bottom:10px"><i class="fa fa-times"></i> Reset filters</a>
</form>

<?php if (!$rows): ?>
    <div class="so-empty">No <?php echo e(strtolower($K['title'])); ?> found</div>
<?php else: ?>
<div class="so-table-wrap"><table class="so-table">
    <thead><tr>
        <?php foreach ($K['cols'] as $label => $c): ?><th><?php echo sort_th($label, is_array($c) ? $c[0] : $c, $sort); ?></th><?php endforeach; ?>
        <th class="center">Action</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <?php $first = true; foreach ($K['cols'] as $label => $c):
                $f = is_array($c) ? $c[0] : $c;
                $cell = is_array($c) && isset($c[1]) ? $c[1]($r) : e($r[$f] ?? ''); ?>
                <td><?php if ($f === 'name'): ?><a href="#" class="so-edit" data-row="<?php echo e(json_encode($formValues($r))); ?>"><?php echo $cell; ?></a><?php else: echo $cell; endif; ?></td>
            <?php $first = false; endforeach; ?>
            <td class="center"><?php echo post_btn('delete', ['id' => $r['id']], '', 'red', 'trash',
                'Remove ' . $r['name'] . '?' . ((int)($r['onu_count'] ?? 0) ? ' ' . (int)$r['onu_count'] . ' ONUs lose this label; nothing changes on the OLT.' : '')); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<div class="so-modal" id="editModal">
    <form method="POST" class="so-modal-box">
        <div class="so-modal-h"><span id="editTitle"><?php echo e($K['one']); ?></span> <button type="button" data-close>&times;</button></div>
        <div class="so-modal-b">
            <?php echo csrf_field(); ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="">
            <?php foreach ($K['fields'] as $f => $spec): [$label, $type] = $spec; ?>
                <div class="so-field">
                    <?php if ($type === 'check'): ?>
                        <label class="so-check"><input type="checkbox" name="<?php echo $f; ?>" value="1"> <?php echo e($label); ?></label>
                    <?php else: ?>
                        <label class="so-label"><?php echo e($label); ?></label>
                        <?php if (is_array($type)): ?>
                            <select name="<?php echo $f; ?>" class="so-select"><?php echo options($type[1], '', $type[2]); ?></select>
                        <?php elseif ($type === 'mode'): ?>
                            <select name="<?php echo $f; ?>" class="so-select"><option value="bridge">Bridging</option><option value="router">Routing</option></select>
                        <?php else: ?>
                            <input name="<?php echo $f; ?>" class="so-input" <?php echo $type === 'number' ? 'type="number" step="any" min="0"' : 'maxlength="100"'; ?>
                                <?php echo $f === 'name' ? 'required' : ''; ?> data-default="<?php echo e($spec[2] ?? ''); ?>">
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="so-modal-f"><button type="button" class="so-btn so-btn-dark" data-close>Cancel</button><button class="so-btn so-btn-green">Save</button></div>
    </form>
</div>
<div class="so-modal" id="importModal">
    <form method="POST" enctype="multipart/form-data" class="so-modal-box">
        <div class="so-modal-h">Import <?php echo e(strtolower($K['title'])); ?> <button type="button" data-close>&times;</button></div>
        <div class="so-modal-b">
            <?php echo csrf_field(); ?><input type="hidden" name="do" value="import">
            <p class="so-muted" style="margin-top:0">A CSV file with a header row using these column names: <b><?php echo e(implode(', ', array_map(function ($f) { return $f[0]; }, $K['fields']))); ?></b>. Export gives you a file in this layout.</p>
            <input type="file" name="file" accept=".csv,text/csv" required>
        </div>
        <div class="so-modal-f"><button type="button" class="so-btn so-btn-dark" data-close>Cancel</button><button class="so-btn so-btn-blue">Import</button></div>
    </form>
</div>
<script>
(function () {
    var modal = document.getElementById('editModal'), form = modal.querySelector('form');
    function fill(row) {
        form.querySelectorAll('[name]').forEach(function (el) {
            var n = el.name;
            if (n === 'csrf' || n === 'do') return;
            var v = row ? row[n] : (el.getAttribute('data-default') || '');
            if (el.type === 'checkbox') el.checked = row ? !!Number(v) : false;
            else if (el.tagName === 'SELECT') el.value = v === null || v === undefined || v === '' ? el.options[0].value : v;
            else el.value = v === null || v === undefined ? '' : v;
        });
        document.getElementById('editTitle').textContent = (row ? 'Edit ' : 'Add ') + <?php echo json_encode(strtolower($K['one'])); ?>;
    }
    document.querySelectorAll('.so-edit').forEach(function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); fill(JSON.parse(a.getAttribute('data-row'))); modal.classList.add('show'); });
    });
    document.querySelector('[data-new]').addEventListener('click', function () { fill(null); });
})();
</script>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
