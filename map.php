<?php
require_once __DIR__ . '/backend/bootstrap.php';

$zoneId = (int)($_GET['zone'] ?? 0);
$points = [];
foreach ($mgr->listOnus(['zone_id' => $zoneId]) as $u) {
    if ($u['lat'] === null || $u['lng'] === null) continue;
    $points[] = ['id' => (int)$u['id'], 'name' => $u['description'], 'sn' => $u['sn'], 'lat' => (float)$u['lat'], 'lng' => (float)$u['lng'],
        'group' => $u['status_group'], 'status' => status_label($u['status_group']), 'rx' => $u['rx_power'] === null ? null : (float)$u['rx_power'],
        'odb' => $u['odb_name'], 'address' => $u['address']];
}
$boxes = [];
foreach ($mgr->odbs() as $d) {
    if ($d['lat'] === null || ($zoneId && (int)$d['zone_id'] !== $zoneId)) continue;
    $boxes[] = ['name' => $d['name'], 'lat' => (float)$d['lat'], 'lng' => (float)$d['lng'], 'used' => (int)$d['onu_count'], 'ports' => (int)$d['ports']];
}
$total = count($mgr->listOnus(['zone_id' => $zoneId]));

$pageTitle = 'Map';
include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <?php echo page_heading('Map', count($points) . ' of ' . $total . ' ONUs have a location. Set it on an ONU\'s page under Location and details.'); ?>
        <form method="GET" class="mb-4 flex flex-wrap gap-3 items-center">
            <select name="zone" class="<?php echo UI_INPUT; ?> w-auto" onchange="this.form.submit()"><?php echo options($mgr->zones(), $zoneId ?: '', 'All zones'); ?></select>
            <span class="flex items-center gap-4 text-xs text-slate-500 dark:text-slate-400">
                <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-emerald-500"></span>Online</span>
                <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-amber-500"></span>Power fail</span>
                <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-rose-500"></span>Signal loss or offline</span>
                <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-sm bg-brand-600"></span>ODB</span>
            </span>
        </form>
        <div id="map" class="w-full rounded-2xl border border-slate-200 dark:border-slate-700 shadow-soft" style="height: 70vh; min-height: 420px;"></div>
    </main>
    <?php include __DIR__ . '/backend/templates/footer.php'; ?>
<script>
(function () {
    var onus = <?php echo json_encode($points, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var boxes = <?php echo json_encode($boxes, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var map = L.map('map');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
    var colour = { online: '#10b981', power_fail: '#f59e0b', disabled: '#94a3b8' };
    var bounds = [];
    onus.forEach(function (u) {
        L.circleMarker([u.lat, u.lng], { radius: 7, color: '#ffffff', weight: 2, fillColor: colour[u.group] || '#ef4444', fillOpacity: 0.95 }).addTo(map)
            .bindPopup('<b><a href="onu.php?id=' + u.id + '">' + esc(u.name) + '</a></b><br>' + esc(u.sn) + '<br>' + esc(u.status)
                + (u.rx !== null ? '<br>Signal ' + u.rx.toFixed(2) + ' dBm' : '') + (u.odb ? '<br>ODB ' + esc(u.odb) : '') + (u.address ? '<br>' + esc(u.address) : ''));
        bounds.push([u.lat, u.lng]);
    });
    boxes.forEach(function (b) {
        L.marker([b.lat, b.lng], { icon: L.divIcon({ className: '', html: '<div style="width:14px;height:14px;background:#2547e0;border:2px solid #fff;border-radius:3px"></div>', iconSize: [14, 14] }) }).addTo(map)
            .bindPopup('<b>' + esc(b.name) + '</b><br>' + b.used + ' of ' + b.ports + ' ports used');
        bounds.push([b.lat, b.lng]);
    });
    if (bounds.length) map.fitBounds(bounds, { padding: [30, 30], maxZoom: 17 }); else map.setView([0, 20], 3);
})();
</script>
