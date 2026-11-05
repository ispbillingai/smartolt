# SmartOLT

An ISP's OLT panel, installed per ISP with its own login and database. The ISP Ledger billing
dashboard calls `api.php`; device access and provisioning live in this application.

The overview, lists, forms, login and password pages share one slate/teal light and dark theme.
The dashboard shows current availability, polling freshness, work queues, SNMP/VPN health,
OLT details and recent changes. Availability is the last observed snapshot, not an SLA.

## Pages and workflows

| Page | Function |
|---|---|
| `index.php` | Sign in, required first password change, network overview |
| `olts.php`, `olt.php` | OLT connections, readings, boards, PON ports, profiles, VLANs, SNMP, diagnostics and generated MikroTik setup |
| `unconfigured.php`, `authorize.php`, `later.php` | Discovery, authorization and queued authorization |
| `onus.php`, `onu.php` | Filters, bulk operations, live ONU readings, customer details, service changes, notes, replacement and deletion |
| `traffic.php` | ONU Ethernet-port counters, live polling, stored readings and MAC CSV |
| `acs.php` | Connect the FreeISP ACS, read an ONU, queue device refresh/reboot or a WiFi change, track task state |
| `map.php`, `graphs.php` | ONU/splitter locations and recorded network history |
| `catalog.php` | Zones, sectors, splitters, ONU types, speeds and presets, including CSV import/export |
| `reports.php` | Authorizations, imports/exports, traffic, WiFi, status, occupancy and configuration mismatches |
| `events.php`, `tasks.php`, `info.php` | Alerts, muting and operation history |
| `auto_actions.php`, `tickets.php` | Automatic authorization rules and scheduled technician tickets |
| `backups.php` | Encrypted snapshots, schedules and authenticated downloads |
| `settings.php`, `vpn.php` | Profile, password, display timezone and connection settings |
| `cron.php` | CLI polling, automatic authorization/reconciliation, backups and retention |

The Huawei MA5600T-family GPON driver targets MA5683T, MA5608T and MA5680T command formats over
Telnet. Local simulations do not establish compatibility with every model/firmware combination.
Other OLT vendors and PON families need their own driver implementation.

## Device changes and identity

Device operations share a per-OLT lock. Inventory is checked for completeness before stale rows
can be removed. Changes to serial numbers, services, VLANs and activation are read back where
the driver supports it; failed service changes attempt to restore the previous service.
Partial failures are reported instead of being counted as successful operations.

An externally replaced serial at the same physical position creates a fresh ONU record; it
does not inherit the previous customer's notes or settings. The explicit **Replace serial**
operation intentionally retains that customer's configuration.

Authorization's **Service template** records intended routing/bridging. Bridge authorization
sets the native VLAN on Ethernet port 1. Routing requires WAN settings already supplied by the
device profile or management system. Switching an existing ONU's routing mode is rejected
because this driver does not provision its complete WAN/LAN configuration. Multiple-service
or IPTV configurations are protected from single-service edits.

## SNMP and traffic

Enable read-only SNMPv2c in the OLT settings and provide its community and UDP port. The community
is encrypted and excluded from public API rows. No PHP SNMP extension is required.
The Uplink tab tests SNMP independently of Telnet and shows identity, uptime, IF-MIB interfaces,
counters and traffic charts. Cron polls enabled OLTs every five minutes. Rates require two valid
samples; resets, discontinuities and ambiguous 32-bit wraps produce gaps. SNMP history is retained
for 30 days. Failed SNMP reads do not overwrite Telnet connection health.

For direct OLTs, permit the monitoring server to reach the configured UDP port. For VPN OLTs,
re-apply the generated MikroTik commands after changing SNMP settings: tunnel UDP 2161 forwards
to the configured SNMP port; tunnel TCP 2323 forwards to Telnet.

ONU optical readings and Ethernet-port statistics use Huawei CLI commands. **Ethernet traffic**
reads the selected physical port, stores counters/rates and exports MAC addresses. It is not a
subscriber billing or monthly-usage measurement. Port history retains up to 500 displayed samples
within 24 hours; the report offers recent readings over 24 hours, 7 days or 30 days.
SNMPv3, SET operations, traps and vendor-specific optical MIBs are not implemented.

## ACS connection

