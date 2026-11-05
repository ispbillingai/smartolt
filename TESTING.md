# Verification and feature audit

Reference recording: `F:\chatgptvideos\oltvideo\Recording 2026-11-05 202649.mp4` (16:01).
Sampled screens throughout the recording and the detailed ONU/OLT settings screens were reviewed.

All device testing uses disposable local simulations, as requested. No demo server, physical OLT,
customer ONU, existing MikroTik or production VPN was changed. The local site configuration
`backend/config/database.php` is not used by the integration runner.

## Evidence and what the counts mean

The suites below test actual application requests, database writes, protocol exchanges and
browser actions. A page rendering successfully is not counted as proof that every operation on
that page works. The browser manifest separately lists discovered controls, recorded interactions
and named checks with verified outcomes. Repeated table-row controls share a behavior; the
manifest labels controls that were not individually exercised.

Results on 5 November 2026 are recorded in `tests/audit-results.json`. Earlier runs under
`.test-runtime` include deliberately failed experiments and failures that led to fixes.

| Suite | Passing checks | Evidence |
|---|---:|---|
| Application API / API access rules | 54 / 13 | `local-7bbe5f4885db4181bbecda215bda3023` |
| HTTP application workflows / session cookies | 173 / 6 | Same integration run |
| ACS / dashboard data freshness | 47 / 5 | Same integration run |
| Driver / database integrity | 49 / 30 | Same integration run |
| SNMP UDP / counter rates / Telnet | 18 / 10 / 11 | Same integration run |
| Time conversion | 8 | Same integration run |
| VPN helpers / certificates / installer | 12 / 7 / 7 | Same integration run |
| MikroTik script generation after the RouterOS fix | 22 | `routeros-7f6e0a88a23545d887282773ff61e858/vpn-unit-final.txt` |
| Backup/storage/cron/migration integrity | 25 | Fresh focused `local-df8735b6888d4adf97441918f628f2eb` |
| Catalogue/queue integrity | 18 | Focused replay log under `local-86ba687530ff4b5886501ad7d8f07e38` |
| Real XAMPP browser | 51 route checks, 2 layout checks and 47 behavior scenarios | Primary + focused runs described below |
| Real Apache access rules | 18 | `apache-713ea2f1af73457096df1d643b04dde6` |
| Actual OpenVPN TLS/authentication | 3 | `openvpn-67537c004cc9433aba8f63c8fb609fd4` |
| Actual RouterOS 7.25.1 configuration execution | 35 | `routeros-7f6e0a88a23545d887282773ff61e858/routeros-results.txt` |

All paths above are inside `.test-runtime`. The PHP development server intermittently returned
incomplete responses on this Windows host, so primary browser verification used XAMPP Apache
and PHP 8.2.4 instead. That run passed 99 of 100 checks, with zero browser JavaScript errors.
The remaining event scenario had no alert in its reused fixture; the test was corrected to
create its own unreachable local OLT and then verify both mute options and unmute. Its focused
Apache replay passed. This is combined evidence, **not a claim of a single 100/100 run**.

Primary browser artifacts: `apache-components-899561dd52ef4489a43cfe4e1b27de84/components/`.
Final event replay: `apache-components-398a68dcc02248179efc624143d71765/components/`.
The primary manifest contains 51 route inventories and 155 light/dark/mobile/auth screenshots.
It records 3,580 control entries including repeated navigation; 186 matched exact-route
interactions. These are not 3,580 independently tested functions. The 47 named scenarios verify
the distinct application workflows and repeated control behaviors.

All 71 PHP files passed syntax checks with both the CLI PHP and XAMPP PHP. Five application/test
JavaScript files, ten shell files and the PowerShell test scripts passed syntax checks.
`git diff --check` passed. Later backend fixes were verified with the focused suites above.

### Actual RouterOS execution

The generated script was also imported into an official RouterOS 7.25.1 CHR image in a disposable
Hyper-V VM. The VM had no network adapter, so it could not reach customer equipment or the
internet. Script files were transferred through its serial pipe and checked against the generated
source before import. Raw console pasting initially dropped characters; those transport errors
were excluded from the code verdict.

This test found and fixed a real repeat-import failure: `place-before=0` could refer to a firewall
row removed earlier in the script. The generator now resolves the current first rule's internal
ID. Actual first/repeat imports confirm the enabled OVPN client, trusted CA, server verification,
four scoped NAT rules, seven filter rules, two mangle rules and one private return route without
duplicates. Additional imports verify two OLTs coexist, special characters in credentials/names
are preserved exactly, and disabling SNMP removes only its forwarding rules while preserving the
other OLT. The 35 checks comprise four exact-source readbacks, four actual imports and 27
configuration assertions. Evidence and reproduction steps are under
`routeros-7f6e0a88a23545d887282773ff61e858/`. Both disposable VM registrations were removed;
their disks and logs remain for inspection. No existing VM or host network settings were changed.

