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
set_time_limit(180);

require_once __DIR__ . '/backend/lib/OltManager.php';

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $error, int $code = 400): void
{
    out(['ok' => false, 'error' => $error], $code);
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_GET;
}

try {
    $mgr = new OltManager();
} catch (Throwable $e) {
    fail('Database not configured on this SmartOLT site.', 500);
}
$mgr->source = 'api';

$given = '';
if (preg_match('/^Bearer\s+([0-9a-f]{64})$/', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) {
    $given = $m[1];
} elseif (isset($in['token'])) {
    $given = (string)$in['token'];
}
if ($given === '' || !hash_equals($mgr->apiToken(), $given)) {
    fail('Unauthorized', 401);
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
            out(['ok' => true] + $mgr->bulk((array)($in['ids'] ?? []), (string)($in['bulk_action'] ?? ''), (int)($in['speed_profile_id'] ?? 0)));

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