Open **ACS connection** on the **VPN & TR069** page under Settings and enter your FreeISP ACS website URL and API
key. Remote connections require HTTPS with certificate verification. The API key is encrypted;
changing the ACS address requires a key for the new address and clears cached device mappings.

ONU matching uses the exact serial, including the equivalent GPON vendor-prefix/hex form.
Ambiguous matches are rejected. Readings include the device identity, firmware, IP, reported
WiFi/LAN parameters and connected hosts. Password parameters are excluded from saved snapshots,
pages, reports and logs. WiFi signal is shown only when the ACS actually reports it.

Refresh, reboot and WiFi changes create tracked requests. **Queued** means the ACS accepted a
task; **Completed** requires its task status to confirm completion. Uncertain delivery is shown
as unknown and is not automatically retried. WiFi updates use the ACS's primary-radio template;
generic WAN, DHCP, CATV and arbitrary parameter writes are not exposed here. ACS timestamps
without a timezone are explicitly labelled as ACS server time.

## Configuration backups and scheduling

**Configuration backups** under Settings captures, lists, downloads and deletes encrypted snapshots.
Each OLT can use a 6-hour, 12-hour, daily or weekly schedule, or manual-only capture. Cron runs
due captures, records their result and retries failed captures on a later run. Existing successful
snapshots survive capture failures. Download/delete require an authenticated session and CSRF.
Whole-OLT configuration restore is not implemented.

Run cron every five minutes. Its database lock prevents overlapping runs; maintenance and daily
reconciliation use saved completion times instead of depending on a particular minute.
Back up the site's encryption key separately: it is needed for stored credentials and snapshots.

## VPN installation and upgrade

On the Linux VPN host run `sudo bash vpn/setup_openvpn.sh <canonical-vpn-hostname>` from this
checkout. The script preserves existing credentials, address allocations and CA material, issues
a server certificate for that hostname, installs the helpers and restarts OpenVPN. Fresh setups
use private `10.253.0.0/16`; existing setups retain their network. Check the script's usage before
deployment and ensure the hostname resolves to the intended VPN server.

Copy each OLT's generated setup to its MikroTik. The script imports the public CA, checks its
fingerprint and enables server-certificate/name verification. It configures an enabled OVPN client,
restricted Telnet/SNMP forwarding and return routing. VPN status requires both a running service
and a fresh status file. Updating this repository does not replace already installed helpers or
already pasted router rules; those need the upgrade steps above.

Local verification includes an actual OpenVPN TLS/authentication exchange and script import on
a disposable RouterOS 7 CHR virtual machine. RouterOS 6 generation has fixture checks but has not
been executed on RouterOS 6. See [TESTING.md](TESTING.md) for the precise execution boundary;
no production router or customer tunnel was used.

## Installation

Requirements: Apache 2.4, PHP 8.2 or newer with PDO MySQL, cURL, mbstring and OpenSSL, MySQL/MariaDB,
and the Linux OpenVPN helpers for VPN connections. The installer discovers the installed PHP-FPM
handler. The access rules also support XAMPP's mod_php and deny private source/config/test paths.
Charts, icons and Leaflet are served locally; map tiles still need internet access.

For managed provisioning, configure the wildcard DNS/TLS certificate, Git access, the fleet secret
at `/root/smartolt_fleet_secret`, and the provisioner endpoint. `install.sh <sub>` creates a private
staging checkout, database, site configuration and five-minute cron job. It rejects unrelated
existing databases/accounts and validates Apache configuration before reporting success.
The initial account is `admin` / `admin` and requires a password change.

`backend/config/database.php` is installation-specific and ignored by Git. Keep it and the
encryption key when updating an existing installation. `install.sql` is the base schema;
`backend/lib/Schema.php` applies later upgrades automatically on application startup. Existing
timestamps retain their Nairobi storage convention; the selected timezone controls local date
filters, display and ticket input.

## API and verification

Use `Authorization: Bearer <token>`, where the token is
`hash_hmac('sha256', <subdomain>, <fleet secret>)`. Read operations permit GET; mutations require
POST. Query-string tokens are rejected. Never include the deployment's fleet secret in source.

[TESTING.md](TESTING.md) records repeatable integration/browser tests, concrete failure cases and
the remaining feature gaps compared with the supplied recording. The Windows runner creates a
new local MariaDB database and a disposable application copy; it never targets the site's existing
database configuration. Passing simulations does not certify a physical OLT, router or ONU.
