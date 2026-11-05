#!/bin/bash
# Click through the panel like a person would. Needs the site served at $1 (php -S), a fresh database
# from install.sql and a freshly started fake OLT on 127.0.0.1:$2.
#   bash tests/web_test.sh http://127.0.0.1:8099 47023

B="${1:-http://127.0.0.1:8099}"
OLT_PORT="${2:-47023}"
J="$(mktemp)"
F="$(mktemp)"
# curl on Windows needs a Windows path for -F file uploads.
command -v cygpath >/dev/null && F="$(cygpath -m "$F")"
FAILS=0
check() { if [ "$2" = "1" ]; then echo "PASS $1"; else echo "FAIL $1"; FAILS=$((FAILS+1)); fi; }
has() { grep -q -- "$2" <<< "$1" && echo 1 || echo 0; }
hasnt() { grep -q -- "$2" <<< "$1" && echo 0 || echo 1; }
both() { [ "$1" = 1 ] && [ "$2" = 1 ] && echo 1 || echo 0; }
csrf() { grep -o 'name="csrf" value="[0-9a-f]*"' <<< "$1" | head -1 | grep -o '[0-9a-f]\{64\}'; }
get() { curl -s -b "$J" -c "$J" -L "$B/$1"; }
post() { curl -s -b "$J" -c "$J" -L "$B/$1" "${@:2}"; }
# PHP's own error output, not words like "Warning" that the pages use as labels.
noerr() { grep -qE '<b>(Fatal error|Warning|Notice|Deprecated)</b>:|Uncaught' <<< "$1" && echo 0 || echo 1; }
T() { csrf "$(get "$1")"; }

# ----- Sign in -----
P=$(get index.php)
check "signed out shows the sign in page" "$(has "$P" 'Manage your OLTs and every ONU on them')"
P=$(post index.php -d username=admin -d password=wrong)
check "wrong password is refused" "$(has "$P" 'Invalid username or password')"
P=$(post index.php -d username=admin -d password=admin)
check "first sign in asks for a new password" "$(has "$P" 'new_password')"
P=$(get olts.php)
check "pages stay closed until the password is changed" "$(has "$P" 'new_password')"
P=$(post index.php -d new_password=short -d confirm_password=short)
check "short password refused" "$(has "$P" 'at least 8 characters')"
P=$(post index.php -d new_password=Fibre-Test-2026 -d confirm_password=Fibre-Test-2026)
check "password changed, dashboard shows" "$(both "$(has "$P" 'Add your OLT to get started')" "$(has "$P" 'Waiting authorization')")"
check "top menu has every section" "$(both "$(has "$P" 'Find config mismatches (DB vs OLT)')" "$(has "$P" 'VPN &amp; TR069')")"
check "dashboard has no PHP errors" "$(noerr "$P")"

# ----- OLTs -----
P=$(get olts.php)
check "OLT form opens when there is no OLT" "$(both "$(has "$P" 'Save and connect')" "$(has "$P" 'so-modal show')")"
P=$(post olts.php -d csrf=bad -d do=save -d conn_type=direct -d name=X -d host=127.0.0.1 -d telnet_port=$OLT_PORT -d username=root -d password=admin123)
check "a form without the session token is refused" "$(has "$P" 'Your session expired')"
P=$(post olts.php -d csrf=$(T olts.php) -d do=save -d conn_type=direct -d "name=Main OLT" -d host=127.0.0.1 -d telnet_port=$OLT_PORT -d username=root -d password=admin123)
check "save OLT reads it at once" "$(has "$P" 'OLT saved and read: 4 ONUs found, 2 online')"
check "OLT list shows the model" "$(has "$P" 'Huawei MA5683T')"
check "OLT list has no PHP errors" "$(noerr "$P")"
P=$(post olts.php -d csrf=$(csrf "$P") -d do=refresh -d id=1)
check "refresh" "$(has "$P" 'Main OLT read: 4 ONUs, 2 online')"

