<?php
/**
 * SmartOLT provisioner, served at https://install.<domain>/install.php (never from an ISP site:
 * .htaccess and the site vhosts deny /provisioner/).
 *
 * The billing dashboard's "Enable SmartOLT" POSTs {"subdomain": "<sub>", "token": "<hmac>"} where
 * hmac = hash_hmac('sha256', <sub>, <fleet secret>), the same token the site API accepts. We make
 * the <sub>.<domain> DNS A record on DigitalOcean, then build the site with install.sh, which runs
 * as root in its own system job through /usr/local/sbin/smartolt-provision (PHP-FPM itself sees
 * /etc read-only). Safe to call again: an existing record or site is reused, never replaced.
 *
 * Settings come from /etc/smartolt-provisioner.php (written by provisioner/setup.sh, not in git).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

function plog(string $message): void
{
    @file_put_contents('/var/log/smartolt-provision/provision.log', date('Y-m-d H:i:s') . ' ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

/** One DigitalOcean API call. Returns [http code, decoded body]. */
function do_api(string $method, string $path, string $token, ?array $body = null): array
{
    $ch = curl_init('https://api.digitalocean.com/v2/' . $path);
    $options = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
    ];
    if ($body !== null) $options[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$raw, true) ?: []];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(405, ['success' => false, 'error' => 'Use POST with a JSON body.']);
}
$config = @include '/etc/smartolt-provisioner.php';
if (!is_array($config) || empty($config['domain']) || empty($config['ip']) || empty($config['do_token']) || empty($config['fleet_secret'])) {
    reply(500, ['success' => false, 'error' => 'Installer not configured', 'details' => 'The SmartOLT installer is not configured on this server.']);
}

$in = json_decode((string)file_get_contents('php://input'), true);
$sub = is_array($in) ? strtolower(trim((string)($in['subdomain'] ?? ''))) : '';
$token = is_array($in) ? (string)($in['token'] ?? '') : '';
if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/D', $sub)) {
    reply(400, ['success' => false, 'error' => 'Invalid subdomain', 'details' => 'Use only lowercase letters, numbers and hyphens.']);
}
// Only a dashboard that holds the fleet secret can ask for a site, and only for its own name.
if ($token === '' || !hash_equals(hash_hmac('sha256', $sub, (string)$config['fleet_secret']), $token)) {
    plog("refused {$sub} from " . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    reply(403, ['success' => false, 'error' => 'Unauthorized', 'details' => 'This request did not come from a SmartOLT billing dashboard.']);
}
$reserved = ['install', 'www', 'mail', 'smtp', 'imap', 'pop', 'ftp', 'api', 'vpn', 'admin', 'root', 'mysql', 'sys', 'smartolt',
    'register', 'acs', 'ns1', 'ns2', 'phpmyadmin', 'webmail', 'cpanel', 'autodiscover', 'autoconfig'];
if (in_array($sub, $reserved, true)) {
    reply(400, ['success' => false, 'error' => 'Reserved name', 'details' => "{$sub} is reserved and cannot be used for a SmartOLT site."]);
}

$domain = (string)$config['domain'];
$fqdn = "{$sub}.{$domain}";

// DNS: reuse our own record, refuse a name that already points somewhere else.
[$code, $res] = do_api('GET', "domains/{$domain}/records?type=A&name=" . rawurlencode($fqdn), (string)$config['do_token']);
if ($code !== 200) {
    plog("dns check {$fqdn} failed HTTP {$code}");
    reply(502, ['success' => false, 'error' => 'DNS check failed', 'details' => "Error checking domain. HTTP status: {$code}"]);
}
$addresses = array_column($res['domain_records'] ?? [], 'data');
if ($addresses && !in_array($config['ip'], $addresses, true)) {
    reply(409, ['success' => false, 'error' => 'Subdomain already taken', 'details' => "{$fqdn} already points to another server."]);
}
if (!$addresses) {
    [$code] = do_api('POST', "domains/{$domain}/records", (string)$config['do_token'], ['type' => 'A', 'name' => $sub, 'data' => $config['ip'], 'ttl' => 300]);
    if ($code !== 201 && $code !== 200) {
        plog("dns create {$fqdn} failed HTTP {$code}");
        reply(502, ['success' => false, 'error' => 'DNS record failed', 'details' => "Could not create {$fqdn}. HTTP status: {$code}"]);
    }
    plog("dns created {$fqdn}");
}

// Build the site. Keep going if the dashboard stops waiting; the dashboard confirms by calling the site.
ignore_user_abort(true);
set_time_limit(900);
$proc = proc_open(['sudo', '-n', '/usr/local/sbin/smartolt-provision', $sub], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
if (!is_resource($proc)) {
    reply(500, ['success' => false, 'error' => 'Installation failed', 'details' => 'The installer could not be started.']);
}
$output = (string)stream_get_contents($pipes[1]);
fclose($pipes[1]);
$exit = proc_close($proc);
plog("install {$sub} exit {$exit}: " . str_replace("\n", ' | ', trim(substr($output, -1500))));

if ($exit === 0 && (strpos($output, 'Installation complete') !== false || strpos($output, 'Site already installed') !== false)) {
    reply(200, ['success' => true, 'message' => "SmartOLT site {$fqdn} is ready", 'site_url' => "https://{$fqdn}/"]);
}
$lines = array_values(array_filter(array_map('trim', explode("\n", $output)), 'strlen'));
reply(500, ['success' => false, 'error' => 'Installation failed', 'details' => mb_substr((string)end($lines) ?: 'No output from the installer.', 0, 300)]);
