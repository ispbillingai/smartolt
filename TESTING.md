# Local verification and feature audit

Reference: `F:\chatgptvideos\oltvideo\Recording 2026-11-05 202649.mp4` (16:01).
Reviewed sampled screens throughout the recording and detailed ONU/OLT settings screens.
All network execution in this review used local simulations, as requested. No demo server,
physical OLT, MikroTik, customer ONU or production VPN was modified.

## Results, 5 November 2026

305 checks passed across the final integration run and focused follow-up checks:
54 API, 173 web workflows, 6 encrypted-storage, 5 dashboard freshness, 18 SNMP UDP,
10 traffic-rate, 7 Telnet/Huawei, 19 MikroTik script, 11 VPN shell, and 2 browser checks.
All 58 PHP files passed syntax checks; application/dashboard JavaScript passed syntax checks.
Browser verification used Chrome with real Chart.js rendering, dark/light themes and a 390px
phone viewport. It caught a screen-reader label escaping its scroll container; that overflow
was fixed and the checks passed again. Screenshots use simulated test data.

These are simulation results, not a claim that the application already matches every feature
in the video or that a production router/OLT connection has been verified.

## Repeatable checks

The PHP network fixtures implement actual local TCP/UDP exchanges. The integration suites use
a real isolated MariaDB database and the application HTTP endpoints.

```text
php tests/snmp_test.php
php tests/snmp_rates_test.php
php tests/telnet_test.php
php tests/vpn_test.php
bash tests/vpn_test.sh
```

`tests/run-local.ps1` provides an isolated Windows/XAMPP integration runner. Read its parameters
before running. It must never target a production database. PHP needs PDO MySQL, cURL, mbstring
and OpenSSL; Git Bash runs the HTTP flow tests. No SNMP PHP extension is required.

Optional browser check: use Node.js 20 or newer, install/provide Playwright, set `SMARTOLT_BROWSER` to a Chromium executable
if needed, then run `node tests/browser_test.cjs http://127.0.0.1:48099` while the local fixture is
running after the web suite. It checks desktop/light/mobile rendering, horizontal overflow,
SNMP/backups/VPN pages and browser JavaScript. Images are written into ignored `.test-runtime`.
The runner accepts `-BrowserTest -Node <path-to-node.exe>` to perform this check before stopping
the isolated services. The dashboard regression suite also checks stale versus fresh readings.

## Coverage

| Area | What is verified locally | Boundary |
|---|---|---|
| Authentication | Wrong/missing API tokens, forged demo Host rejection, password-change CSRF and login lifecycle | No external identity provider |
| Huawei | Discovery, profiles, VLANs, service ports, authorization, ONU changes, bulk actions, reboot, save and diagnostics against a fake OLT | Real firmware output and live hardware not verified |
| Telnet | Fragmented negotiation, paging and Huawei parameter prompts | No SSH driver |
| SNMPv2c | UDP transport, response correlation, source-port filtering, retries, timeouts, BER validation, bounded walks, IF-MIB counters | No v3, SET, traps, vendor optical MIB or per-ONU traffic |
| Traffic | Exact Counter64 deltas, reset/discontinuity handling, 32-bit ambiguity gaps, persisted samples and interface charts | Ethernet interfaces exposed by firmware only |
| MikroTik | RouterOS 6/7 script generation, quoting, client enablement, narrow Telnet/SNMP forwarding and return-routing rules | No RouterOS VM or physical router execution |
| VPN server | Authentication/allocation, status formats, IPv6 peer formatting, stopped/stale detection and revocation with shell fixtures | No real TLS handshake, Linux service or kernel firewall verification |
| Backups | Capture, encrypted storage, integrity check, download/delete and absence of plaintext activity transcripts | Manual snapshots only; no restore |
| App workflows | Catalogue, presets, automatic/later authorization, maps, reports, CSV, tickets, events and settings HTTP flows | Simulations do not demonstrate carrier production readiness |

## Comparison with the recording

| Feature shown | Current state |
|---|---|
| Overview, configured/unconfigured ONUs, filters and authorization | Implemented; dashboard redesigned as an operations view |
| Status causes, optical levels/history, location, speed/VLAN changes and bulk actions | Implemented using Huawei CLI; model/firmware validation required |
| Zones, sectors, splitters, ONU types, presets, tickets and reports | Implemented, except the explicitly missing reports below |
| VPN setup and connection visibility | Implemented and improved; tested with fixtures |
| SNMP read community and port | Added read-only v2c polling and independent health |
| Ethernet/uplink traffic | Added measured counters, rates and 24-hour charts |
| Configuration backups | Added encrypted manual snapshots |
| SNMP write community and traps | Not implemented |
| Per-ONU traffic, usage reports and WiFi signal reports | Not implemented |
| TR-069, WiFi/LAN/DHCP/CATV management | No ACS integration; not implemented |
| ONU IP pools, remote ACLs, VoIP profiles, advanced NTP/fan settings | Not implemented |
| MAC-table export | Not implemented |
| IPTV provisioning and additional PON/vendor families | Not implemented; model choices are not proof of driver support |
| Scheduled backups and configuration restore | Not implemented |

## Known deployment limitations

- RouterOS server-certificate verification still needs trusted-CA distribution and a matching
  server identity. Existing compatibility-oriented OpenVPN settings are not a hardened configuration.
- Upgrading the repository alone does not update installed VPN helpers or MikroTik rules. Run the
  VPN setup script on the Linux host and re-apply generated router commands during deployment.
- User timezone settings are stored, but the manager still uses the existing Africa/Nairobi clock.
- Empty or stale data must not be read as healthy service. The dashboard marks polling freshness;
  historical traffic and availability require the cron job to be running.
- Keep the deployment's encryption key backed up; changing it prevents reading stored secrets/backups.

Protocol references: [MikroTik OpenVPN](https://help.mikrotik.com/docs/spaces/ROS/pages/2031655/OpenVPN),
[MikroTik mangle/routing marks](https://help.mikrotik.com/docs/spaces/ROS/pages/48660587/Mangle),
[OpenVPN 2.6 manual](https://openvpn.net/community-docs/community-articles/openvpn-2-6-manual/),
[SNMP RFC 3416](https://www.rfc-editor.org/rfc/rfc3416),
[IF-MIB RFC 2863](https://www.rfc-editor.org/rfc/rfc2863).
