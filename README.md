# SmartOLT

Each ISP's own OLT panel, at `https://<sub>.smartolt.ispledger.com`. Same family and look as the
ACS (TR-069), Hotspot AP and Device Access sites: its own login, its own database, installed per ISP
by `install.sh`. The billing dashboard's SmartOLT menu (radius `system/controllers/smartolt.php`)
calls this site's `api.php`; everything that touches an OLT lives here.

## Pages

| Page | What it does |
|---|---|
| `index.php` | Sign in, first sign in password change, then the overview: OLTs, ONUs, online, offline, weak signal, signal chart, busiest PON ports, offline ONUs |
| `olts.php` | Add, edit, remove an OLT; Refresh reads model, firmware, boards, profiles, VLANs and every ONU |
| `unconfigured.php` | ONUs the OLT sees but has not authorized; authorize with line and service profile, VLAN, GEM port, bridge or router mode |
| `onus.php` | Every authorized ONU with status and signal; filter by OLT, status, weak signal, search; reboot, delete |
| `onu.php` | One ONU read live: light levels, temperature, last down cause, uptime; rename, reboot, delete |
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
    backend/lib/OltManager.php      OLT passwords (AES-256-GCM), per OLT lock, activity log, full read, ONU actions
    backend/templates/              header, sidebar, footer, login and password forms, shared UI pieces
    tests/fake_olt.php              a pretend MA5683T on a TCP port
    tests/api_test.php, web_test.sh end to end tests against the fake OLT

Supported now: Huawei MA5683T, MA5608T, MA5680T over Telnet. Another vendor is another driver in
`backend/lib/` with the same public methods as `Huawei.php`.

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
