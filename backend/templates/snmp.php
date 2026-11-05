<?php
// Included by the authenticated OLT page; $olt is the public row.
$sample = $olt['snmp'] ?? [];
$interfaces = $sample['interfaces'] ?? [];
?>
<div class="so-inline" style="margin-bottom:14px">
    <?php echo btn('SNMP settings', 'blue', 'olts.php?edit=' . $id); ?>
    <?php if (!empty($olt['snmp_enabled'])) echo post_btn('snmp', ['id' => $id, 'tab' => 'uplink'], 'Test SNMP / refresh counters', 'blue', 'refresh', '', 'Reading SNMP...'); ?>
</div>
<?php if (empty($olt['snmp_enabled'])): ?>
    <p class="so-muted">Enable SNMP with the OLT's read-only community to read Ethernet interface status and traffic. Configure SNMPv2c and allow the monitoring source on the OLT first.</p>
<?php else: ?>
    <p>SNMP: <?php echo badge($olt['snmp_status'], $olt['snmp_status'] === 'online' ? 'green' : 'orange'); ?> · Last attempt: <?php echo e($olt['snmp_checked_at'] ?: 'Not checked yet'); ?></p>
    <?php if ($olt['snmp_error']) echo alert($olt['snmp_error'], 'err'); ?>
    <?php if ($sample): ?><p>Last successful data: <?php echo e($sample['sampled_at']); ?> · <?php echo e($sample['system']['name']); ?> · Uptime: <?php echo number_format($sample['system']['uptime_seconds'] / 3600, 1); ?> hours<br><small><?php echo e($sample['system']['description']); ?></small></p><?php endif; ?>
    <p class="so-muted">Standard IF-MIB Ethernet interfaces reported by the OLT. Rates need two successful reads and are averages between reads. Missing counters, resets and ambiguous 32-bit wraps appear as gaps. Automatic reads run every five minutes.</p>
    <?php if ($interfaces): ?>
    <div class="so-table-wrap"><table class="so-table"><thead><tr><th>Interface</th><th>Admin</th><th>Link</th><th>Capacity</th><th>Receive Mbps</th><th>Transmit Mbps</th><th>Counter</th></tr></thead><tbody>
    <?php foreach ($interfaces as $iface): ?><tr>
        <td><?php echo e($iface['name']); ?> <small>#<?php echo (int)$iface['index']; ?></small></td>
        <td><?php echo [1 => 'Up', 2 => 'Down', 3 => 'Testing'][(int)$iface['admin_status']] ?? 'Unknown'; ?></td>
        <td><?php echo [1 => 'Up', 2 => 'Down', 3 => 'Testing', 4 => 'Unknown', 5 => 'Dormant', 6 => 'Not present', 7 => 'Lower layer down'][(int)$iface['oper_status']] ?? 'Unknown'; ?></td>
        <td><?php echo $iface['speed_bps'] ? number_format($iface['speed_bps'] / 1000000) . ' Mbps' : 'Unknown'; ?></td>
        <td><?php echo isset($iface['rx_bps']) ? number_format($iface['rx_bps'] / 1000000, 3) : 'n/a'; ?></td>
        <td><?php echo isset($iface['tx_bps']) ? number_format($iface['tx_bps'] / 1000000, 3) : 'n/a'; ?></td>
        <td><?php echo (int)$iface['counter_bits']; ?> bit</td>
    </tr><?php endforeach; ?></tbody></table></div>
    <?php
    $history = [];
    foreach ($mgr->snmpHistory($id) as $point) $history[(int)$point['if_index']][] = $point;
    ?>
    <div class="so-half">
    <?php foreach ($interfaces as $iface): $points = $history[$iface['index']] ?? []; if (count($points) < 2) continue; ?>
        <div><?php echo panel_open($iface['name'] . ' · last 24 hours'); ?><canvas id="snmp_if_<?php echo (int)$iface['index']; ?>" height="120"></canvas><?php echo panel_close(); ?></div>
    <?php endforeach; ?>
    </div>
    <script>
    (function () {
        if (typeof Chart === 'undefined') return;
        var history = <?php echo json_encode($history, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        Object.keys(history).forEach(function (key) {
            var el = document.getElementById('snmp_if_' + key), points = history[key];
            if (!el) return;
            new Chart(el, {type:'line', data:{labels:points.map(function(p){return p.at;}), datasets:[
                {label:'Receive Mbps', borderColor:'#2ecc40', data:points.map(function(p){return p.rx_bps === null ? null : Number(p.rx_bps)/1000000;}), pointRadius:0},
                {label:'Transmit Mbps', borderColor:'#4e8cff', data:points.map(function(p){return p.tx_bps === null ? null : Number(p.tx_bps)/1000000;}), pointRadius:0}
            ]}, options:{responsive:true, spanGaps:false, scales:{y:{beginAtZero:true}, x:{ticks:{maxTicksLimit:6}}}}});
        });
    })();
    </script>
    <?php elseif ($olt['snmp_status'] === 'online'): ?><p>No Ethernet interfaces are exposed by this OLT's SNMP view.</p><?php endif; ?>
<?php endif; ?>