P=$(get index.php)
check "dashboard card: 2 waiting, both new" "$(both "$(has "$P" '<b>2</b><span>Waiting authorization')" "$(has "$P" 'New: 2')")"
check "dashboard card: offline split by cause" "$(has "$P" 'PwrFail: 1 &nbsp;LoS: 1')"
check "dashboard OLT panel shows uptime and temperature" "$(has "$P" '3d 04:33, 45°C')"
check "dashboard PON outage rows" "$(both "$(has "$P" 'Full PON LOS')" "$(has "$P" 'Offline N/A')")"
check "dashboard has no PHP errors" "$(noerr "$P")"
P=$(get "olt.php?id=1")
check "OLT page lists PON ports and boards" "$(both "$(has "$P" '0/1/1')" "$(has "$P" 'H805GPFD')")"
check "OLT page has no PHP errors" "$(noerr "$P")"

# ----- Settings lists -----
P=$(post "catalog.php?k=zones" -d csrf=$(T "catalog.php?k=zones") -d do=save -d name=Kasarani)
check "zone added" "$(both "$(has "$P" 'Zone saved')" "$(has "$P" 'Kasarani')")"
P=$(post "catalog.php?k=zones" -d csrf=$(csrf "$P") -d do=save -d name=Kasarani)
check "duplicate zone refused" "$(has "$P" 'already in use')"
printf 'Name\nKasarani\nRuiru\n' > "$F"
P=$(post "catalog.php?k=zones" -F csrf=$(T "catalog.php?k=zones") -F do=import -F "file=@$F;type=text/csv")
check "zones import (existing one skipped)" "$(both "$(has "$P" 'Imported 1')" "$(has "$P" 'Ruiru')")"
C=$(get "catalog.php?k=zones&export=csv")
check "zones export" "$(both "$(has "$C" 'Name')" "$(has "$C" 'Ruiru')")"
P=$(get "catalog.php?k=zones&q=Ruiru")
check "zones search" "$(both "$(has "$P" 'Ruiru')" "$(hasnt "$P" '>Kasarani<')")"
P=$(post "catalog.php?k=sectors" -d csrf=$(T "catalog.php?k=sectors") -d do=save -d "name=Branch A" -d "comment=North reseller")
check "sector added" "$(both "$(has "$P" 'Sector saved')" "$(has "$P" 'North reseller')")"
P=$(post "catalog.php?k=odbs" -d csrf=$(T "catalog.php?k=odbs") -d do=save -d name=ODB-K1 -d zone_id=1 -d sector_id=1 -d ports=8 -d lat=-1.2210 -d lng=36.8960)
check "splitter added" "$(both "$(has "$P" 'Splitter saved')" "$(has "$P" 'ODB-K1')")"
P=$(post "catalog.php?k=speeds" -d csrf=$(T "catalog.php?k=speeds") -d do=save -d "name=Home 10" -d down_mbps=10 -d up_mbps=5)
check "speed profile added" "$(both "$(has "$P" 'Speed profile saved')" "$(has "$P" '10 Mbps')")"
P=$(get "catalog.php?k=types")
check "ONU types come seeded" "$(has "$P" 'EG8145V5')"
P=$(post "catalog.php?k=presets" -d csrf=$(T "catalog.php?k=presets") -d do=save -d name=Home -d mode=bridge -d vlan=100 -d speed_profile_id=1 -d zone_id=1 -d gemport=1)
check "preset added" "$(both "$(has "$P" 'Preset saved')" "$(has "$P" 'OLT default')")"
check "settings lists have no PHP errors" "$(noerr "$P")"

