<?php
// Loopback-only ACS REST fixture. Run as the router for a dedicated PHP development server.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit; }
header('Content-Type: application/json');
$statePath = sys_get_temp_dir() . '/smartolt-acs-fixture-' . (int)$_SERVER['SERVER_PORT'] . '.json';
$state = is_file($statePath) ? json_decode(file_get_contents($statePath), true) : null;
$state = $state ?: ['mode' => 'normal', 'task_status' => 'pending', 'next' => 100, 'tasks' => [], 'submitted' => 0, 'serial' => 'HWTC4D2F5A9A'];
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
function fixtureReply(array $body, int $code = 200): never { http_response_code($code); echo json_encode($body); exit; }
if ($path === '/__control') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!empty($body['reset'])) $state = ['mode' => 'normal', 'task_status' => 'pending', 'next' => 100, 'tasks' => [], 'submitted' => 0, 'serial' => 'HWTC4D2F5A9A'];
        foreach (['mode', 'task_status', 'serial'] as $key) if (isset($body[$key])) $state[$key] = $body[$key];
        file_put_contents($statePath, json_encode($state));
    }
    fixtureReply(['success' => true] + $state);
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer fixture-acs-key' || $state['mode'] === 'rejectkey') fixtureReply(['error' => 'secret-reflection-must-not-appear'], 401);
if ($state['mode'] === 'redirect') { header('Location: http://127.0.0.1:1/steal'); fixtureReply(['error' => 'redirect'], 302); }
if ($state['mode'] === 'malformed') { echo 'not json secret-reflection-must-not-appear'; exit; }
$file = basename($path);
if ($file === 'devices.php') {
    $serial = $_GET['serial'] ?? '';
    $fixtureSerial = strtoupper($state['serial']);
    $vendor = substr($fixtureSerial, 0, 4);
    $suffix = substr($fixtureSerial, 4);
    $aliases = [$fixtureSerial, $vendor . '-' . $suffix, strtoupper(bin2hex($vendor)) . $suffix];
    if (!in_array($serial, $aliases, true) || $state['mode'] === 'missing') fixtureReply(['error' => 'Device not found'], 404);
    $id = $state['mode'] === 'ambiguous' && $serial === $vendor . '-' . $suffix ? 42 : 41;
    $reported = $state['mode'] === 'mismatch' ? 'HWTCFFFFFFFF' : $state['serial'];
    if ($state['mode'] === 'malformeddevice') $reported = ['unexpected' => 'serial'];
    fixtureReply(['success' => true, 'device' => ['id' => $id, 'serialNumber' => $reported, 'manufacturer' => 'Huawei', 'model' => 'HG8245H',
        'status' => 'online', 'lastContact' => '2026-11-05 10:00:00', 'ipAddress' => '192.168.5.2', 'softwareVersion' => 'fixture-1', 'hardwareVersion' => 'A',
        'ssid' => 'Fixture WiFi', 'ssidPassword' => 'device-wifi-secret', 'tr069Password' => 'device-tr069-secret', 'localAdminPassword' => 'device-admin-secret',
        'connectedClients' => 2, 'uptime' => 3600, 'connectedHosts' => [['ipAddress' => '192.168.1.2', 'macAddress' => '00:11:22:33:44:55', 'hostname' => 'laptop', 'isActive' => true]],
        'parameters' => [
            ['name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID', 'value' => 'Fixture WiFi', 'updatedAt' => '2026-11-05 10:00:00'],
            ['name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDevice.1.RSSI', 'value' => '-62'],
            ['name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase', 'value' => 'parameter-wifi-secret'],
            ['name' => 'InternetGatewayDevice.ManagementServer.ConnectionRequestPassword', 'value' => 'parameter-acs-secret'],
            ['name' => 'Device.Ethernet.Interface.1.Status', 'value' => 'Up'],
        ]]]);
}
if ($file === 'parameters.php' && ($_GET['action'] ?? '') === 'device_action_capabilities') fixtureReply(['success' => true, 'capabilities' => ['refresh' => true, 'reboot' => true]]);
if ($file === 'provision.php' && ($_GET['action'] ?? '') === 'capabilities') fixtureReply(['success' => true, 'api_version' => 1]);
if ($file === 'tasks.php') {
    if ($state['mode'] === 'malformedtasks') fixtureReply(['success' => true, 'tasks' => 'invalid']);
    $tasks = [];
    foreach ($state['tasks'] as $t) { $t['status'] = $state['task_status']; $tasks[] = $t; }
    fixtureReply(['success' => true, 'tasks' => $tasks]);
}
if ($file === 'provision.php' && ($body['action'] ?? '') === 'preview') {
    fixtureReply(['success' => true, 'tasks' => [['task_type' => $state['mode'] === 'badpreview' ? 'reboot' : 'wifi', 'task_data' => $body['config']['wifi'] ?? []]]]);
}
if (($file === 'parameters.php' && ($body['action'] ?? '') === 'device_action') || ($file === 'provision.php' && ($body['action'] ?? '') === 'provision')) {
    if ((int)($body['device_id'] ?? 0) !== 41) fixtureReply(['error' => 'wrong device'], 400);
    $id = $state['next']++;
    $state['submitted']++;
    $state['tasks'][] = ['id' => $id, 'device_id' => 41, 'status' => 'pending', 'task_data' => ['password' => 'task-payload-secret'], 'message' => 'task-message-secret'];
    file_put_contents($statePath, json_encode($state));
    if ($state['mode'] === 'uncertain') fixtureReply(['error' => 'Accepted then response failed secret-reflection-must-not-appear'], 500);
    if ($state['mode'] === 'malformedtaskids') fixtureReply(['success' => true, 'task_ids' => [['invalid']]], 202);
    fixtureReply(['success' => true, 'task_ids' => [$id], 'job_id' => $file === 'provision.php' ? 71 : null], 202);
}
fixtureReply(['error' => 'Not found'], 404);
