<?php
// Small pieces of markup every page shares. Styles live in assets/app.css.

const SMARTOLT_VERSION = '1.5.0';

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf'] ?? '') . '">';
}

/** Title, description and the buttons on the right, as at the top of each page. */
function page_title(string $title, string $desc = '', string $buttons = ''): string
{
    return '<div class="so-title"><div><h1>' . e($title) . '</h1>' . ($desc !== '' ? '<p>' . e($desc) . '</p>' : '') . '</div>'
        . ($buttons !== '' ? '<div class="so-actions">' . $buttons . '</div>' : '') . '</div>';
}

/** A button or link button. $tone: green, blue, red, orange, grey, dark. */
function btn(string $label, string $tone, string $href = '', string $icon = '', string $extra = ''): string
{
    $inner = ($icon !== '' ? '<i class="fa fa-' . $icon . '"></i>' : '') . ($label !== '' ? ' ' . e($label) : '');
    $cls = 'so-btn so-btn-' . $tone;
    return $href !== ''
        ? '<a href="' . e($href) . '" class="' . $cls . '" ' . $extra . '>' . $inner . '</a>'
        : '<button class="' . $cls . '" ' . $extra . '>' . $inner . '</button>';
}

/** A one button POST form, for actions on a row. */
function post_btn(string $action, array $fields, string $label, string $tone, string $icon = '', string $confirm = '', ?string $busy = null, string $to = ''): string
{
    $h = '<form method="POST" style="display:inline"' . ($to !== '' ? ' action="' . e($to) . '"' : '')
        . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . ($busy !== null ? ' data-busy="' . e($busy) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="do" value="' . e($action) . '">';
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    return $h . btn($label, $tone, '', $icon, $label === '' ? 'title="' . e(ucfirst(str_replace('_', ' ', $action))) . '" aria-label="' . e($action) . '"' : '') . '</form>';
}

/** Small coloured label. $tone: blue, green, red, orange, grey. */
function badge(string $text, string $tone = ''): string
{
    return '<span class="so-badge' . ($tone !== '' ? ' b-' . $tone : '') . '">' . e($text) . '</span>';
}

const STATUS_NAMES = ['online' => 'Online', 'power_fail' => 'Power fail', 'los' => 'LOS', 'other' => 'Offline', 'disabled' => 'Disabled'];

function status_label(string $group): string
{
    return ['online' => 'Online', 'power_fail' => 'Power fail', 'los' => 'Signal loss (LOS)', 'disabled' => 'Disabled', 'other' => 'Offline'][$group] ?? 'Not read yet';
}

const ACTION_LABELS = [
    'admin.password' => 'Change panel password', 'olt.backup' => 'Back up OLT configuration', 'olt.backup_download' => 'Download OLT backup', 'olt.backup_delete' => 'Delete OLT backup',
    'onu.authorize' => 'Authorize ONU', 'onu.reboot' => 'Reboot ONU', 'onu.delete' => 'Delete ONU', 'onu.describe' => 'Rename ONU',
    'onu.status' => 'Read ONU', 'onu.autofind' => 'Search for ONUs', 'onu.config' => 'Show running config',
    'onu.service' => 'Change VLAN or speed', 'onu.enable' => 'Enable ONU', 'onu.disable' => 'Disable ONU', 'onu.replace_sn' => 'Replace ONU',
    'onu.bulk_reboot' => 'Reboot many ONUs', 'onu.bulk_disable' => 'Disable many ONUs', 'onu.bulk_enable' => 'Enable many ONUs', 'onu.bulk_speed' => 'Set speed on many ONUs',
    'olt.refresh' => 'Read OLT', 'olt.delete' => 'Remove OLT', 'olt.save' => 'Save OLT configuration', 'olt.diag' => 'Diagnostics',
    'onu.sw_info' => 'ONU software info', 'onu.restore' => 'Restore ONU defaults', 'olt.vlan_add' => 'Add VLAN', 'olt.vlan_delete' => 'Delete VLAN', 'olt.cli' => 'CLI command', 'report.export' => 'Export ONUs',
];

/** "0/1/0 HWTC12345678 authorized" style line for the dashboard Info list. */
function action_text(array $a): string
{
    $done = ['onu.authorize' => 'authorized', 'onu.reboot' => 'rebooted', 'onu.delete' => 'deleted', 'onu.describe' => 'renamed',
        'onu.service' => 'service changed', 'onu.enable' => 'enabled', 'onu.disable' => 'disabled', 'onu.replace_sn' => 'replaced',
        'olt.refresh' => 'read', 'olt.save' => 'configuration saved', 'olt.delete' => 'removed', 'onu.status' => 'read live', 'admin.password' => 'password changed'][$a['action']] ?? (ACTION_LABELS[$a['action']] ?? $a['action']);
    $who = $a['source'] === 'auto' ? ' by auto action' : ($a['source'] === 'api' ? ' from the billing dashboard' : ($a['source'] === 'cron' ? '' : ($a['user'] !== '' ? ' by ' . $a['user'] : '')));
    return trim($a['target'] . ' ' . $done) . $who . ($a['ok'] ? '' : ' failed: ' . $a['message']);
}

/** The status icon of the ONU lists: globe for online and offline, plug for power fail, broken link for LOS. */
function status_icon(string $group): string
{
    $icon = ['online' => 'globe', 'power_fail' => 'plug', 'los' => 'chain-broken', 'other' => 'globe', 'disabled' => 'ban'][$group] ?? 'question-circle';
    $cls = isset(STATUS_NAMES[$group]) ? $group : 'unknown';
    return '<i class="fa fa-' . $icon . ' fa-lg st-' . $cls . '" title="' . e(status_label($group)) . '"></i>';
}

/** Status as a word with its colour, for detail pages and history. */
function status_badge(string $group, string $runState = ''): string
{
    $tone = ['online' => 'green', 'power_fail' => 'blue', 'los' => 'red', 'other' => 'grey', 'disabled' => 'grey'][$group] ?? 'grey';
    if (!isset(STATUS_NAMES[$group])) return badge($runState !== '' ? ucfirst($runState) : 'Not read yet', 'grey');
    return badge(status_label($group), $tone);
}

function signal_level($rx): string
{
    if ($rx === null || $rx === '') return '';
    $v = (float)$rx;
    if ($v < OltManager::SIGNAL_CRITICAL || $v > OltManager::SIGNAL_HOT) return 'Critical';
    return $v < OltManager::SIGNAL_WARNING ? 'Warning' : 'Good';
}

/** Signal bars, value and "dBm" under it, coloured good, warning or critical. */
function signal_html($rx): string
{
    if ($rx === null || $rx === '') return '<span class="so-muted">-</span>';
    $lvl = strtolower(signal_level($rx));
    return '<span class="so-signal sig-' . $lvl . '"><i class="fa fa-signal"></i><b>' . number_format((float)$rx, 2) . '</b><small>dBm</small></span>';
}

function fmt_speed($kbps): string
{
    if ($kbps === null || $kbps === '') return '-';
    return OltManager::speedText($kbps);
}

function fmt_distance($m): string
{
    if ($m === null || $m === '') return '-';
    return (int)$m >= 1000 ? number_format((int)$m / 1000, 2) . ' km' : (int)$m . ' m';
}

/** How the OLT is reached, for lists: "VPN to 192.168.100.1" or "41.90.1.2:23". */
function olt_address(array $o): string
{
    return ($o['conn_type'] ?? 'direct') === 'vpn' ? 'VPN to ' . $o['lan_ip'] : $o['host'] . ':' . $o['telnet_port'];
}

/** The ONU's place written the way the lists show it: gpon-onu_0/1/0:3. */
function onu_place(array $u): string
{
    return 'gpon-onu_' . (int)$u['frame'] . '/' . (int)$u['slot'] . '/' . (int)$u['port'] . ':' . (int)$u['ont_id'];
}

/** "5 minutes ago", "3 hours ago", "2 days ago" for a stored time. */
function ago(?string $at): string
{
    if (!$at) return '-';
    $s = max(0, time() - AppTime::timestamp($at));
    if ($s < 60) return $s . ' seconds ago';
    if ($s < 3600) { $n = (int)round($s / 60); return $n . ($n === 1 ? ' minute ago' : ' minutes ago'); }
    if ($s < 86400) { $n = (int)round($s / 3600); return $n . ($n === 1 ? ' hour ago' : ' hours ago'); }
    $d = (int)round($s / 86400);
    return $d . ($d === 1 ? ' day ago' : ' days ago');
}

function when(?string $at): string
{
    if (!$at) return '-';
    return AppTime::format('Y-m-d', $at) === AppTime::format('Y-m-d') ? 'Today ' . AppTime::format('H:i:s', $at) : AppTime::format('Y-m-d H:i', $at);
}

/** <option> list from rows with id and name. */
function options(array $rows, $selected, string $none = ''): string
{
    $html = $none !== '' ? '<option value="">' . e($none) . '</option>' : '';
    foreach ($rows as $r) {
        $html .= '<option value="' . e($r['id']) . '"' . ((string)$selected === (string)$r['id'] ? ' selected' : '') . '>' . e($r['name']) . '</option>';
    }
    return $html;
}

/** OLT options shown as "1 - Main OLT". */
function olt_options(array $olts, $selected, string $none = ''): string
{
    $html = $none !== '' ? '<option value="">' . e($none) . '</option>' : '';
    foreach ($olts as $o) {
        $html .= '<option value="' . (int)$o['id'] . '"' . ((string)$selected === (string)$o['id'] ? ' selected' : '') . '>' . (int)$o['id'] . ' - ' . e($o['name']) . '</option>';
    }
    return $html;
}

function flash_messages(): string
{
    $html = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        $html .= '<div class="so-alert ' . ($type === 'error' ? 'a-err' : 'a-ok') . '">' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

function alert(string $msg, string $tone = 'info'): string
{
    return '<div class="so-alert a-' . $tone . '">' . e($msg) . '</div>';
}

function panel_open(string $title, string $tools = '', string $icon = ''): string
{
    return '<div class="so-panel"><div class="so-panel-h"><span>' . ($icon !== '' ? '<i class="fa fa-' . $icon . '"></i> ' : '') . e($title) . '</span>'
        . ($tools !== '' ? '<span class="so-tools">' . $tools . '</span>' : '') . '</div><div class="so-panel-b">';
}

function panel_close(): string
{
    return '</div></div>';
}

/** Column heading that sorts the table by $key when clicked; keeps the other query parameters. */
function sort_th(string $label, string $key, string $current): string
{
    $q = $_GET;
    $asc = $current === $key;
    $q['sort'] = $asc ? $key . '_desc' : $key;
    $icon = $current === $key ? 'sort-asc' : ($current === $key . '_desc' ? 'sort-desc' : 'sort');
    return '<a href="?' . e(http_build_query($q)) . '">' . e($label) . ' <i class="fa fa-' . $icon . '"></i></a>';
}

/** Page links under long tables. */
function pager(int $total, int $page, int $per): string
{
    $pages = (int)ceil($total / $per);
    if ($pages <= 1) return '';
    $q = $_GET;
    $h = '<div class="so-pager">';
    for ($i = 1; $i <= $pages; $i++) {
        if ($pages > 12 && $i > 2 && $i < $pages - 1 && abs($i - $page) > 2) { if (abs($i - $page) === 3) $h .= '<a>...</a>'; continue; }
        $q['page'] = $i;
        $h .= $i === $page ? '<span>' . $i . '</span>' : '<a href="?' . e(http_build_query($q)) . '">' . $i . '</a>';
    }
    return $h . '</div>';
}

/** Send rows as a CSV download and stop. */
function csv_download(string $name, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $header);
    foreach ($rows as $r) fputcsv($out, $r);
    exit;
}

/** Rows of an uploaded CSV file, or throws. */
function csv_upload(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new InvalidArgumentException('Choose a CSV file to import.');
    if ($f['size'] > 5 * 1024 * 1024) throw new InvalidArgumentException('The file is too big, 5 MB at most.');
    $rows = [];
    $h = fopen($f['tmp_name'], 'r');
    while (($r = fgetcsv($h)) !== false) {
        if ($rows === [] && isset($r[0])) $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', $r[0]);
        $rows[] = $r;
    }
    fclose($h);
    if (count($rows) < 2) throw new InvalidArgumentException('The file has no rows under its header.');
    return $rows;
}

/** A drawing of an OLT chassis for the dashboard and OLT page. */
function olt_picture(int $slots = 16): string
{
    $w = 150; $h = 130;
    $svg = '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 150 130" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="OLT">'
        . '<rect x="6" y="4" width="138" height="122" rx="4" fill="#8d96a3" stroke="#5c6470"/>'
        . '<rect x="12" y="10" width="126" height="14" fill="#6c7480"/>'
        . '<rect x="12" y="110" width="126" height="10" fill="#6c7480"/>';
    $sw = 126 / $slots;
    for ($i = 0; $i < $slots; $i++) {
        $x = 12 + $i * $sw;
        $svg .= '<rect x="' . round($x + 1, 1) . '" y="28" width="' . round($sw - 2, 1) . '" height="78" fill="#b9c0c9" stroke="#59606b" stroke-width=".6"/>'
            . '<circle cx="' . round($x + $sw / 2, 1) . '" cy="34" r="1.3" fill="' . ($i % 5 === 0 ? '#2ecc40' : '#59606b') . '"/>';
    }
    return $svg . '</svg>';
}
