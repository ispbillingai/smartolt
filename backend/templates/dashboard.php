<?php
// Dashboard reads the cached fleet state; opening it never initiates a device change.
$olts = $mgr->olts();
$c = $mgr->counts();
$net = $mgr->networkStatus(24);
$out = $mgr->outages();
$events = $mgr->events(['tab' => 'ongoing'], 4);
$eventCounts = $mgr->eventCounts();
$activity = array_slice(array_values(array_filter($mgr->actionsFiltered([], 60), function ($a) {
    return !in_array($a['action'], ['onu.status', 'onu.autofind', 'olt.diag', 'onu.config'], true)
        && !($a['action'] === 'olt.refresh' && $a['source'] === 'cron' && $a['ok']);
})), 0, 6);
$oltId = (int)($_GET['olt'] ?? ($olts[0]['id'] ?? 0));
$selected = null;
$freshCount = 0; $vpnUp = 0; $vpnTotal = 0; $snmpUp = 0; $snmpTotal = 0; $latestRead = null;
$fleet = [];
foreach ($olts as $o) {
    $readAt = !empty($o['last_polled_at']) ? strtotime($o['last_polled_at']) : false;
    $fresh = $readAt !== false && time() - $readAt <= 900;
    $o['_read_state'] = $o['status'] === 'error' ? 'error' : ($fresh && $o['status'] === 'online' ? 'online' : ($readAt ? 'stale' : 'pending'));
    if ($o['_read_state'] === 'online') $freshCount++;
    if ($readAt && $o['status'] === 'online' && ($latestRead === null || $readAt > $latestRead)) $latestRead = $readAt;
    $o['_vpn'] = null;
    if ($o['conn_type'] === 'vpn') { $o['_vpn'] = $mgr->vpnStatus($o); $vpnTotal++; $vpnUp += (int)$o['_vpn']['connected']; }
    if (!empty($o['snmp_enabled'])) {
        $snmpTotal++;
        $snmpAt = !empty($o['snmp_checked_at']) ? strtotime($o['snmp_checked_at']) : false;
        $o['_snmp_fresh'] = $snmpAt && time() - $snmpAt <= 900;
        if (($o['snmp_status'] ?? '') === 'online' && $o['_snmp_fresh']) $snmpUp++;
    }
    if ((int)$o['id'] === $oltId) $selected = $o;
    $fleet[] = $o;
}
usort($fleet, function ($a, $b) { return ($a['_read_state'] === 'online') <=> ($b['_read_state'] === 'online') ?: strcasecmp($a['name'], $b['name']); });
$needsRead = count($olts) - $freshCount;
$onlineShare = $c['total'] ? round($c['online'] * 100 / $c['total'], 1) : null;
$stale = $needsRead > 0;
$chip = function (string $label, string $tone = 'quiet', string $title = ''): string {
    return '<span class="dash-chip ' . e($tone) . '"' . ($title !== '' ? ' title="' . e($title) . '"' : '') . '><span aria-hidden="true"></span>' . e($label) . '</span>';
};
$pageTitle = 'Network operations';
$bodyClass = 'so-operations-page';
include __DIR__ . '/header.php';
?>
<div class="so-dashboard">
    <?php echo flash_messages(); ?>
    <header class="dash-heading">
        <div><p class="dash-eyebrow">Your network / Overview</p><h1>Network operations<span>.</span></h1><p>A clear view of your connections, customers and next steps.</p></div>
        <div class="dash-heading-actions"><button type="button" class="dash-refresh" id="autoRefresh" aria-pressed="true"><i class="fa fa-refresh" aria-hidden="true"></i> <span id="autoText">Auto-refresh on</span></button><a class="dash-primary" href="olts.php?add=1"><i class="fa fa-plus" aria-hidden="true"></i> Add OLT</a></div>
    </header>

    <section class="dash-overview" aria-labelledby="dash-summary">
        <div class="dash-overview-copy">
            <div class="dash-overline"><span class="dash-mark" aria-hidden="true"><i class="fa fa-sitemap"></i></span><span>NETWORK PULSE</span><span class="dash-time"><?php echo e(AppTime::format('D, d M · H:i')); ?></span></div>
            <h2 id="dash-summary"><?php echo !$olts ? 'Connect your first OLT.' : ($needsRead ? 'Keep every connection in view.' : ($c['offline'] || $eventCounts['total'] ? 'Know where to focus next.' : 'Your network is in view.')); ?></h2>
            <p><?php if (!$olts): ?>Add an OLT to discover your devices, track signal quality and manage authorizations.
            <?php elseif ($stale): ?><?php echo $needsRead; ?> OLT<?php echo $needsRead === 1 ? ' needs' : 's need'; ?> a successful recent read. Customer figures below show the last received data.
            <?php else: ?><?php echo number_format($c['online']); ?> of <?php echo number_format($c['total']); ?> authorized ONUs online in the latest reads. Review alerts and service changes from one place.<?php endif; ?></p>
            <div class="dash-summary-chips"><?php echo $chip($freshCount . ' / ' . count($olts) . ' OLTs recently read', $olts && !$stale ? 'good' : 'quiet'); ?><?php echo $chip($eventCounts['total'] . ' open alerts', $eventCounts['total'] ? 'warn' : 'quiet'); ?><span class="dash-updated"><?php echo $latestRead ? 'Latest read ' . ago(date('Y-m-d H:i:s', $latestRead)) : ($olts ? 'No successful OLT read available' : 'Awaiting the first device read'); ?></span></div>
        </div>
        <div class="dash-health" aria-label="<?php echo $onlineShare === null ? 'No customer data yet' : e($onlineShare) . ' percent online at last read'; ?>">
            <div class="dash-ring" style="--share:<?php echo $onlineShare ?? 0; ?>%"><div><strong><?php echo $onlineShare === null ? 'n/a' : number_format($onlineShare, 1) . '<small>%</small>'; ?></strong><span>online at last read</span></div></div>
            <div class="dash-health-legend"><span><i class="good"></i> <?php echo number_format($c['online']); ?> online</span><span><i class="quiet"></i> <?php echo number_format($c['offline']); ?> offline</span></div>
        </div>
    </section>

    <section class="dash-metrics" aria-label="Customer status">
        <a href="onus.php?status=online" class="dash-metric" data-testid="metric-online" data-count="<?php echo (int)$c['online']; ?>"><div class="dash-metric-label"><span>Online customers</span><i class="fa fa-wifi good"></i></div><strong><?php echo number_format($c['online']); ?></strong><div class="dash-metric-detail"><?php echo number_format($c['total']); ?> authorized ONUs <i class="fa fa-arrow-up"></i></div></a>
        <a href="unconfigured.php" class="dash-metric" data-testid="metric-waiting" data-count="<?php echo (int)$c['waiting']; ?>" data-new="<?php echo (int)$c['new']; ?>" data-moved="<?php echo (int)$c['moved']; ?>" data-resync="<?php echo (int)$c['resync']; ?>"><div class="dash-metric-label"><span>Waiting authorization</span><i class="fa fa-plus-circle blue"></i></div><strong><?php echo number_format($c['waiting']); ?></strong><div class="dash-metric-detail"><?php echo (int)$c['new']; ?> new · <?php echo (int)$c['moved']; ?> moved · <?php echo (int)$c['resync']; ?> resync <i class="fa fa-arrow-up"></i></div></a>
        <a href="onus.php?status=offline" class="dash-metric" data-testid="metric-offline" data-count="<?php echo (int)$c['offline']; ?>"><div class="dash-metric-label"><span>Offline customers</span><i class="fa fa-plug rose"></i></div><strong><?php echo number_format($c['offline']); ?></strong><div class="dash-metric-detail">Power fail: <?php echo (int)$c['power_fail']; ?> · Signal loss: <?php echo (int)$c['los']; ?> <i class="fa fa-arrow-up"></i></div></a>
        <a href="onus.php?signal=weak" class="dash-metric" data-testid="metric-signal" data-count="<?php echo (int)$c['warning'] + (int)$c['critical']; ?>"><div class="dash-metric-label"><span>Signal attention</span><i class="fa fa-signal amber"></i></div><strong><?php echo number_format($c['warning'] + $c['critical']); ?></strong><div class="dash-metric-detail"><?php echo (int)$c['warning']; ?> warning · <?php echo (int)$c['critical']; ?> critical <i class="fa fa-arrow-up"></i></div></a>
    </section>

    <div class="dash-main-grid">
        <section class="dash-panel dash-network" aria-labelledby="network-title">
            <div class="dash-panel-heading"><div><p class="dash-eyebrow">CUSTOMER CONNECTIVITY</p><h2 id="network-title">The last 24 hours</h2></div><a href="graphs.php?range=24" class="dash-text-link">Explore graphs <i class="fa fa-arrow-right"></i></a></div>
            <p class="dash-caption">Online and offline states recorded during scheduled OLT reads.</p>
            <?php if (count($net) < 2): ?><div class="dash-empty"><i class="fa fa-area-chart"></i><strong>Your network history starts here</strong><span>At least two OLT reads are needed to draw this chart.</span></div>
            <?php else: ?><div class="dash-chart"><canvas id="netChart" role="img" aria-label="Network status over the last 24 hours"></canvas></div><p class="dash-chart-fallback" hidden>Charts could not load. <a href="reports.php?r=status">View the status report</a> for recorded changes.</p><?php endif; ?>
        </section>
        <section class="dash-panel dash-next" aria-labelledby="next-title">
            <div class="dash-panel-heading"><div><p class="dash-eyebrow">WORK QUEUE</p><h2 id="next-title">Your next steps</h2></div><i class="fa fa-location-arrow"></i></div>
            <?php if (!$olts): ?><a class="dash-task" href="olts.php?add=1"><span class="dash-task-icon blue"><i class="fa fa-server"></i></span><span><b>Connect an OLT</b><small>Start reading your network</small></span><i class="fa fa-angle-right"></i></a><?php endif; ?>
            <?php if ($needsRead): ?><a class="dash-task" href="#fleet"><span class="dash-task-icon rose"><i class="fa fa-refresh"></i></span><span><b>Review <?php echo $needsRead; ?> OLT connection<?php echo $needsRead === 1 ? '' : 's'; ?></b><small>Failed, stale or awaiting first read</small></span><i class="fa fa-angle-right"></i></a><?php endif; ?>
            <a class="dash-task" href="unconfigured.php"><span class="dash-task-icon blue"><i class="fa fa-plus"></i></span><span><b><?php echo $c['waiting'] ? 'Authorize ' . $c['waiting'] . ' waiting ONU' . ($c['waiting'] === 1 ? '' : 's') : 'Review ONU discovery'; ?></b><small><?php echo (int)$c['moved']; ?> moved · <?php echo (int)$c['new']; ?> new devices</small></span><i class="fa fa-angle-right"></i></a>
            <a class="dash-task" href="onus.php?signal=critical"><span class="dash-task-icon amber"><i class="fa fa-signal"></i></span><span><b><?php echo (int)$c['critical']; ?> critical signal<?php echo $c['critical'] === 1 ? '' : 's'; ?></b><small>Check optical levels and fibre paths</small></span><i class="fa fa-angle-right"></i></a>
            <a class="dash-task" href="backups.php"><span class="dash-task-icon good"><i class="fa fa-shield"></i></span><span><b>Configuration backups</b><small>Review and download saved configurations</small></span><i class="fa fa-angle-right"></i></a>
            <div class="dash-note"><i class="fa fa-info-circle"></i><span>Page refreshes every minute. Device data updates on the 5 minute polling schedule.</span></div>
        </section>
    </div>

    <section class="dash-panel dash-fleet" id="fleet" aria-labelledby="fleet-title">
        <div class="dash-panel-heading"><div><p class="dash-eyebrow">DEVICE CONNECTIONS</p><h2 id="fleet-title">Your OLT fleet <span class="dash-count"><?php echo count($olts); ?></span></h2></div><div class="dash-fleet-summary"><span>VPN <b><?php echo $vpnUp; ?>/<?php echo $vpnTotal; ?></b></span><span>SNMP <b><?php echo $snmpUp; ?>/<?php echo $snmpTotal; ?></b></span><a class="dash-text-link" href="olts.php">Manage OLTs <i class="fa fa-arrow-right"></i></a></div></div>
        <?php if (!$fleet): ?><div class="dash-empty"><i class="fa fa-server"></i><strong>No OLTs connected yet</strong><span>Add a direct connection or a MikroTik VPN tunnel.</span><a href="olts.php?add=1" class="dash-primary">Add your first OLT</a></div>
        <?php else: ?><div class="dash-table-wrap"><table class="dash-table"><thead><tr><th>Device</th><th>Customer ONUs</th><th>Telnet read</th><th>SNMP</th><th>Connection</th><th>Last read</th><th><span class="dash-sr-only">Open device</span></th></tr></thead><tbody>
        <?php foreach (array_slice($fleet, 0, 8) as $o):
            $snmpState = empty($o['snmp_enabled']) ? 'disabled' : ($o['snmp_status'] ?? 'pending');
            if (in_array($snmpState, ['online', 'partial'], true) && empty($o['_snmp_fresh'])) $snmpState = 'stale';
            $readLabel = ['online' => 'Recent read', 'error' => 'Failed', 'stale' => 'Stale', 'pending' => 'Not read'][$o['_read_state']];
            $snmpLabel = ['disabled' => 'Not enabled', 'online' => 'Responding', 'partial' => 'Partial read', 'error' => 'Failed', 'stale' => 'Stale', 'pending' => 'Not read'][$snmpState] ?? 'Not read';
        ?><tr><td><a class="dash-device" href="olt.php?id=<?php echo (int)$o['id']; ?>"><span class="dash-device-icon"><i class="fa fa-server"></i></span><span><b><?php echo e($o['name']); ?></b><small><?php echo e($o['model'] ?: 'Model not read'); ?> · <?php echo e($o['conn_type'] === 'vpn' ? $o['lan_ip'] : $o['host']); ?></small></span></a></td>
            <td><strong><?php echo number_format((int)$o['onu_online']); ?></strong><span class="dash-muted"> / <?php echo number_format((int)$o['onu_count']); ?></span><small class="dash-subtext">online / authorized</small></td>
            <td><?php echo $chip($readLabel, $o['_read_state'] === 'online' ? 'good' : ($o['_read_state'] === 'error' ? 'bad' : 'warn'), $o['last_error'] ?? ''); ?></td>
            <td><a href="olt.php?id=<?php echo (int)$o['id']; ?>&tab=uplink"><?php echo $chip($snmpLabel, $snmpState === 'online' ? 'good' : (in_array($snmpState, ['error', 'partial', 'stale'], true) ? 'warn' : 'quiet'), $o['snmp_error'] ?? ''); ?></a></td>
            <td><?php if ($o['_vpn'] === null): ?><span class="dash-connection"><i class="fa fa-exchange"></i> Direct</span><?php else: ?><a href="olt.php?id=<?php echo (int)$o['id']; ?>#vpn"><?php echo $chip(isset($o['_vpn']['error']) ? 'VPN unavailable' : ($o['_vpn']['connected'] ? 'VPN connected' : 'VPN offline'), $o['_vpn']['connected'] ? 'good' : 'warn', $o['_vpn']['error'] ?? ''); ?></a><?php endif; ?></td>
            <td class="dash-muted"><?php echo $o['last_polled_at'] ? ago($o['last_polled_at']) : 'Never'; ?></td><td><a href="olt.php?id=<?php echo (int)$o['id']; ?>" class="dash-open" aria-label="<?php echo e('Open ' . $o['name']); ?>"><i class="fa fa-arrow-right"></i></a></td></tr><?php endforeach; ?>
        </tbody></table></div><?php if (count($fleet) > 8): ?><a class="dash-show-all" href="olts.php">View all <?php echo count($fleet); ?> OLTs <i class="fa fa-arrow-right"></i></a><?php endif; ?><?php endif; ?>
        <div class="dash-fleet-foot"><span><i class="fa fa-clock-o"></i> Reads older than 15 minutes are marked stale.</span><span>SNMP shows enabled OLTs · VPN shows configured tunnels</span></div>
    </section>

    <div class="dash-main-grid">
        <section class="dash-panel" aria-labelledby="outage-title">
            <div class="dash-panel-heading"><div><p class="dash-eyebrow">SERVICE HEALTH</p><h2 id="outage-title">PON &amp; signal watch</h2></div><a class="dash-text-link" href="events.php">All events <i class="fa fa-arrow-right"></i></a></div>
            <?php foreach ([['variations', 'Signal variations', 'signal'], ['partial_los', 'Partial LOS', 'chain-broken'], ['full_los', 'Full PON LOS', 'unlink'], ['power_fail', 'Power fail', 'plug'], ['na', 'Offline N/A', 'question-circle']] as [$key, $label, $icon]): $n = count($out[$key]); ?>
                <details class="dash-outage"><summary><span class="dash-outage-icon <?php echo $n ? 'amber' : 'dash-muted'; ?>"><i class="fa fa-<?php echo $icon; ?>"></i></span><b><?php echo $label; ?></b><span class="dash-outage-count<?php echo $n ? ' active' : ''; ?>"><?php echo $n; ?></span><i class="fa fa-angle-down"></i></summary><div class="dash-outage-body">
                    <?php if (!$n): ?><p>No matching alerts in the latest received data.</p><?php endif; ?>
                    <?php if ($key === 'variations'): foreach ($out[$key] as $u): ?><p><a href="onu.php?id=<?php echo (int)$u['id']; ?>"><?php echo e($u['description'] ?: $u['sn']); ?></a> · <?php echo e($u['olt_name']); ?>: <?php echo number_format((float)$u['rx_power'], 2); ?> dBm now, <?php echo number_format((float)$u['avg_rx'], 2); ?> dBm average</p><?php endforeach;
                    elseif ($key === 'na'): foreach ($out[$key] as $u): ?><p><a href="onu.php?id=<?php echo (int)$u['id']; ?>"><?php echo e($u['description'] ?: $u['sn']); ?></a> · <?php echo e($u['olt_name'] . ' ' . onu_place($u)); ?> · down <?php echo ago($u['last_down_at']); ?></p><?php endforeach;
                    else: foreach ($out[$key] as $p): $pon = "{$p['frame']}/{$p['slot']}/{$p['port']}"; ?><p><a href="onus.php?olt=<?php echo (int)$p['olt_id']; ?>&pon=<?php echo $pon; ?>"><?php echo e($p['olt_name']); ?> PON <?php echo $pon; ?></a>: <?php echo $key === 'power_fail' ? (int)$p['pf'] . ' power fail' : (int)$p['los'] . ' LOS'; ?> of <?php echo (int)$p['total']; ?> ONUs</p><?php endforeach; endif; ?>
                </div></details>
            <?php endforeach; ?>
            <?php if ($events): ?><div class="dash-alerts"><h3>Open alerts <span><?php echo (int)$eventCounts['total']; ?></span></h3><?php foreach ($events as $event): ?><a href="events.php" class="dash-alert"><i class="fa fa-exclamation-circle <?php echo $event['severity'] === 'critical' ? 'rose' : 'amber'; ?>"></i><span><b><?php echo e($event['title']); ?></b><small><?php echo e($event['olt_name'] ?: 'Network'); ?> · <?php echo ago($event['started_at']); ?></small></span><i class="fa fa-angle-right"></i></a><?php endforeach; ?></div><?php endif; ?>
        </section>
        <section class="dash-panel dash-device-focus" aria-labelledby="device-title">
            <div class="dash-panel-heading"><div><p class="dash-eyebrow">DEVICE FOCUS</p><h2 id="device-title">OLT overview</h2></div><?php if ($olts): ?><form method="GET"><label class="dash-sr-only" for="focusOlt">Select OLT</label><select id="focusOlt" name="olt" class="so-select" onchange="this.form.submit()"><?php echo olt_options($olts, $oltId); ?></select></form><?php endif; ?></div>
            <?php if (!$selected): ?><div class="dash-empty"><i class="fa fa-server"></i><strong>Select an OLT to inspect it</strong><span>Your device hardware and details will appear here.</span></div>
            <?php else: $boards = count(json_decode((string)$selected['boards_json'], true) ?: []); ?>
                <a href="olt.php?id=<?php echo $oltId; ?>" class="dash-rack"><?php echo olt_picture(max(8, min(21, $boards ?: 16))); ?></a>
                <div class="dash-device-title"><div><h3><?php echo e($selected['name']); ?></h3><p><?php echo e($selected['model'] ? 'Huawei ' . $selected['model'] : 'Model not read'); ?></p></div><?php echo $chip($selected['_read_state'] === 'online' ? 'Recently read' : 'Check connection', $selected['_read_state'] === 'online' ? 'good' : 'warn'); ?></div>
                <dl class="dash-device-stats"><div><dt>Uptime</dt><dd><?php echo e($selected['uptime'] ?: 'Not read'); ?></dd></div><div><dt>Temperature</dt><dd><?php echo $selected['temperature'] !== '' ? e($selected['temperature']) . '°C' : 'Not read'; ?></dd></div><div><dt>Boards found</dt><dd><?php echo $boards ?: 'Not read'; ?></dd></div></dl>
                <?php if ($selected['status'] === 'error'): ?><p class="dash-inline-error"><?php echo e($selected['last_error']); ?></p><?php endif; ?>
                <div class="dash-device-tools"><a href="olt.php?id=<?php echo $oltId; ?>"><i class="fa fa-server"></i> Open device</a><a href="diag.php?olt=<?php echo $oltId; ?>"><i class="fa fa-terminal"></i> Diagnostics</a><a href="olts.php?edit=<?php echo $oltId; ?>"><i class="fa fa-sliders"></i> Settings</a></div>
            <?php endif; ?>
        </section>
    </div>

    <div class="dash-main-grid dash-bottom-grid">
        <section class="dash-panel" aria-labelledby="auth-title"><div class="dash-panel-heading"><div><p class="dash-eyebrow">NETWORK GROWTH</p><h2 id="auth-title">New authorizations</h2></div><a class="dash-text-link" href="reports.php?r=auth">14 day report <i class="fa fa-arrow-right"></i></a></div><div class="dash-chart dash-chart-short"><canvas id="authChart" role="img" aria-label="ONU authorizations per day for the last 14 days"></canvas></div><p class="dash-chart-fallback" hidden>Charts could not load. <a href="reports.php?r=auth">View the authorization report</a>.</p></section>
        <section class="dash-panel" aria-labelledby="activity-title"><div class="dash-panel-heading"><div><p class="dash-eyebrow">CHANGE LOG</p><h2 id="activity-title">Recent activity</h2></div><a class="dash-text-link" href="info.php">View all <i class="fa fa-arrow-right"></i></a></div>
            <?php if (!$activity): ?><div class="dash-empty"><i class="fa fa-history"></i><strong>No changes recorded yet</strong><span>Authorizations and configuration changes appear here.</span></div><?php else: ?><ol class="dash-activity"><?php foreach ($activity as $a): ?><li><span class="dash-activity-dot <?php echo $a['ok'] ? 'good' : 'rose'; ?>"><i class="fa fa-<?php echo $a['ok'] ? 'check' : 'exclamation'; ?>"></i></span><div><p><?php echo e(action_text($a)); ?></p><time datetime="<?php echo e(AppTime::format('c', $a['created_at'])); ?>"><?php echo ago($a['created_at']); ?></time></div></li><?php endforeach; ?></ol><?php endif; ?>
        </section>
    </div>
</div>
<script type="application/json" id="dashboardData"><?php echo json_encode(['auth' => $mgr->authorizationsPerDay(14), 'net' => array_map(function ($r) { return ['t' => AppTime::format('H:i', $r['t']), 'on' => $r['online'], 'pf' => $r['power_fail'], 'los' => $r['los'], 'na' => max(0, $r['offline'] - $r['los'] - $r['power_fail'])]; }, $net)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script src="assets/dashboard.js?v=1"></script>
<?php include __DIR__ . '/footer.php'; ?>
