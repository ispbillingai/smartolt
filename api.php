<?php
/**
 * SmartOLT API (runs at {sub}.<smartolt domain>/api.php)
 * --------------------------------------------------------------------------
 * The billing dashboard (system/controllers/smartolt.php in radius) calls this with a JSON body
 * {"action": ...} and "Authorization: Bearer <token>", where
 *     token = hash_hmac('sha256', <subdomain>, <fleet secret>)
 * The fleet secret is written into backend/config/database.php by install.sh and is never in git.
 **/

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
set_time_limit(180);

require_once __DIR__ . '/backend/lib/OltManager.php';

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(string $error, int $code = 400): void
{
    out(['ok' => false, 'error' => $error], $code);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    fail('Use GET for read operations or POST with a JSON body.', 405);
}
if ($method === 'POST') {
    $body = (string)file_get_contents('php://input');
    $decoded = json_decode($body);
    if (!$decoded instanceof stdClass) fail('The request body must be a JSON object.', 400);
    $in = json_decode($body, true);
} else {
    $in = $_GET;
}

try {
    $mgr = new OltManager();
} catch (Throwable $e) {
    fail('Database not configured on this SmartOLT site.', 500);
}
$mgr->source = 'api';
$mgr->ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

$given = '';
if (preg_match('/^(?i:Bearer)\s+([0-9a-f]{64})$/', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) {
    $given = $m[1];
} elseif ($method === 'POST' && isset($in['token']) && is_string($in['token'])) {
    $given = (string)$in['token'];
}
if ($given === '' || !hash_equals($mgr->apiToken(), $given)) {
    fail('Unauthorized', 401);
}
if (!isset($in['action']) || !is_string($in['action']) || $in['action'] === '') fail('Provide an action name.', 400);
if (isset($in['id']) && (!is_int($in['id']) && !(is_string($in['id']) && ctype_digit($in['id'])))) fail('The ID must be an integer.', 400);

// URL query strings are commonly logged. Tokens are accepted only in the header or JSON POST.
// Read endpoints remain available by GET; any device/configuration mutation requires POST.
$readActions = ['ping', 'olt.list', 'olt.get', 'olt.vpn', 'onu.list', 'onu.get', 'onu.unconfigured', 'stats', 'catalogs.list', 'actions.list', 'onu.history'];
if ($method === 'GET' && !in_array((string)($in['action'] ?? ''), $readActions, true)) {
    header('Allow: POST');
    fail('This operation requires POST with a JSON body.', 405);
}

$id = (int)($in['id'] ?? 0);
try {
    switch ((string)($in['action'] ?? '')) {
        case 'ping':
            out(['ok' => true]);

        case 'olt.list':
            out(['ok' => true, 'olts' => array_map(['OltManager', 'publicOlt'], $mgr->olts())]);

        case 'olt.get':
            $olt = $mgr->olt($id);
            if (!$olt) fail('OLT not found.', 404);
            out(['ok' => true, 'olt' => OltManager::publicOlt($olt)]);

        case 'olt.save':
            out(['ok' => true, 'id' => $mgr->saveOlt($in)]);

        case 'olt.vpn':
            // Everything the billing dashboard needs to set the ISP's MikroTik up for this OLT itself.
            $olt = $mgr->olt($id);
            if (!$olt) fail('OLT not found.', 404);
            if ($olt['conn_type'] !== 'vpn') fail('This OLT is reached directly, not through the SmartOLT VPN.', 400);
            out(['ok' => true, 'status' => $mgr->vpnStatus($olt), 'server' => $mgr->vpnServer(), 'port' => 1194,
                'interface' => 'smartolt-olt' . (int)$olt['id'], 'user' => $olt['vpn_user'], 'password' => $mgr->decrypt($olt['vpn_pass_enc']),
                'tunnel_ip' => $olt['vpn_ip'], 'lan_ip' => $olt['lan_ip'], 'lan_port' => (int)$olt['lan_port'], 'nat_port' => OltManager::VPN_NAT_PORT,
                'script' => $mgr->mikrotikScript($olt)]);

        case 'olt.delete':
            $mgr->deleteOlt($id);
            out(['ok' => true]);

        case 'olt.refresh':
            $olt = $mgr->olt($id);
            if (!$olt) fail('OLT not found.', 404);
            try {
                $summary = $mgr->refresh($olt);
                $mgr->log($id, 'olt.refresh', $olt['name'], true, 'ok');
            } catch (Throwable $e) {
                $mgr->log($id, 'olt.refresh', $olt['name'], false, $e->getMessage());
                throw $e;
            }
            out(['ok' => true, 'summary' => $summary, 'olt' => OltManager::publicOlt($mgr->olt($id))]);

        case 'olt.snmp':
            $olt = $mgr->olt($id);
            if (!$olt) fail('OLT not found.', 404);
            $snmp = $mgr->pollSnmp($olt);
            $ok = in_array($snmp['status'] ?? '', ['online', 'partial'], true);
            $code = $ok ? 200 : (($snmp['status'] ?? '') === 'disabled' ? 400 : (($snmp['status'] ?? '') === 'busy' ? 409 : 502));
            out(['ok' => $ok, 'snmp' => $snmp, 'olt' => OltManager::publicOlt($mgr->olt($id))]
                + ($ok ? [] : ['error' => $snmp['error'] ?? 'SNMP could not be read.']), $code);

        case 'onu.unconfigured':
            $oltId = (int)($in['olt_id'] ?? 0);
            $onus = $mgr->autofind($oltId);
            out(['ok' => true, 'onus' => $onus, 'olt' => OltManager::publicOlt($mgr->olt($oltId))]);

        case 'onu.list':
            out(['ok' => true, 'onus' => $mgr->listOnus($in)]);

        case 'onu.authorize':
            out(['ok' => true] + $mgr->authorize($in));

        case 'onu.get':
            [$onu, $detail] = $mgr->liveOnu($id);
            out(['ok' => true, 'onu' => $onu, 'detail' => $detail, 'olt_name' => $onu['olt_name']]);

        case 'onu.reboot':
            $mgr->reboot($id);
            out(['ok' => true]);

        case 'onu.delete':
            out(['ok' => true, 'service_ports_removed' => $mgr->deleteOnu($id)]);

        case 'onu.describe':
            out(['ok' => true, 'description' => $mgr->describe($id, (string)($in['description'] ?? ''), isset($in['customer_ref']) ? (string)$in['customer_ref'] : null)]);

        case 'onu.meta':
            $mgr->saveMeta($id, $in);
            out(['ok' => true]);

        case 'onu.service':
            $mgr->setService($id, $in);
            out(['ok' => true, 'onu' => $mgr->onu($id)]);

        case 'onu.enable':
        case 'onu.disable':
            $mgr->setActive($id, $in['action'] === 'onu.enable');
            out(['ok' => true]);

        case 'onu.replace_sn':
            out(['ok' => true, 'sn' => $mgr->replaceSn($id, (string)($in['sn'] ?? ''))]);

        case 'onu.bulk':
            $bulk = $mgr->bulk((array)($in['ids'] ?? []), (string)($in['bulk_action'] ?? ''), (int)($in['speed_profile_id'] ?? 0));
            out(['ok' => !$bulk['failed']] + $bulk, $bulk['failed'] ? ($bulk['done'] ? 207 : 502) : 200);

        case 'onu.history':
            out(['ok' => true, 'signal' => $mgr->signalHistory($id, min(30, max(1, (int)($in['days'] ?? 7)))), 'events' => $mgr->statusEvents($id)]);

        case 'stats':
            out(['ok' => true, 'counts' => $mgr->counts((int)($in['olt_id'] ?? 0)), 'network' => $mgr->networkStatus(24, (int)($in['olt_id'] ?? 0))]);

        case 'catalogs.list':
            out(['ok' => true, 'zones' => $mgr->zones(), 'odbs' => $mgr->odbs(), 'onu_types' => $mgr->onuTypes(), 'speed_profiles' => $mgr->speedProfiles()]);

        case 'actions.list':
            out(['ok' => true, 'actions' => $mgr->actions(200)]);
    }
} catch (InvalidArgumentException $e) {
    fail($e->getMessage(), stripos($e->getMessage(), 'not found') !== false ? 404 : 400);
} catch (OltError | TelnetError $e) {
    fail($e->getMessage(), 502);
} catch (Throwable $e) {
    error_log('SmartOLT api: ' . $e->getMessage());
    fail($e->getMessage(), 500);
}

fail('Unknown action.', 404);
