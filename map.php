<?php
require_once __DIR__ . '/backend/bootstrap.php';

$layer = ($_GET['layer'] ?? '') === 'odb' ? 'odb' : 'onu';
$zoneId = (int)($_GET['zone'] ?? 0);
$all = $mgr->listOnus(['zone_id' => $zoneId]);
$points = [];
if ($layer === 'onu') {
    foreach ($all as $u) {
        if ($u['lat'] === null || $u['lng'] === null) continue;
        $points[] = ['id' => (int)$u['id'], 'name' => $u['description'], 'sn' => $u['sn'], 'lat' => (float)$u['lat'], 'lng' => (float)$u['lng'],
            'group' => $u['status_group'], 'status' => status_label($u['status_group']), 'rx' => $u['rx_power'] === null ? null : (float)$u['rx_power'],
            'odb' => $u['odb_name'], 'address' => $u['address']];
    }
}
$boxes = [];
$odbCount = 0;
foreach ($mgr->odbs() as $d) {
    if ($zoneId && (int)$d['zone_id'] !== $zoneId) continue;
    $odbCount++;
    if ($d['lat'] === null) continue;
    $boxes[] = ['id' => (int)$d['id'], 'name' => $d['name'], 'lat' => (float)$d['lat'], 'lng' => (float)$d['lng'], 'used' => (int)$d['onu_count'], 'ports' => (int)$d['ports']];
}

$pageTitle = $layer === 'odb' ? 'Splitters map' : 'ONUs map';
include __DIR__ . '/backend/templates/header.php';
?>
<link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
<script src="assets/vendor/leaflet/leaflet.js"></script>
<?php echo page_title($pageTitle, $layer === 'odb'
    ? count($boxes) . ' of ' . $odbCount . ' splitters have a location. Set it under Settings, Splitters.'
    : count($points) . ' of ' . count($all) . ' ONUs have a location. Set it on the ONU page, or with Use GPS when you authorize.'); ?>
<form method="GET" class="so-inline" style="margin-bottom:12px">
    <?php if ($layer === 'odb'): ?><input type="hidden" name="layer" value="odb"><?php endif; ?>
    <label class="so-label">Zone</label>
    <select name="zone" class="so-select" onchange="this.form.submit()"><?php echo options($mgr->zones(), $zoneId ?: '', 'Any'); ?></select>
    <span class="so-inline so-muted" style="font-size:13px">
        <?php if ($layer === 'onu'): ?>
            <span><i class="fa fa-circle st-online"></i> Online</span><span><i class="fa fa-circle st-power_fail"></i> Power fail</span>
            <span><i class="fa fa-circle st-los"></i> LOS or offline</span><span><i class="fa fa-square" style="color:#f5a623"></i> Splitter</span>
        <?php else: ?>
            <span><i class="fa fa-square" style="color:#2ecc40"></i> Free ports</span><span><i class="fa fa-square" style="color:#f5a623"></i> 1 or 2 free</span><span><i class="fa fa-square" style="color:#ff4136"></i> Full</span>
        <?php endif; ?>
    </span>
</form>
<div id="map" style="height:72vh;min-height:420px;border-radius:6px;border:1px solid var(--border)"></div>
<script>
(function () {
    var onus = <?php echo json_encode($points, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var boxes = <?php echo json_encode($boxes, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var layer = <?php echo json_encode($layer); ?>;
    var map = L.map('map');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
    var colour = { online: '#2ecc40', power_fail: '#4e8cff', disabled: '#9aa5b8' };
    var bounds = [];
    onus.forEach(function (u) {
        L.circleMarker([u.lat, u.lng], { radius: 7, color: '#ffffff', weight: 2, fillColor: colour[u.group] || '#ff4136', fillOpacity: 0.95 }).addTo(map)
            .bindPopup('<b><a href="onu.php?id=' + u.id + '">' + esc(u.name) + '</a></b><br>' + esc(u.sn) + '<br>' + esc(u.status)
                + (u.rx !== null ? '<br>Signal ' + u.rx.toFixed(2) + ' dBm' : '') + (u.odb ? '<br>Splitter ' + esc(u.odb) : '') + (u.address ? '<br>' + esc(u.address) : ''));
        bounds.push([u.lat, u.lng]);
    });
    boxes.forEach(function (b) {
        var free = b.ports - b.used, c = layer === 'odb' ? (free <= 0 ? '#ff4136' : (free <= 2 ? '#f5a623' : '#2ecc40')) : '#f5a623';
        L.marker([b.lat, b.lng], { icon: L.divIcon({ className: '', html: '<div style="width:14px;height:14px;background:' + c + ';border:2px solid #fff;border-radius:3px"></div>', iconSize: [14, 14] }) }).addTo(map)
            .bindPopup('<b><a href="onus.php?odb=' + b.id + '">' + esc(b.name) + '</a></b><br>' + b.used + ' of ' + b.ports + ' ports used');
        bounds.push([b.lat, b.lng]);
    });
    if (bounds.length) map.fitBounds(bounds, { padding: [30, 30], maxZoom: 17 }); else map.setView([0, 20], 3);
})();
</script>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
