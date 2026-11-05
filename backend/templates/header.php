<?php
// Page head and the top menu bar. Every signed in page includes this after bootstrap.php and
// footer.php at the end; the page body goes between them inside <main>.
$navHere = basename($_SERVER['PHP_SELF']);
$navQ = $_GET;
$navIsOn = function (array $navPages) use ($navHere, $navQ): bool {
    foreach ($navPages as $navP) {
        [$navFile, $navParam] = array_pad(explode('?', $navP, 2), 2, null);
        if ($navFile !== $navHere) continue;
        if ($navParam === null) return true;
        [$navK, $navV] = explode('=', $navParam);
        if ((string)($navQ[$navK] ?? '') === $navV) return true;
    }
    return false;
};
$navMenu = [
    ['Map', ['ONUs map' => 'map.php', 'Splitters map' => 'map.php?layer=odb']],
    ['Graphs', 'graphs.php'],
    ['Events', 'events.php'],
    ['Diag', 'diag.php'],
    ['Tasks', ['Task history' => 'tasks.php', 'Auto actions' => 'auto_actions.php', 'Bulk actions' => 'onus.php?select=1']],
    ['Reports', [
        'Authorizations' => 'reports.php?r=auth', 'Export' => 'reports.php?r=export', 'Import' => 'reports.php?r=import',
        'Traffic usage' => 'reports.php?r=traffic', 'WiFi signal' => 'reports.php?r=wifi', 'ONU status changes' => 'reports.php?r=status',
        'PON occupancy' => 'reports.php?r=pon', 'Splitter check' => 'reports.php?r=splitters', 'Find config mismatches (DB vs OLT)' => 'reports.php?r=mismatch',
    ]],
    ['Settings', [
        'Zones' => 'catalog.php?k=zones', 'Sectors' => 'catalog.php?k=sectors', 'Splitters' => 'catalog.php?k=odbs',
        'ONU types' => 'catalog.php?k=types', 'Speed profiles' => 'catalog.php?k=speeds', 'OLTs' => 'olts.php',
        'VPN & TR069' => 'vpn.php', 'Presets' => 'catalog.php?k=presets', 'General' => 'settings.php', 'Billing' => 'billing.php',
    ]],
];
$navOlts = $mgr->olts();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(($pageTitle ?? '') !== '' ? $pageTitle . ' · SmartOLT' : 'SmartOLT'); ?></title>
    <link rel="icon" type="image/png" href="https://hotspots.co.ke/logo.png">
    <script>
        // Dark is the default; the half moon icon switches, remembered per browser.
        try { if (localStorage.getItem('smartolt-theme') === 'light') document.documentElement.classList.add('light'); } catch (e) {}
        // Chart colours that follow the theme (pages redraw on the themechange event).
        window.soChart = {
            tick: function () { return document.documentElement.classList.contains('light') ? '#475569' : '#b9c6dc'; },
            grid: function () { return document.documentElement.classList.contains('light') ? 'rgba(100,116,139,.2)' : 'rgba(120,150,200,.15)'; }
        };
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="assets/app.css?v=4">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<nav class="so-nav" id="soNav">
    <div class="so-wrap">
        <a class="so-brand" href="index.php">SMARTOLT</a>
        <button type="button" class="so-burger" id="soBurger" aria-label="Menu"><i class="fa fa-bars"></i></button>
        <ul class="so-menu">
            <li class="<?php echo $navIsOn(['unconfigured.php', 'authorize.php']) ? 'on' : ''; ?>"><a href="unconfigured.php">Unconfigured</a></li>
            <li class="<?php echo $navIsOn(['onus.php', 'onu.php']) && !$navIsOn(['onus.php?select=1']) ? 'on' : ''; ?>"><a href="onus.php">Configured</a></li>
            <?php foreach ($navMenu as [$navLabel, $navTarget]): ?>
                <?php if (is_string($navTarget)): ?>
                    <li class="<?php echo $navIsOn([$navTarget]) ? 'on' : ''; ?>"><a href="<?php echo $navTarget; ?>"><?php echo $navLabel; ?></a></li>
                <?php else: $navOn = $navIsOn(array_values($navTarget)); ?>
                    <li class="<?php echo $navOn ? 'on' : ''; ?>">
                        <button type="button" data-drop><?php echo $navLabel; ?> <span class="so-caret"></span></button>
                        <div class="so-drop">
                            <?php foreach ($navTarget as $navL => $navHref): ?>
                                <a href="<?php echo $navHref; ?>" class="<?php echo $navIsOn([$navHref]) ? 'on' : ''; ?>"><?php echo htmlspecialchars($navL); ?></a>
                            <?php endforeach; ?>
                        </div>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
            <li><a href="#" data-open="soSave" title="Save the OLT configuration"><i class="fa fa-floppy-o"></i></a></li>
            <li><a href="#" id="soTheme" title="Dark or light"><i class="fa fa-adjust"></i></a></li>
            <li class="<?php echo $navIsOn(['help.php']) ? 'on' : ''; ?>"><a href="help.php" title="User guide"><i class="fa fa-book"></i></a></li>
            <li class="<?php echo $navIsOn(['settings.php']) ? 'on' : ''; ?>"><a href="settings.php" title="<?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>"><i class="fa fa-user"></i></a></li>
            <li><a href="logout.php"><i class="fa fa-power-off"></i> Log out</a></li>
        </ul>
    </div>
</nav>

<!-- Save the running config on the OLTs to flash -->
<div class="so-modal" id="soSave">
    <form method="POST" action="save.php" class="so-modal-box" data-busy="Saving the configuration on the OLT...">
        <div class="so-modal-h">Save OLT configuration <button type="button" data-close>&times;</button></div>
        <div class="so-modal-b">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="back" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'index.php'); ?>">
            <p class="so-muted" style="margin-top:0">Writes what is running on the OLT to its memory, so ONUs authorized or changed since the last save are still there after the OLT restarts.</p>
            <label class="so-label">OLT</label>
            <select name="olt_id" class="so-select">
                <option value="0">All OLTs</option>
                <?php foreach ($navOlts as $navO): ?>
                    <option value="<?php echo (int)$navO['id']; ?>"><?php echo (int)$navO['id'] . ' - ' . htmlspecialchars($navO['name']); ?><?php echo $navO['saved_at'] ? ', saved ' . ago($navO['saved_at']) : ''; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="so-modal-f"><button type="button" class="so-btn so-btn-dark" data-close>Cancel</button><button class="so-btn so-btn-blue"><i class="fa fa-floppy-o"></i> Save</button></div>
    </form>
</div>

<!-- Our confirm box for forms marked data-confirm -->
<div class="so-modal" id="soConfirm">
    <div class="so-modal-box">
        <div class="so-modal-h">Please confirm <button type="button" data-close>&times;</button></div>
        <div class="so-modal-b" id="soConfirmText"></div>
        <div class="so-modal-f"><button type="button" class="so-btn so-btn-dark" data-close>Cancel</button><button type="button" class="so-btn so-btn-blue" id="soConfirmYes">Yes</button></div>
    </div>
</div>
<div class="so-busy" id="soBusy"><div><i class="fa fa-refresh fa-spin"></i> <span id="soBusyText">Talking to the OLT...</span></div></div>

<main class="so-wrap so-main">
