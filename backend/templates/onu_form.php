<?php
// The ONU form shared by Authorize ONU and Add ONU for later authorization, laid out like the
// SmartOLT authorization page. Expects: $mgr, $form (later|authorize), $olts, $sel (selected values),
// and for authorize: $olt (public row), $found (the waiting ONU).
$typeRows = $mgr->onuTypes();
$presets = $mgr->presets();
$prof = $form === 'authorize' ? $olt['profiles'] : ['line' => [], 'srv' => [], 'vlans' => []];
$vlanList = [];
foreach ($olts as $o) foreach ((json_decode((string)$o['profiles_json'], true)['vlans'] ?? []) as $v) if ((int)$v['id'] > 1) $vlanList[(int)$v['id']] = true;
ksort($vlanList);
$v = function (string $k, $default = '') use ($sel) { return $sel[$k] ?? $default; };
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
<?php if ($presets): ?>
<div class="so-row"><label>Preset</label><select id="preset" name="preset_id" class="so-select"><option value="">None</option>
    <?php foreach ($presets as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo (string)$v('preset_id') === (string)$p['id'] ? 'selected' : ''; ?>><?php echo e($p['name']); ?></option><?php endforeach; ?></select></div>
<?php endif; ?>
<?php if ($form === 'later'): ?>
<div class="so-row"><label>OLT</label><select name="olt_id" class="so-select"><option value="">Any OLT</option><?php echo olt_options($olts, $v('olt_id')); ?></select></div>
<?php else: ?>
<div class="so-row"><label>OLT</label><div><?php echo (int)$olt['id'] . ' - ' . e($olt['name']); ?></div></div>
<?php endif; ?>
<div class="so-row"><label>PON type</label><div class="so-inline" style="font-size:14px"><label class="so-check"><input type="radio" checked> GPON</label><label class="so-check so-muted"><input type="radio" disabled> EPON</label></div></div>
<div class="so-row"><label>GPON channel</label><div class="so-inline" style="font-size:14px"><label class="so-check"><input type="radio" checked> GPON</label><label class="so-check so-muted"><input type="radio" disabled> XG-PON</label><label class="so-check so-muted"><input type="radio" disabled> XGS-PON</label></div></div>
<?php if ($form === 'later'): ?>
<div class="so-row"><label>Board</label><input name="slot" type="number" min="0" max="21" class="so-input" placeholder="Board (optional)" value="<?php echo e($v('slot')); ?>"></div>
<div class="so-row"><label>Port</label><input name="port" type="number" min="0" max="15" class="so-input" placeholder="Port (optional)" value="<?php echo e($v('port')); ?>"></div>
<div class="so-row"><label>SN</label><input name="sn" required maxlength="20" class="so-input so-mono" value="<?php echo e($v('sn')); ?>" placeholder="For example HWTC1A2B3C4D"></div>
<?php else: ?>
<div class="so-row"><label>Board</label><div><?php echo (int)$found['slot']; ?></div></div>
<div class="so-row"><label>Port</label><div><?php echo (int)$found['port']; ?></div></div>
<div class="so-row"><label>SN</label><div class="so-mono"><?php echo e($found['sn']); ?> <span class="so-muted"><?php echo e(trim(($found['equipment'] ?? '') . ' ' . ($found['vendor'] ?? ''))); ?></span></div></div>
<?php endif; ?>
<div class="so-row"><label>ONU type</label><select name="onu_type_id" data-k="onu_type_id" class="so-select"><?php echo options($typeRows, $v('onu_type_id'), $form === 'later' ? 'From the model the OLT reports' : 'Not set'); ?></select></div>
<?php if ($form === 'authorize'): ?>
<div class="so-row"><label>Line-profile</label><select name="line_profile" data-k="line_profile" class="so-select" required>
    <?php foreach ($prof['line'] as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo (string)$p['id'] === (string)$v('line_profile') ? 'selected' : ''; ?>><?php echo (int)$p['id'] . ': ' . e($p['name']); ?></option><?php endforeach; ?></select></div>
<div class="so-row"><label>Service-profile</label><select name="srv_profile" data-k="srv_profile" class="so-select" required>
    <?php foreach ($prof['srv'] as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo (string)$p['id'] === (string)$v('srv_profile') ? 'selected' : ''; ?>><?php echo (int)$p['id'] . ': ' . e($p['name']); ?></option><?php endforeach; ?></select></div>
<?php else: ?>
<div class="so-row"><label>Line-profile</label><select class="so-select" disabled><option>Auto (OLT setting)</option></select></div>
<div class="so-row"><label>Service-profile</label><select class="so-select" disabled><option>Auto (OLT setting)</option></select></div>
<?php endif; ?>
<div class="so-row"><label>ONU mode</label><div class="so-inline" style="font-size:14px">
    <label class="so-check"><input type="radio" name="mode" value="router" <?php echo $v('mode', 'router') === 'router' ? 'checked' : ''; ?>> Routing</label>
    <label class="so-check"><input type="radio" name="mode" value="bridge" <?php echo $v('mode', 'router') === 'bridge' ? 'checked' : ''; ?>> Bridging</label></div></div>
<div class="so-row"><label>User VLAN-ID</label><input name="vlan" data-k="vlan" type="number" min="1" max="4094" list="vlanList" class="so-input" value="<?php echo e($v('vlan')); ?>" placeholder="Empty authorizes without internet">
    <datalist id="vlanList"><?php foreach (array_keys($vlanList) as $id): ?><option value="<?php echo $id; ?>"><?php endforeach; ?></datalist></div>
<div class="so-row"><label>Zone</label><select name="zone_id" data-k="zone_id" class="so-select"><?php echo options($mgr->zones(), $v('zone_id'), 'None'); ?></select></div>
<div class="so-row"><label>Sector</label><select name="sector_id" data-k="sector_id" class="so-select"><?php echo options($mgr->sectors(), $v('sector_id'), 'None'); ?></select></div>
<div class="so-row"><label>Splitter</label><select name="odb_id" class="so-select"><?php echo options($mgr->odbs(), $v('odb_id'), 'None'); ?></select></div>
<div class="so-row"><label>Splitter port</label><input name="odb_port" type="number" min="1" max="64" class="so-input" value="<?php echo e($v('odb_port')); ?>" placeholder="None"></div>
<div class="so-row"><label>Speed profile</label><select name="speed_profile_id" data-k="speed_profile_id" class="so-select"><?php echo options($mgr->speedProfiles(), $v('speed_profile_id'), 'Unlimited'); ?></select></div>
<div class="so-row"><label>Name</label><input name="description" maxlength="60" class="so-input" value="<?php echo e($v('description')); ?>"></div>
<div class="so-row"><label>Address or comment</label><input name="address" maxlength="255" class="so-input" value="<?php echo e($v('address')); ?>" placeholder="Address or comment (optional)"></div>
<div class="so-row"><label>ONU external ID</label><input name="customer_ref" maxlength="100" class="so-input" value="<?php echo e($v('customer_ref')); ?>" placeholder="Use the unique ONU external ID with API or billing systems"></div>
<?php if ($form === 'authorize'): ?>
<details style="margin:0 0 12px 222px"><summary class="so-muted" style="cursor:pointer;font-size:14px">Advanced</summary>
    <div class="so-row" style="margin-top:10px"><label>GEM port</label><input name="gemport" data-k="gemport" type="number" min="0" max="1023" value="1" class="so-input"></div></details>
<?php endif; ?>
<div class="so-row"><label></label><label class="so-check"><input type="checkbox" id="useGps" <?php echo $v('lat') !== '' ? 'checked' : ''; ?>> Use GPS</label></div>
<div id="gpsBlock" class="<?php echo $v('lat') !== '' ? '' : 'hidden'; ?>">
    <div class="so-row"><label>Latitude</label><input name="lat" class="so-input" value="<?php echo e($v('lat')); ?>"></div>
    <div class="so-row"><label>Longitude</label><input name="lng" class="so-input" value="<?php echo e($v('lng')); ?>"></div>
    <div class="so-row"><label></label><div><button type="button" id="hereBtn" class="so-btn so-btn-green so-btn-sm">Get current location</button><div id="miniMap" class="so-mini-map"></div></div></div>
</div>
<script>
(function () {
    var presets = <?php echo json_encode(array_column($presets, null, 'id')); ?>;
    var sel = document.getElementById('preset');
    if (sel) sel.addEventListener('change', function () {
        var p = presets[sel.value];
        if (!p) return;
        document.querySelectorAll('[data-k]').forEach(function (el) { var x = p[el.getAttribute('data-k')]; if (x !== null && x !== undefined) el.value = x; });
        document.querySelectorAll('input[name=mode]').forEach(function (r) { r.checked = r.value === p.mode; });
    });
    var lat = document.querySelector('input[name=lat]'), lng = document.querySelector('input[name=lng]'), map = null, pin = null;
    function draw() {
        if (!window.L) return;
        var la = parseFloat(lat.value), lo = parseFloat(lng.value);
        if (!map) { map = L.map('miniMap'); L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
            map.on('click', function (e) { lat.value = e.latlng.lat.toFixed(7); lng.value = e.latlng.lng.toFixed(7); draw(); }); }
        if (isNaN(la) || isNaN(lo)) { map.setView([0, 20], 2); return; }
        if (pin) pin.setLatLng([la, lo]); else pin = L.marker([la, lo]).addTo(map);
        map.setView([la, lo], 16);
    }
    document.getElementById('useGps').addEventListener('change', function () {
        document.getElementById('gpsBlock').classList.toggle('hidden', !this.checked);
        if (!this.checked) { lat.value = ''; lng.value = ''; } else setTimeout(draw, 50);
    });
    if (!document.getElementById('gpsBlock').classList.contains('hidden')) setTimeout(draw, 50);
    lat.addEventListener('change', draw); lng.addEventListener('change', draw);
    document.getElementById('hereBtn').addEventListener('click', function () {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(function (p) { lat.value = p.coords.latitude.toFixed(7); lng.value = p.coords.longitude.toFixed(7); draw(); });
    });
})();
</script>
