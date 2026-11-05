<?php
/** RouterOS 6/7 script generation, kept independent of database and VPN provisioning. */
final class Mikrotik
{
    public const SNMP_NAT_PORT = 2161;

    public static function script(array $olt, string $server, string $password, string $caPem): string
    {
        $ca = @openssl_x509_read($caPem);
        $details = $ca ? openssl_x509_parse($ca) : false;
        if (!$ca || !$details || !str_contains($details['extensions']['basicConstraints'] ?? '', 'CA:TRUE')
            || ($details['validTo_time_t'] ?? 0) <= time() || ($details['validFrom_time_t'] ?? PHP_INT_MAX) > time()) {
            throw new RuntimeException('The VPN trust certificate is missing, invalid or expired. Run the VPN server setup first.');
        }
        openssl_x509_export($ca, $caPem);
        if (strlen($caPem) > 4096) throw new RuntimeException('The VPN CA is too large for RouterOS 6 inline import.');
        $fingerprint = openssl_x509_fingerprint($ca, 'sha256');
        $caFile = 'smartolt-ca-' . substr($fingerprint, 0, 12);
        $pemLiteral = '"' . str_replace("\n", '\\r\\n', str_replace("\r", '', $caPem)) . '"';
        foreach (['vpn_ip', 'lan_ip'] as $field) {
            if (!filter_var($olt[$field] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new InvalidArgumentException('The VPN and OLT addresses must be valid IPv4 addresses.');
            }
        }
        if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $server)) throw new InvalidArgumentException('Invalid VPN server hostname.');
        $port = self::port($olt['lan_port'] ?? 23);
        $if = 'smartolt-olt' . (int)$olt['id'];
        $lan = $olt['lan_ip'];
        $prefix = explode('.', $olt['vpn_ip']);
        $source = $prefix[0] . '.' . $prefix[1] . '.0.1';
        $tag = 'comment=' . self::quote($if);
        $common = 'name=' . $if . ' connect-to=' . self::quote($server) . ' port=1194 user=' . self::quote($olt['vpn_user'])
            . ' password=' . self::quote($password) . ' mode=ip auth=sha1 verify-server-certificate=yes tls-version=only-1.2 add-default-route=no use-peer-dns=no disabled=no comment='
            . self::quote('SmartOLT ' . $olt['name']);
        $client = '/interface ovpn-client add ' . $common;
        $route7 = ':if ([:len [/routing table find name=' . self::quote($if) . ']] = 0) do={ /routing table add name=' . $if . ' fib }; /ip route add dst-address=' . $source . '/32 gateway=' . $if . ' routing-table=' . $if . ' ' . $tag;
        $route6 = '/ip route add dst-address=' . $source . '/32 gateway=' . $if . ' routing-mark=' . $if . ' ' . $tag;
        $lines = [
            '{',
            ':local version [/system resource get version]',
            ':local major [:tonum [:pick $version 0 [:find $version "."]]]',
            ':if (($major != 6) && ($major != 7)) do={ :error "SmartOLT requires RouterOS 6 or 7" }',
            // The authenticated panel supplies this exact public CA. No unverified fetch is used.
            ':if ([:len [/certificate find fingerprint=' . self::quote($fingerprint) . ']] = 0) do={',
            '  /file print file=' . self::quote($caFile),
            '  :delay 1s',
            '  /file set [find name=' . self::quote($caFile . '.txt') . '] contents=' . $pemLiteral,
            '  /certificate import file-name=' . self::quote($caFile . '.txt') . ' passphrase=""',
            '  /file remove [find name=' . self::quote($caFile . '.txt') . ']',
            '}',
            ':local ca [/certificate find fingerprint=' . self::quote($fingerprint) . ']',
            ':if ([:len $ca] = 0) do={ :error "SmartOLT CA import failed; VPN was not changed" }',
            '/certificate set $ca trusted=yes',
            '/interface ovpn-client remove [find name=' . self::quote($if) . ']',
            // Parse only the selected branch so RouterOS 6 never parses v7-only syntax/enums.
            ':if ($major = 7) do={ :local install [:parse ' . self::quote($client . ' cipher=aes256-cbc') . ']; $install } else={ :local install [:parse ' . self::quote($client . ' cipher=aes256') . ']; $install }',
            '/ip firewall nat remove [find ' . $tag . ']',
            '/ip firewall filter remove [find ' . $tag . ']',
            '/ip firewall mangle remove [find ' . $tag . ']',
            '/ip route remove [find ' . $tag . ']',
            // Several OLTs can use the same router: return each connection through its own tunnel.
            ':if ($major = 7) do={ :local install [:parse ' . self::quote($route7) . ']; $install } else={ :local install [:parse ' . self::quote($route6) . ']; $install }',
        ];
        $lines[] = self::first('mangle', 'chain=prerouting in-interface=' . $if . ' src-address=' . $source . ' connection-state=new action=mark-connection new-connection-mark=' . $if . ' passthrough=no ' . $tag);
        $lines[] = self::first('mangle', 'chain=prerouting in-interface=!' . $if . ' src-address=' . $lan . ' connection-mark=' . $if . ' action=mark-routing new-routing-mark=' . $if . ' passthrough=no ' . $tag);
        // Add the deny rules first: each later allow goes before them and before existing drops.
        $lines[] = self::first('filter', 'chain=input in-interface=' . $if . ' action=drop ' . $tag);
        $lines[] = self::first('filter', 'chain=forward in-interface=' . $if . ' action=drop ' . $tag);
        $lines[] = self::first('filter', 'chain=forward out-interface=' . $if . ' action=drop ' . $tag);
        $services = [['tcp', 2323, $port]];
        if (!empty($olt['snmp_enabled'])) $services[] = ['udp', self::SNMP_NAT_PORT, self::port($olt['snmp_port'] ?? 161)];
        foreach ($services as [$protocol, $tunnelPort, $oltPort]) {
            $lines[] = self::first('nat', 'chain=dstnat in-interface=' . $if . ' src-address=' . $source . ' dst-address=' . $olt['vpn_ip']
                . ' protocol=' . $protocol . ' dst-port=' . $tunnelPort . ' action=dst-nat to-addresses=' . $lan . ' to-ports=' . $oltPort . ' ' . $tag);
            $lines[] = self::first('nat', 'chain=srcnat src-address=' . $source . ' dst-address=' . $lan . ' protocol=' . $protocol
                . ' dst-port=' . $oltPort . ' connection-mark=' . $if . ' action=masquerade ' . $tag);
            $lines[] = self::first('filter', 'chain=forward in-interface=' . $if . ' src-address=' . $source . ' dst-address=' . $lan
                . ' protocol=' . $protocol . ' dst-port=' . $oltPort . ' connection-nat-state=dstnat action=accept ' . $tag);
            $lines[] = self::first('filter', 'chain=forward out-interface=' . $if . ' src-address=' . $lan . ' dst-address=' . $source
                . ' protocol=' . $protocol . ' src-port=' . $oltPort . ' connection-state=established,related action=accept ' . $tag);
        }
        $lines[] = ':put "SmartOLT configured. Check that ' . $if . ' becomes running under Interfaces, then refresh the OLT in SmartOLT."';
        $lines[] = '}';
        return implode("\n", $lines);
    }

    private static function first(string $table, string $rule): string
    {
        $path = '/ip firewall ' . $table;
        // Console row numbers can still point at a removed rule during script reapplication.
        // The internal ID returned by find is stable and works without a preceding print.
        return ':if ([:len [' . $path . ' find]] > 0) do={ ' . $path . ' add ' . $rule . ' place-before=[:pick [' . $path . ' find] 0] } else={ ' . $path . ' add ' . $rule . ' }';
    }

    private static function port($value): int
    {
        $port = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) throw new InvalidArgumentException('Invalid OLT service port.');
        return $port;
    }

    private static function quote(string $value): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $value)) throw new InvalidArgumentException('Router commands cannot contain control characters.');
        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']) . '"';
    }
}