This verifies RouterOS configuration execution. It does **not** verify a RouterOS tunnel carrying
packets, Linux kernel forwarding or physical OLT reachability. The separate OpenVPN test verifies
TLS/authentication; RouterOS 6 has generation tests but no executed VM test.

## Repeat the local checks

Use PHP with PDO MySQL, cURL, mbstring and OpenSSL, MariaDB, Git Bash, Node.js 20+ and Playwright.
Set `NODE_PATH` if Playwright is supplied outside the normal module path, and `SMARTOLT_BROWSER`
to an installed Chrome/Chromium executable when necessary.

```powershell
powershell -File tests/run-local.ps1 -Php C:\PHP\php.exe -MariaDbBin F:\xampp\mysql\bin -BrowserTest -Components -Node <path-to-node.exe>
```

The runner creates an isolated application copy, a fresh MariaDB data directory and random
loopback ports. It uses local OLT, SNMP and ACS fixtures, retains logs/screenshots under
`.test-runtime/local-<id>`, and stops only processes it started. Do not point individual
database tests at a production configuration.

To use real XAMPP Apache/mod_php instead of PHP's development server, add
`-Apache F:\xampp\apache\bin\httpd.exe -PhpRoot F:\xampp\php`. This starts a separate temporary
Apache instance; it does not modify or stop existing XAMPP services.

Additional independent checks:

```text
php tests/snmp_test.php
php tests/snmp_rates_test.php
php tests/telnet_test.php
php tests/driver_integrity_test.php
php tests/time_test.php
php tests/vpn_test.php
bash tests/vpn_test.sh
bash tests/vpn_certificate_test.sh
bash tests/install_test.sh
powershell -File tests/apache_access_test.ps1
pwsh -File tests/openvpn_test.ps1 -OpenVpn <path-to-OpenVPN-2.7-or-newer.exe>
```

The adapter-free OpenVPN test needs OpenVPN 2.7+ because it uses a null device. It starts no
installed service, creates no network adapter or routes, and checks the actual TLS/authentication
exchange with the application authentication helper. It is separate from Linux service/firewall
deployment verification.

## Behaviors exercised

| Area | Local verification |
|---|---|
| Authentication/API | Login failure/success, mandatory password reset, CSRF, password changes, session versus remembered cookies, logout, protected routes, token rejection, POST-only mutations, malformed JSON and per-item bulk outcomes |
| Shared interface | Real Chart.js and Leaflet; shared light/dark palette; desktop and 390px phone layout; navigation, theme persistence, chart data retained on theme changes, dialogs and cancellation |
| ONU workflows | Discovery/authorization, queued authorization, filters, selection/bulk actions, live readings, notes, GPS fields, VLAN/speed changes, activation, reboot, factory-reset request, explicit serial replacement, deletion and CSV |
| OLT workflows | Add/edit/read/delete, profile and board views, VLAN changes, save configuration, read-only diagnostics and copying output |
| Catalogues/rules | Create/edit/search/export/import/delete, missing-ID errors, reference preservation, pause/resume automatic rules, preset dependencies and transactional queue updates |
| Reports/events/tickets | Filtered downloads, import with skipped rows, status/history/time ranges, mismatch scan, auto-reconciliation setting, mute/unmute, create/close/reopen tickets and timezone conversion |
| SNMPv2c | Actual UDP, source/request correlation, retries/timeouts, BER errors, bounded walks, IF-MIB counters, exact Counter64 arithmetic, reset/discontinuity and wrap handling |
| Huawei/Telnet | Actual TCP fixture, fragmented negotiations/prompts/paging, serial identity checks, complete/incomplete/empty inventories, readback verification, partial service failure/rollback and atomic metadata/deletion cleanup |
| Ethernet/MAC | Actual simulated CLI response, exact counters, live start/stop, persisted history and freshness filtering, valid ports and MAC CSV |
| Backups/cron | Encrypted capture/download/delete, secret-free activity logs, schedules, due/retry/disabled behavior, overlap lock, persistent maintenance times, failure exit status and injected database failures |
| ACS | Existing FreeISP ACS API contract, exact serial aliases/ambiguity, encrypted key, safe WiFi/LAN/host readings, HTTP forms, task queue/status transitions, malformed replies, uncertain delivery, idempotency, endpoint/ONU locks and secret-free HTML/CSV |
| VPN | Generated RouterOS commands, certificate trust/fingerprint/hostname, helper authentication/allocation/status/revocation, stopped/stale detection and actual OpenVPN accepted/rejected handshakes |
| Installation | Mock Linux install commands, existing-data refusal, secret-safe configuration, discovered PHP handler, real XAMPP PHP execution and denial of private root/nested paths |