# ----- Unconfigured and authorize -----
P=$(get unconfigured.php)
check "unconfigured shows the last read without asking the OLT" "$(both "$(has "$P" '485754434D2F5A9A')" "$(has "$P" 'Last scan')")"
P=$(get "unconfigured.php?refresh=1")
check "refresh asks the OLT again" "$(both "$(has "$P" '5A544547C0FFEE01')" "$(has "$P" 'seconds ago')")"
check "unconfigured has no PHP errors" "$(noerr "$P")"
P=$(get "authorize.php?olt=1&sn=485754434D2F5A9A")
check "authorize form picks the profile in use" "$(has "$P" 'value="10" selected')"
check "authorize form picks the ONU type from the model" "$(has "$P" 'value="5" selected>EG8145V5')"
check "authorize form offers presets" "$(has "$P" '>Home</option>')"
P=$(get "authorize.php?olt=1&sn=485754434D2F9999")
check "authorize for an ONU no longer waiting goes back" "$(has "$P" 'no longer waiting')"
P=$(post authorize.php -d csrf=$(T "authorize.php?olt=1&sn=485754434D2F5A9A") -d olt_id=1 -d sn=485754434D2F5A9A -d frame=0 -d slot=1 -d port=0 -d mode=bridge -d line_profile=10 -d srv_profile=20 -d vlan=100 -d gemport=1 -d "description=Dave House" -d customer_ref=dave -d onu_type_id=5 -d speed_profile_id=1 -d zone_id=1 -d sector_id=1 -d odb_id=1 -d odb_port=3 -d "address=Block 4" -d model=EG8145V5 -d lat=-1.2212 -d lng=36.8962)
check "authorize lands on the new ONU" "$(both "$(has "$P" 'ONU authorized as ONU ID 2')" "$(has "$P" 'Show running-config')")"
check "new ONU keeps speed, sector and location" "$(both "$(has "$P" 'Home 10')" "$(both "$(has "$P" 'Branch A')" "$(has "$P" '-1.2212')")")"
check "ONU page has no PHP errors" "$(noerr "$P")"
ID=$(grep -o 'name="id" value="[0-9]*"' <<< "$P" | head -1 | grep -o '[0-9]*')

# ----- ONU page -----
P=$(get "onu.php?id=$ID")
check "ONU page reads it live" "$(both "$(has "$P" 'What the OLT reports')" "$(has "$P" 'INTERNET_100')")"
P=$(post onu.php -d csrf=$(csrf "$P") -d do=details -d id=$ID -d zone_id=1 -d onu_type_id=5 -d odb_id=1 -d odb_port=4 -d "address=Block 5" -d lat=-1.2211 -d lng=36.8961)
check "details saved" "$(both "$(has "$P" 'Details saved')" "$(has "$P" 'Block 5')")"
P=$(post onu.php -d csrf=$(csrf "$P") -d do=details -d id=$ID -d lat=200 -d lng=1)
check "bad location refused" "$(has "$P" 'latitude and longitude')"
P=$(post onu.php -d csrf=$(csrf "$P") -d do=rename -d id=$ID -d "description=Dave Home" -d customer_ref=dave2 -d zone_id=1 -d odb_id=1 -d odb_port=4)
check "rename on the OLT" "$(both "$(has "$P" 'Saved as Dave_Home')" "$(has "$P" 'dave2')")"
P=$(post onu.php -d csrf=$(csrf "$P") -d do=service -d id=$ID -d vlan=200 -d mode=bridge -d speed_profile_id=1)
check "service changed on the OLT" "$(both "$(has "$P" 'Service changed on the OLT')" "$(has "$P" 'value="200"')")"
P=$(post onu.php -d csrf=$(csrf "$P") -d do=config -d id=$ID)
check "running config from the OLT" "$(both "$(has "$P" 'Running config on the OLT')" "$(has "$P" 'vlan 200 gpon 0/1/0 ont 2')")"
P=$(post onu.php -d csrf=$(csrf "$P") -d do=resync -d id=$ID)
check "resync config" "$(has "$P" 'put on the OLT again')"
P=$(post onu.php -d csrf=$(csrf "$P") -d do=disable -d id=$ID)
check "disable" "$(both "$(has "$P" 'ONU disabled')" "$(has "$P" 'Enable ONU')")"
P=$(post onu.php -d csrf=$(csrf "$P") -d do=enable -d id=$ID)
check "enable" "$(has "$P" 'ONU enabled')"
check "status history records both" "$(both "$(has "$P" '>Disabled</span>')" "$(has "$P" '<td>enabled</td>')")"
P=$(post onu.php -d csrf=$(csrf "$P") -d do=replace -d id=$ID -d new_sn=485754434D2F0001)
check "replace with a serial already in use is refused" "$(has "$P" 'already has that serial')"
P=$(post onu.php -d csrf=$(csrf "$P") -d do=replace -d id=$ID -d new_sn=HWTC-AAAA0001)
check "replace serial" "$(has "$P" 'Serial changed to 48575443AAAA0001')"

