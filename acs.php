<?php
require_once __DIR__ . '/backend/bootstrap.php';
require_once __DIR__ . '/backend/lib/AcsIntegration.php';
header('Cache-Control: no-store');
set_time_limit(120);
$acs = new AcsIntegration($mgr);
$onuId = (int)($_GET['onu_id'] ?? 0);
$onu = $onuId ? $mgr->onu($onuId) : null;
if ($onuId && !$onu) { http_response_code(404); exit('ONU not found.'); }
$url = 'acs.php' . ($onuId ? '?onu_id=' . $onuId : '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'settings' && !$onuId) {
            $acs->saveSettings(trim((string)($_POST['base_url'] ?? '')), trim((string)($_POST['api_key'] ?? '')), isset($_POST['enabled']));
            back($url, 'success', 'ACS connection saved. Use Test connection to verify it.');
        } elseif ($action === 'test' && !$onuId) {
            $acs->checkConnection();
            back($url, 'success', 'The ACS accepted this connection and API key.');
        } elseif ($action === 'read' && $onuId) {
            $acs->sync($onuId);
            $acs->refreshJobs($onuId);
            back($url, 'success', 'Readings and task status retrieved from the ACS. The device contact time shows when the device last reported.');
        } elseif (in_array($action, ['refresh', 'reboot', 'wifi'], true) && $onuId) {
            if (in_array($action, ['reboot', 'wifi'], true) && !isset($_POST['confirmed'])) throw new InvalidArgumentException('Confirm the device change before submitting.');
            $r = $acs->queue($onuId, $action, (string)($_POST['request_id'] ?? ''), (string)($_POST['ssid'] ?? ''), (string)($_POST['wifi_password'] ?? ''));
            $message = $r['repeated'] ? 'This request was already recorded; its current status is ' . $r['status'] . '. Nothing was sent again.'
                : 'Request #' . $r['id'] . ' queued in the ACS. It is not applied yet; the device must contact the ACS and complete the task.';
            back($url, 'success', $message);
        } else throw new InvalidArgumentException('Unknown ACS action.');
    } catch (Throwable $e) { back($url, 'error', $e->getMessage()); }
}
$settings = $acs->settings();
$cache = $onuId ? $acs->device($onuId) : null;
$snapshot = $cache['snapshot'] ?? [];
$pageTitle = 'TR069 & WiFi';
include __DIR__ . '/backend/templates/header.php';
echo page_title($onu ? 'TR069 · ' . $onu['sn'] : 'ACS connection',
    $onu ? 'Read the ONU through your ACS, request fresh readings, and queue device changes.' : 'Connect SmartOLT to your FreeISP ACS using its website URL and API key.',
    btn($onu ? 'Back to ONU' : 'Back to VPN & TR069', 'blue', $onu ? 'onu.php?id=' . $onuId : 'vpn.php'));
echo flash_messages();
?>
<?php if (!$onu): ?>
<?php echo panel_open('ACS connection', '', 'cloud'); ?>
<p>Status: <strong><?php echo e($settings['enabled'] ? $settings['status'] : 'Disabled'); ?></strong><?php if ($settings['checked_at']): ?> · Checked <?php echo e(AppTime::format('Y-m-d H:i:s', $settings['checked_at'])); ?><?php endif; ?></p>
<?php if ($settings['last_error']): ?><p class="so-muted"><?php echo e($settings['last_error']); ?></p><?php endif; ?>
<form method="post" class="so-form">
    <?php echo csrf_field(); ?><input type="hidden" name="action" value="settings">
    <div class="so-field"><label class="so-label" for="base_url">ACS website URL</label><input class="so-input" type="url" id="base_url" name="base_url" required maxlength="255" value="<?php echo e($settings['base_url']); ?>" placeholder="https://acs.example.com"><small>Use the site address, without /backend/api/rest. HTTPS certificate verification is required.</small></div>
    <div class="so-field"><label class="so-label" for="api_key">ACS API key</label><input class="so-input" type="password" id="api_key" name="api_key" autocomplete="new-password" maxlength="1024" <?php echo $settings['has_api_key'] ? '' : 'required'; ?>><small><?php echo $settings['has_api_key'] ? 'A key is saved. Leave blank to keep it at the same address.' : 'Create an API key in the ACS and paste it here.'; ?></small></div>
    <p><label><input type="checkbox" name="enabled" value="1" <?php echo $settings['enabled'] ? 'checked' : ''; ?>> Enable the ACS connection</label></p>
    <button class="so-btn so-btn-green" type="submit">Save connection</button>