`tests/audit-backend.json` inventories manager, Huawei, Telnet and SNMP methods and documents
postconditions and remaining boundaries. It is an audit map, not a line/branch-coverage claim.

## Concrete defects corrected

- Dashboard-only colours and styling now use the same theme as forms, tables, charts and auth.
- Cancelled confirmations could leave a form approved; cancellation now clears the pending action.
- Empty Huawei discovery using “ONTs do not exist” incorrectly marked a working OLT as failed.
- Partial/unrecognized inventories could clear valid state; completeness is checked first.
- Reused physical ONU positions could inherit an unrelated customer's metadata.
- Some successful-looking device responses did not apply changes; readback checks now reject them.
- Service edits could destroy a previous service on failure or collapse multiple services.
- Deletions could leave notes/tickets behind or broaden scoped automatic rules.
- Deleting a preset could discard queued authorization intent; referenced presets now reject deletion.
- Replacing a pending authorization could lose the old row before validation completed.
- Independent maximum counts could inflate network-history totals; coherent samples are used.
- Display timezone changes previously did not control date filters and ticket input.
- Backup schedules, per-port traffic, MAC export and the ACS/WiFi workflows were missing.
- API mutations could use GET; mutation methods and credentials now have explicit request rules.
- VPN setup lacked a verified server identity; CA trust and canonical-name verification are configured.
- Re-pasting a MikroTik script could fail because numeric firewall row positions referred to removed
  rules. Rule insertion now uses the current internal rule IDs, checked in actual RouterOS.
- Linux-only PHP handler settings interfered with XAMPP; mod_php is now handled.
- Installer assumptions could overwrite shared state or report partial installation as complete.

## Comparison with the recording

| Feature | Current state and limit |
|---|---|
| Overview, configured/unconfigured lists, filters and authorization | Implemented; dashboard is a distinct operations overview using the shared application theme |
| Status causes, optical levels/history, location, VLAN/speed edits and bulk actions | Implemented through Huawei CLI; firmware compatibility requires a device check |
| Zones, sectors, splitters, types, presets, events, tickets and CSV reports | Implemented and exercised through HTTP/browser workflows |
| VPN setup and connection visibility | Implemented; script, helper, certificate and actual OpenVPN protocol checks are separate evidence |
| SNMP community/port and uplink/interface traffic | Read-only v2c IF-MIB polling and retained charts |
| ONU traffic | Physical Ethernet-port counters/rates and MAC export; no monthly subscriber billing usage |
| Configuration backup | Encrypted manual and scheduled snapshots; no whole-OLT restore |
| TR-069, WiFi, LAN and hosts | FreeISP ACS connection; safe reads plus tracked refresh/reboot/primary-radio WiFi changes |
| WiFi signal report | Only RSSI/signal parameters actually supplied by the ACS; no invented values |
| Routing/bridging | Saved authorization template; bridge ETH1 native VLAN. Full WAN/routing-mode changes are not implemented |
| SNMP write/traps/v3 | Not implemented |
| Generic LAN/DHCP/CATV writes, ONU IP pools, remote ACLs and VoIP provisioning | Not implemented |
| IPTV/multiple-service provisioning and other vendors/PON families | Not implemented; existing multiple services are protected from single-service edits |
| Advanced NTP/fan controls and whole-OLT restore | Not implemented |

## Deployment boundaries

- Local tests do not prove hardware firmware compatibility, physical forwarding, optical readings
  or delivery of an ACS task to a real ONU.
- VPN helpers need Linux services/firewall setup. Passing the web app under XAMPP does not turn
  Windows into the production VPN host.
- Apply updated VPN helpers and generated MikroTik commands when upgrading a deployed site.
- Keep the site's existing encryption key/configuration. Stored passwords and backups depend on it.
- Cron must run for polling history, automatic authorization/reconciliation, scheduled backups
  and retention. Empty/stale data is labelled instead of presented as healthy service.
- Map tiles still use OpenStreetMap; locally served map/chart libraries do not remove that dependency.
