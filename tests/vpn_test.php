<?php
// php tests/vpn_test.php -- no database, router or root permissions required.
require_once __DIR__ . '/../backend/lib/Mikrotik.php';
$ca = file_get_contents(__DIR__ . '/fixtures/vpn-ca.pem');
$failures = 0;
function check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failures++;
}
$olt = ['id' => 12, 'name' => 'OLT "A" $value', 'vpn_ip' => '10.253.1.2', 'vpn_user' => 'demo-olt12',
    'lan_ip' => '192.168.100.2', 'lan_port' => 23, 'snmp_enabled' => 1, 'snmp_port' => 1161];
$s = Mikrotik::script($olt, 'demo.smartolt.example.com', 'secret$"\\', $ca);
$parsed = strtr($s, ['\\\\' => '\\', '\\"' => '"', '\\$' => '$']);
check('client is enabled explicitly in both RouterOS branches', substr_count($s, 'disabled=no') === 2);
check('both RouterOS branches authenticate the VPN server', substr_count($s, 'verify-server-certificate=yes tls-version=only-1.2') === 2);
check('exact CA is imported and trusted without an insecure download', str_contains($s, '/certificate import file-name=') && str_contains($s, openssl_x509_fingerprint($ca, 'sha256')) && !str_contains($s, '/tool fetch'));
check('RouterOS 6 and 7 cipher syntax', str_contains($s, 'cipher=aes256"]') && str_contains($s, 'cipher=aes256-cbc"]'));
check('version-specific commands are parsed only after selecting the RouterOS branch', substr_count($s, ':local install [:parse ') === 4);
check('no default route or DNS replacement', substr_count($s, 'add-default-route=no use-peer-dns=no') === 2);
check('quoted values cannot expand RouterOS variables or terminate commands', str_contains($parsed, 'password="secret\\$\\"\\\\"') && str_contains($parsed, 'SmartOLT OLT \\"A\\" \\$value'));
check('SNMP forwards UDP2161 to configured LAN port', str_contains($s, 'protocol=udp dst-port=2161 action=dst-nat to-addresses=192.168.100.2 to-ports=1161'));
check('Telnet forwards TCP2323 to LAN port23', str_contains($s, 'protocol=tcp dst-port=2323 action=dst-nat to-addresses=192.168.100.2 to-ports=23'));
check('NAT is limited to this tunnel and server', str_contains($s, 'in-interface=smartolt-olt12 src-address=10.253.0.1 dst-address=10.253.1.2'));
check('source NAT does not rewrite unrelated LAN traffic', !str_contains($s, 'chain=srcnat dst-address=') && str_contains($s, 'chain=srcnat src-address=10.253.0.1 dst-address=192.168.100.2'));
check('source NAT is scoped to connections received through this tunnel', str_contains($s, 'dst-port=1161 connection-mark=smartolt-olt12 action=masquerade'));
check('return traffic allowed before existing firewall drops using stable rule IDs', str_contains($s, 'src-port=1161 connection-state=established,related action=accept comment="smartolt-olt12" place-before=[:pick [/ip firewall filter find] 0]') && !str_contains($s, 'place-before=0'));
check('router services and unrelated forwarding are blocked', str_contains($s, 'chain=input in-interface=smartolt-olt12 action=drop') && str_contains($s, 'chain=forward in-interface=smartolt-olt12 action=drop'));
check('multiple tunnels have separate return routes for v6 and v7', str_contains($s, 'routing-table=smartolt-olt12') && str_contains($s, 'routing-mark=smartolt-olt12') && str_contains($s, 'new-connection-mark=smartolt-olt12'));
check('re-paste cleans only this OLT rules', substr_count($s, 'remove [find comment="smartolt-olt12"]') === 4);
$olt['snmp_enabled'] = 0;
check('disabled SNMP has no UDP forwarding', !str_contains(Mikrotik::script($olt, 'vpn.example.com', 'pass', $ca), 'protocol=udp'));
$olt['vpn_ip'] = '17.0.5.6';
check('existing legacy tunnels keep their server address', str_contains(Mikrotik::script($olt, 'vpn.example.com', 'pass', $ca), 'src-address=17.0.0.1'));
foreach (['lan_ip' => '1.2.3.4; /system reboot', 'vpn_ip' => '::1', 'lan_port' => 70000] as $field => $value) {
    $bad = $olt; $bad[$field] = $value;
    try { Mikrotik::script($bad, 'vpn.example.com', 'pass', $ca); check("reject invalid $field", false); }
    catch (InvalidArgumentException $e) { check("reject invalid $field", true); }
}
try { Mikrotik::script($olt, 'vpn.example.com', 'pass', ''); check('missing CA never disables verification', false); }
catch (RuntimeException $e) { check('missing CA never disables verification', true); }
exit($failures ? 1 : 0);