</form>
<form method="post" style="margin-top:16px"><?php echo csrf_field(); ?><input type="hidden" name="action" value="test"><button class="so-btn so-btn-blue" type="submit" <?php echo $settings['enabled'] ? '' : 'disabled'; ?>>Test connection</button></form>
<p class="so-muted">Each ONU must already be registered in this ACS with the same serial number. Reading this page does not contact or configure a device. Device passwords are never included in SmartOLT's cached readings.</p>
<?php echo panel_close(); ?>
<?php else: ?>
<?php echo panel_open('ACS readings', btn('Connection settings', 'grey', 'acs.php'), 'cloud'); ?>
<?php if (!$settings['enabled']): ?><p>Connect the ACS in <a href="acs.php">connection settings</a> to use TR069 and WiFi controls.</p><?php endif; ?>
<form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="read"><button class="so-btn so-btn-blue" type="submit" <?php echo $settings['enabled'] ? '' : 'disabled'; ?>>Read ACS & task status</button></form>
<?php if (!$cache): ?><p>No matching ACS readings have been saved for this serial yet.</p><?php else: ?>
<p class="so-muted">Cached from the ACS <?php echo e(AppTime::format('Y-m-d H:i:s', $cache['checked_at'])); ?>. ACS device contact time: <?php echo e($snapshot['last_contact'] ?: 'Not reported'); ?> (ACS server time). Cached status may have changed since that contact.</p>
<?php if ($cache['last_error']): ?><p class="so-muted">Latest read failed: <?php echo e($cache['last_error']); ?>. Previous readings remain below.</p><?php endif; ?>
<dl class="so-dl">
<?php foreach (['serial' => 'Serial', 'manufacturer' => 'Manufacturer', 'model' => 'Model', 'status' => 'ACS device status', 'ip' => 'IP address', 'software' => 'Software', 'hardware' => 'Hardware', 'ssid' => 'WiFi name', 'clients' => 'Connected clients', 'uptime' => 'Uptime (seconds)'] as $key => $label): ?><dt><?php echo e($label); ?></dt><dd><?php echo e($snapshot[$key] === null || $snapshot[$key] === '' ? 'Not reported' : (string)$snapshot[$key]); ?></dd><?php endforeach; ?>
</dl>
<?php endif; ?>
<?php echo panel_close(); ?>
<div class="so-half"><div><?php echo panel_open('Device actions', '', 'refresh'); ?>
<p class="so-muted">These requests run when the ONU next contacts its ACS. A refresh requests new device readings; Read ACS retrieves the ACS's current copy.</p>
<form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="refresh"><input type="hidden" name="request_id" value="<?php echo bin2hex(random_bytes(16)); ?>"><button class="so-btn so-btn-blue" <?php echo $settings['enabled'] ? '' : 'disabled'; ?>>Request fresh device readings</button></form>
<form method="post" style="margin-top:24px"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reboot"><input type="hidden" name="request_id" value="<?php echo bin2hex(random_bytes(16)); ?>"><p><label><input type="checkbox" name="confirmed" required> Reboot this ONU and temporarily interrupt its service.</label></p><button class="so-btn so-btn-red" <?php echo $settings['enabled'] ? '' : 'disabled'; ?>>Queue device reboot</button></form>
<?php echo panel_close(); ?></div><div><?php echo panel_open('Change WiFi', '', 'wifi'); ?>
<p class="so-muted">Changes the primary WiFi network supported by the ACS device template. Other radios remain unchanged. The password is sent to the ACS over the configured connection and is not saved in SmartOLT.</p>
<form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="wifi"><input type="hidden" name="request_id" value="<?php echo bin2hex(random_bytes(16)); ?>">
<div class="so-field"><label class="so-label" for="ssid">WiFi name</label><input class="so-input" id="ssid" name="ssid" maxlength="32" required value="<?php echo e($snapshot['ssid'] ?? ''); ?>"></div>
<div class="so-field"><label class="so-label" for="wifi_password">New WiFi password</label><input class="so-input" type="password" id="wifi_password" name="wifi_password" autocomplete="new-password" minlength="8" maxlength="64" required></div>
<p><label><input type="checkbox" name="confirmed" required> Apply this WiFi change when the device next contacts the ACS. Existing WiFi clients may disconnect.</label></p>
<button class="so-btn so-btn-green" <?php echo $settings['enabled'] ? '' : 'disabled'; ?>>Queue WiFi change</button></form>
<?php echo panel_close(); ?></div></div>
<?php foreach (['wifi' => 'Reported WiFi details', 'lan' => 'Reported LAN ports'] as $kind => $title): echo panel_open($title, '', $kind === 'wifi' ? 'wifi' : 'sitemap'); ?>
<?php if (empty($snapshot[$kind])): ?><p class="so-muted">No supported <?php echo e($kind); ?> readings have been reported by this device.</p><?php else: ?>
<div class="so-table-wrap"><table class="so-table"><thead><tr><th>Reading</th><th>Value</th><th>Reported (ACS time)</th></tr></thead><tbody><?php foreach ($snapshot[$kind] as $reading): ?><tr><td style="overflow-wrap:anywhere"><?php echo e($reading['name']); ?></td><td><?php echo e($reading['value']); ?></td><td><?php echo e($reading['updated_at'] ?: 'Not reported'); ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php endif; echo panel_close(); endforeach; ?>
<?php echo panel_open('Connected hosts reported by ACS', '', 'desktop'); ?>
<?php if (empty($snapshot['hosts'])): ?><p class="so-muted">No host details reported.</p><?php else: ?>
<div class="so-table-wrap"><table class="so-table"><thead><tr><th>Hostname</th><th>IP</th><th>MAC</th><th>State</th><th>Last seen (ACS time)</th></tr></thead><tbody><?php foreach ($snapshot['hosts'] as $host): ?><tr><td><?php echo e($host['hostname']); ?></td><td><?php echo e($host['ip']); ?></td><td><?php echo e($host['mac']); ?></td><td><?php echo $host['active'] ? 'Active at last report' : 'Inactive at last report'; ?></td><td><?php echo e($host['last_seen']); ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php endif; echo panel_close(); ?>
<?php echo panel_open('ACS request history', '', 'history'); $jobs = $acs->jobs($onuId); ?>
<p class="so-muted">Queued means waiting for the device. Completed means the ACS reports every task completed; read the latest device values to verify the result. Unknown means delivery or execution could not be confirmed, so check the ACS before repeating.</p>
<?php if (!$jobs): ?><p class="so-muted">No ACS requests yet.</p><?php else: ?><div class="so-table-wrap"><table class="so-table"><thead><tr><th>Request</th><th>Operation</th><th>State</th><th>Last checked</th><th>Details</th></tr></thead><tbody><?php foreach ($jobs as $job): ?><tr><td>#<?php echo (int)$job['id']; ?></td><td><?php echo e(ucfirst($job['operation'])); ?></td><td><?php echo e(ucfirst($job['status'])); ?></td><td><?php echo e(AppTime::format('Y-m-d H:i:s', $job['updated_at'])); ?></td><td><?php echo e($job['last_error']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; echo panel_close(); ?>
<?php endif; ?>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
