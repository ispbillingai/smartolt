<?php
// The floppy icon in the top bar: write the running config of one or all OLTs to their flash.
require_once __DIR__ . '/backend/bootstrap.php';

$back = (string)($_POST['back'] ?? 'index.php');
// Only send people back to a page of this site.
$page = basename((string)parse_url($back, PHP_URL_PATH));
$back = preg_match('/^[a-z_]+\.php$/', $page) ? $page . (($q = parse_url($back, PHP_URL_QUERY)) ? '?' . str_replace(["\r", "\n"], '', $q) : '') : 'index.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') back('index.php', 'error', 'Use the save icon in the top bar.');
require_csrf();
$id = (int)($_POST['olt_id'] ?? 0);
$olts = $id ? array_filter([$mgr->olt($id)]) : $mgr->olts();
if (!$olts) back($back, 'error', 'No OLT to save.');
$done = [];
foreach ($olts as $o) {
    try {
        $mgr->saveConfig((int)$o['id']);
        $done[] = $o['name'];
    } catch (Throwable $e) {
        flash('error', $o['name'] . ': configuration not saved. ' . $e->getMessage());
    }
}
if ($done) flash('success', 'Configuration saved on ' . implode(', ', $done) . '.');
header('Location: ' . $back);
