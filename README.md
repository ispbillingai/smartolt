# SmartOLT

Each ISP's own OLT panel, at `https://<sub>.smartolt.ispledger.com`. Same family and look as the
ACS (TR-069), Hotspot AP and Device Access sites: its own login, its own database, installed per ISP
by `install.sh`. The billing dashboard's SmartOLT menu (radius `system/controllers/smartolt.php`)
calls this site's `api.php`; everything that touches an OLT lives here.

## Pages

| Page | What it does |
|---|---|
| `index.php` | Sign in, first sign in password change, then the overview: waiting authorization, online, offline split by power fail, signal loss (LOS) and other, low signal (warning, critical), 24 hour network status, latest changes |
| `olts.php`, `olt.php` | Add, edit, remove an OLT; the OLT page shows boards, every PON port (online, power fail, LOS, average and weakest signal), profiles, VLANs and speed tables |
| `unconfigured.php` | ONUs waiting for authorization (from the last 5 minute read, or Search Now); authorize with name, ONU type picked from the model, mode, VLAN, speed, zone, ODB and port, address |
| `onus.php` | Every authorized ONU: status with the down cause, signal, distance, VLAN, speed, zone and ODB; filter by OLT, PON port, status, zone, ODB, type, speed, VLAN; tick many to reboot, disable, enable or set speed; CSV export |
| `onu.php` | One ONU read live; signal graph for 7 days, status history; rename, change VLAN, speed or mode on the OLT, disable or enable, replace the ONU (new serial, same settings), reboot, delete; zone, ODB, address and GPS |
| `map.php` | ONUs and ODBs with a location on a map, coloured by status |
| `setup.php` | Zones, ODBs (splitter boxes), ONU types and speed profiles. A speed profile becomes an OLT traffic table the first time it is used on that OLT |
| `logs.php` | Every change, from this panel, from the billing dashboard and from the 5 minute read |
| `settings.php` | Profile and password |
| `api.php` | JSON API for the billing dashboard |
| `cron.php` | Command line only: reads every OLT every 5 minutes |

## Layout

    backend/auth/login.php          sign in, master password (bcrypt hash only), password reset
    backend/bootstrap.php           session, login gate, CSRF, flash messages for the signed in pages
    backend/config/database.php     written by install.sh, git ignored (databasecopy.php is the example)
    backend/lib/Telnet.php          Telnet client: option refusal, "More" paging, "{ <cr>|... }:" prompts
    backend/lib/Huawei.php          Huawei MA5600T series commands and output parsers
    backend/lib/OltManager.php      OLT passwords (AES-256-GCM), per OLT lock, activity log, full read, ONU actions, history, bulk actions
    backend/lib/Schema.php          database upgrades: install.sql is version 1, each later change runs once on the next page load
    backend/templates/              header, sidebar, footer, login and password forms, shared UI pieces
    tests/fake_olt.php              a pretend MA5683T on a TCP port
    tests/api_test.php, web_test.sh end to end tests against the fake OLT

Supported now: Huawei MA5683T, MA5608T, MA5680T over Telnet. Another vendor is another driver in
`backend/lib/` with the same public methods as `Huawei.php`.

The dashboard is an operations overview with measured ONU availability, polling freshness,
attention items, fleet connectivity, SNMP/VPN health and quick actions. Missing or stale readings
are labelled; availability is a current snapshot, not an SLA measurement.

## SNMP and traffic

Enable **read-only SNMPv2c** in an OLT's settings and enter its community and UDP port. The
community is encrypted at rest and omitted from public API rows. No PHP SNMP extension is needed.
The Uplink tab can test SNMP independently of Telnet and shows system identity/uptime, IF-MIB
Ethernet interfaces and traffic history. Cron polls enabled OLTs every five minutes. Traffic needs
two successful samples; resets, missing counters and ambiguous 32-bit wraps produce gaps. History
is kept for 30 days. SNMP failure does not overwrite Telnet connection health.

For direct OLTs, allow the monitoring server to reach the configured UDP port. For VPN OLTs,
re-paste the generated MikroTik commands after enabling/changing SNMP: UDP 2161 on the tunnel is
forwarded to the OLT's configured SNMP port. Telnet uses TCP 2323 on the tunnel. ONU optical levels
and provisioning still use the Huawei Telnet driver; SNMP write operations and traps are not implemented.

## VPN installation and upgrade

On the Linux VPN server run `sudo bash vpn/setup_openvpn.sh` from this updated checkout. It installs
the helper and status parser, preserves existing credentials/addresses, and restarts OpenVPN.
Fresh installations use private `10.253.0.0/16`; existing installations retain their network.
Then copy the updated commands from each OLT page to its MikroTik. Scripts explicitly enable the
OVPN client, restrict forwarding to the OLT services and return traffic through the corresponding
tunnel. Status checks require a running service and a fresh OpenVPN status file.

RouterOS 6/7 commands and server helpers are fixture-tested, not verified on physical RouterOS.
The legacy TLS setup does not authenticate the VPN server certificate on the router; trusted-CA
distribution and server-name verification remain deployment work before calling this hardened.

## Configuration backups

Use **Configuration backups** under Settings to capture, list, download or delete manual snapshots.
Configuration content is encrypted in the database and excluded from activity transcripts. Downloads
require an authenticated session and CSRF token. Back up the site's encryption key separately;
without it, saved passwords and configuration snapshots cannot be recovered. Restore and scheduled
backup execution are not implemented.

## Verification and remaining scope

See [TESTING.md](TESTING.md) for repeatable simulation tests and the feature comparison with the
supplied recording. Passing these tests does not establish firmware compatibility or full SmartOLT
feature parity. Schema upgrades apply automatically on the next application request.

## API auth

`Authorization: Bearer <token>` where `token = hash_hmac('sha256', <subdomain>, <fleet secret>)`.
The fleet secret is in `/root/smartolt_fleet_secret` on the SmartOLT box (install.sh copies it into
the site config) and in the billing dashboard controller. It is never in this repo.

## Install

One time on the SmartOLT box:

1. Apache with PHP 8 (php-fpm as in `.htaccess`), MySQL, `mod_ssl`.
2. Wildcard certificate for `*.smartolt.ispledger.com` in `/etc/letsencrypt/live/smartolt.ispledger.com/`.
3. `/root/smartolt_fleet_secret` with the fleet secret.
4. Git access to this private repo through `/root/.ssh`.
5. The provisioner at `https://install.smartolt.ispledger.com/install.php`, which creates the DNS record and runs
   `install.sh <sub>` (the dashboard's Turn On SmartOLT button calls it).

Per ISP: `sudo /root/install.sh <sub>` clones the repo to `/var/www/html/<sub>`, writes the config with a fresh
encryption key, creates the database from `install.sql`, adds the 5 minute cron and the Apache site.
First sign in is `admin` / `admin` and must be changed.

The OLT must be reachable from this box on its Telnet port: a public IP, or a port forward on the
ISP's router to the OLT management IP.

## Test

    php tests/fake_olt.php 47023
    php -S 127.0.0.1:8099                       (site folder, with a test backend/config/database.php)
    php tests/api_test.php http://127.0.0.1:8099/api.php 127.0.0.1 47023 <sub> <fleet secret>
    bash tests/web_test.sh http://127.0.0.1:8099 47023   (fresh database)

The fake OLT follows Huawei's documented output. Confirm on a real MA5683T before going live:
`display ont info 0 all` from config mode and `display ont optical-info <port> all` (older firmware
may lack the `all` form; the list then shows no levels, the ONU page still reads them one by one).