# ----- Configured list -----
P=$(get "onus.php?status=other")
check "offline N/A filter is empty (erin is LOS, bob power fail)" "$(both "$(hasnt "$P" 'erin_flat')" "$(hasnt "$P" 'bob_shop')")"
P=$(get "onus.php?status=los")
check "LOS filter lists erin_flat only" "$(both "$(has "$P" 'erin_flat')" "$(hasnt "$P" 'bob_shop')")"
P=$(get "onus.php?status=power_fail")
check "power fail filter lists bob_shop only" "$(both "$(has "$P" 'bob_shop')" "$(hasnt "$P" 'erin_flat')")"
P=$(get "onus.php?zone=1")
check "zone filter" "$(both "$(has "$P" 'Dave_Home')" "$(hasnt "$P" 'alice_home')")"
P=$(get "onus.php?sector=1")
check "sector filter" "$(both "$(has "$P" 'Dave_Home')" "$(hasnt "$P" 'carol')")"
P=$(get "onus.php?board=1&port=1")
check "board and port filter" "$(both "$(has "$P" 'carol')" "$(hasnt "$P" 'alice_home')")"
P=$(get "onus.php?pon=0/1/1")
check "PON port filter" "$(both "$(has "$P" 'carol')" "$(hasnt "$P" 'alice_home')")"
P=$(get "onus.php?signal=good")
check "signal filter" "$(both "$(has "$P" 'alice_home')" "$(hasnt "$P" 'erin_flat')")"
P=$(get "onus.php?q=dave2")
check "search by external ID" "$(both "$(has "$P" 'Dave_Home')" "$(hasnt "$P" 'carol')")"
P=$(get onus.php)
check "ONU list shows place, signal and VLAN" "$(both "$(has "$P" 'gpon-onu_0/1/0:0')" "$(both "$(has "$P" '<b>-19.03</b>')" "$(has "$P" '<td>100</td>')")")"
check "ONU list counts" "$(has "$P" 'ONUs of 5 displayed')"
check "ONU list has no PHP errors" "$(noerr "$P")"
P=$(get "onus.php?select=1")
check "bulk mode shows tick boxes" "$(both "$(has "$P" 'id="tickAll"')" "$(has "$P" 'Set speed profile')")"
P=$(post onus.php -d csrf=$(csrf "$P") -d do=bulk -d bulk_action=reboot -d "ids[]=1" -d "ids[]=3")
check "bulk reboot" "$(has "$P" 'Rebooted 2 ONUs')"
P=$(post onus.php -d csrf=$(csrf "$P") -d do=bulk -d bulk_action=speed -d bulk_speed=1 -d "ids[]=1" -d "ids[]=2" -d "ids[]=3")
check "bulk speed" "$(has "$P" 'New speed set on 3 ONUs')"
P=$(get "onus.php?speed=1")
check "speed filter shows the ONUs moved" "$(both "$(has "$P" 'alice_home')" "$(has "$P" 'carol')")"

# ----- Reports -----
C=$(get "reports.php?r=export&download=1")
check "export CSV" "$(both "$(has "$C" 'Serial number')" "$(has "$C" 'erin_flat')")"
C=$(get "reports.php?r=export&download=1&status=los")
check "export keeps the filters" "$(both "$(has "$C" 'erin_flat')" "$(hasnt "$C" 'alice_home')")"
printf 'Serial number,Zone,Splitter,Splitter port,Address\n485754434D2F0001,Kasarani,ODB-K1,4,Plot 9\n485754434D2F0002,Nowhere,,,\n' > "$F"
P=$(post reports.php -F csrf=$(T "reports.php?r=import") -F do=import -F "file=@$F;type=text/csv")
check "import updates ONUs by serial" "$(both "$(has "$P" 'Updated 1 ONU')" "$(has "$P" 'no zone called Nowhere')")"
P=$(get "onus.php?zone=1")
check "imported zone shows in the list" "$(has "$P" 'alice_home')"
P=$(get "reports.php?r=splitters")
check "splitter check finds port 4 given twice" "$(has "$P" 'Port 4 is given to')"
P=$(get "reports.php?r=auth")
check "authorizations report" "$(both "$(has "$P" '485754434D2F5A9A')" "$(has "$P" 'admin')")"
P=$(get "reports.php?r=status&state=disabled")
check "status changes report" "$(both "$(has "$P" 'Dave_Home')" "$(hasnt "$P" 'bob_shop')")"
P=$(get "reports.php?r=pon")
check "PON occupancy report" "$(both "$(has "$P" '0/1/0')" "$(has "$P" '/ 128')")"
for r in traffic wifi mismatch; do P=$(get "reports.php?r=$r"); check "report $r has no PHP errors" "$(noerr "$P")"; done
# A speed changed in SmartOLT but not on the OLT is a mismatch until resynced.
P=$(post "catalog.php?k=speeds" -d csrf=$(T "catalog.php?k=speeds") -d do=save -d id=1 -d "name=Home 10" -d down_mbps=20 -d up_mbps=5)
P=$(post olts.php -d csrf=$(T olts.php) -d do=refresh -d id=1)
P=$(get "reports.php?r=mismatch")
check "mismatch report finds the old speed on the OLT" "$(has "$P" 'Speed on the OLT is 10 Mbps down')"
P=$(get index.php)
check "dashboard counts them under Resync" "$(has "$P" 'Resync: [1-9]')"
P=$(post reports.php -d csrf=$(T "reports.php?r=mismatch") -d do=resync -d id=1)
check "resync from the report" "$(has "$P" 'were put on the OLT')"

# ----- Later authorization and auto actions -----
P=$(post unconfigured.php -d csrf=$(T unconfigured.php) -d do=later -d sn=5A544547C0FFEE01 -d preset_id=1 -d "description=Later Guy" -d zone_id=1)
check "add ONU for later authorization" "$(both "$(has "$P" 'authorized with these details')" "$(has "$P" 'Added for later')")"
P=$(post unconfigured.php -d csrf=$(csrf "$P") -d do=later -d sn=485754434D2F0001)
check "later refuses an ONU already authorized" "$(has "$P" 'already authorized')"
P=$(get "unconfigured.php?refresh=1")
check "the later ONU is authorized on the next scan" "$(has "$P" 'Auto actions authorized 1 ONU')"
P=$(get "onus.php?q=Later")
check "it carries the later details" "$(both "$(has "$P" 'Later_Guy')" "$(has "$P" 'Kasarani')")"
# Deleting Dave puts its ONU back in autofind; an auto action then authorizes it again.
P=$(post onu.php -d csrf=$(T "onu.php?id=$ID&live=0") -d do=delete -d id=$ID)
check "delete goes back to the list" "$(has "$P" 'ONU deleted from the OLT')"
P=$(get "unconfigured.php?refresh=1")
check "deleted ONU waits again, marked as authorized before" "$(both "$(has "$P" '48575443AAAA0001')" "$(has "$P" 'Authorized before')")"
P=$(get index.php)
check "dashboard D counts it" "$(has "$P" 'D: 1')"
P=$(post auto_actions.php -d csrf=$(T auto_actions.php) -d do=save -d olt_id=1 -d pon=0/9/9 -d preset_id=1 -d enabled=1)
P=$(get "unconfigured.php?refresh=1")
check "an auto action for another port does nothing" "$(hasnt "$P" 'Auto actions authorized')"
P=$(post auto_actions.php -d csrf=$(T auto_actions.php) -d do=save -d pon=0/1/0 -d preset_id=1 -d enabled=1)
check "auto action added" "$(both "$(has "$P" 'Auto action saved')" "$(has "$P" '0/1/0')")"
P=$(post auto_actions.php -d csrf=$(csrf "$P") -d do=auto_toggle)
check "stop auto actions" "$(has "$P" 'Auto actions stopped')"
P=$(get "unconfigured.php?refresh=1")
check "stopped auto actions authorize nothing" "$(both "$(hasnt "$P" 'Auto actions authorized')" "$(has "$P" 'Start auto actions')")"
P=$(post unconfigured.php -d csrf=$(csrf "$P") -d do=auto_toggle)
check "start auto actions" "$(has "$P" 'Auto actions started')"
P=$(get "unconfigured.php?refresh=1")
check "auto action authorizes the waiting ONU" "$(has "$P" 'Auto actions authorized 1 ONU')"
P=$(get tasks.php)
check "task history shows the auto action" "$(both "$(has "$P" 'Auto action')" "$(has "$P" 'Set speed on many ONUs')")"

# ----- Diag, save, events, other pages -----
P=$(post diag.php -d csrf=$(T diag.php) -d olt_id=1 -d check=version)
check "diag runs a display command" "$(has "$P" 'MA5600V800R018C10')"
P=$(post diag.php -d csrf=$(csrf "$P") -d olt_id=1 -d check=summary -d frame=0 -d slot=1 -d port=0)
check "diag with a PON port" "$(has "$P" 'In port 0/1/0')"
P=$(post diag.php -d csrf=$(csrf "$P") -d olt_id=1 -d check=onu -d frame=0 -d slot=1 -d port=x)
check "diag refuses a parameter that is not a number" "$(has "$P" 'Fill in port as a number')"
P=$(post save.php -d csrf=$(T index.php) -d olt_id=0 -d back=/olts.php)
check "save the OLT configuration" "$(both "$(has "$P" 'Configuration saved on Main OLT')" "$(has "$P" '<h1>OLTs</h1>')")"
P=$(post save.php -d csrf=$(T index.php) -d olt_id=0 -d back=https://evil.example/x)
check "save never sends you to another site" "$(has "$P" 'Waiting authorization')"
P=$(get "events.php?action=olt.save")
check "events filter by action" "$(both "$(has "$P" '<td>Save OLT configuration</td>')" "$(hasnt "$P" '<td>Authorize ONU</td>')")"
P=$(get "events.php?user=admin")
check "events filter by user" "$(has "$P" '<td>admin</td>')"
P=$(get "events.php?from=2001-01-01&to=2001-01-02")
check "events with no rows" "$(has "$P" 'No info available')"
P=$(get "map.php?layer=odb")
check "splitters map shows the splitter" "$(has "$P" 'ODB-K1')"
for p in map.php graphs.php "graphs.php?olt=1&range=168" vpn.php billing.php help.php tasks.php auto_actions.php "catalog.php?k=presets" "catalog.php?k=sectors"; do
    P=$(get "$p"); check "$p has no PHP errors" "$(noerr "$P")"
done
P=$(get logs.php)
check "old activity log link opens Events" "$(has "$P" 'No info available\|<td>Authorize ONU</td>')"
P=$(get "setup.php?tab=speeds")
check "old network setup link opens the speed profiles" "$(has "$P" '<h1>Speed profiles</h1>')"
P=$(post "catalog.php?k=zones" -d csrf=$(T "catalog.php?k=zones") -d do=save -d name=Unused)
P=$(post "catalog.php?k=zones" -d csrf=$(csrf "$P") -d do=unused)
check "delete unused zones keeps the one in use" "$(both "$(has "$P" 'Removed 2 not used')" "$(both "$(hasnt "$P" '>Unused<')" "$(has "$P" 'Kasarani')")")"
P=$(get index.php)
check "dashboard Info lists the latest changes" "$(has "$P" 'configuration saved by admin')"
P=$(get settings.php)
check "settings has no upload field" "$(hasnt "$P" 'type="file"')"
P=$(post settings.php -d csrf=$(csrf "$P") -d "display_name=Test ISP" -d company_name=TestNet -d timezone=Africa/Nairobi)
check "settings saved" "$(has "$P" 'Settings updated successfully')"

curl -s -b "$J" -c "$J" -L "$B/logout.php" > /dev/null
P=$(get onus.php)
check "after logout pages need a sign in again" "$(has "$P" 'Manage your OLTs and every ONU on them')"
P=$(post index.php -d username=admin -d password=Fibre-Test-2026)
check "sign in with the new password" "$(has "$P" 'Waiting authorization')"

rm -f "$J" "$F"
[ $FAILS -eq 0 ] && echo -e "\nALL PASSED" || echo -e "\n$FAILS FAILED"
exit $FAILS
