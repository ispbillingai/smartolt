#!/bin/bash
# Click through the panel like a person would. Needs the site served at $1 (php -S), a fresh database
# from install.sql and the fake OLT on 127.0.0.1:$2.
#   bash tests/web_test.sh http://127.0.0.1:8099 47023

B="${1:-http://127.0.0.1:8099}"
OLT_PORT="${2:-47023}"
J="$(mktemp)"
FAILS=0
check() { if [ "$2" = "1" ]; then echo "PASS $1"; else echo "FAIL $1"; FAILS=$((FAILS+1)); fi; }
has() { grep -q -- "$2" <<< "$1" && echo 1 || echo 0; }
hasnt() { grep -q -- "$2" <<< "$1" && echo 0 || echo 1; }
both() { [ "$1" = 1 ] && [ "$2" = 1 ] && echo 1 || echo 0; }
csrf() { grep -o 'name="csrf" value="[0-9a-f]*"' <<< "$1" | head -1 | grep -o '[0-9a-f]\{64\}'; }
get() { curl -s -b "$J" -c "$J" -L "$B/$1"; }
post() { curl -s -b "$J" -c "$J" -L "$B/$1" "${@:2}"; }
noerr() { grep -qiE 'fatal error|warning:|notice:|deprecated:|uncaught' <<< "$1" && echo 0 || echo 1; }

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
check "password changed, overview shows" "$(has "$P" 'Add your OLT to get started')"
check "overview has no PHP errors" "$(noerr "$P")"

P=$(get olts.php)
check "OLT form shows when there is no OLT" "$(has "$P" 'Save and Connect')"
P=$(post olts.php -d csrf=bad -d do=save -d name=X -d host=127.0.0.1 -d telnet_port=$OLT_PORT -d username=root -d password=admin123)
check "a form without the session token is refused" "$(has "$P" 'Your session expired')"
T=$(csrf "$(get olts.php)")
P=$(post olts.php -d csrf=$T -d do=save -d "name=Main OLT" -d host=127.0.0.1 -d telnet_port=$OLT_PORT -d username=root -d password=admin123)
check "save OLT reads it at once" "$(has "$P" 'OLT saved and read: 4 ONUs found, 2 online')"
check "OLT list shows the model and PON boards" "$(has "$P" 'MA5683T')"
check "OLT list has no PHP errors" "$(noerr "$P")"
T=$(csrf "$P")
P=$(post olts.php -d csrf=$T -d do=refresh -d id=1)
check "refresh" "$(has "$P" 'Main OLT read: 4 ONUs, 2 online')"

P=$(get index.php)
check "dashboard counts waiting ONUs" "$(has "$P" 'Waiting authorization')"
check "dashboard splits offline by cause" "$(has "$P" '<b>1</b> power fail · <b>1</b> signal loss')"
check "dashboard has no PHP errors" "$(noerr "$P")"
P=$(get "olt.php?id=1")
check "OLT page lists PON ports" "$(both "$(has "$P" '0/1/0')" "$(has "$P" '0/1/1')")"
check "OLT page shows the boards" "$(has "$P" 'H805GPFD')"
check "OLT page has no PHP errors" "$(noerr "$P")"

# Network setup
T=$(csrf "$(get setup.php)")
P=$(post setup.php -d csrf=$T -d do=save_zone -d tab=zones -d name=Kasarani)
check "zone added" "$(both "$(has "$P" 'Zone saved')" "$(has "$P" 'Kasarani')")"
T=$(csrf "$P")
P=$(post setup.php -d csrf=$T -d do=save_zone -d tab=zones -d name=Kasarani)
check "duplicate zone refused" "$(has "$P" 'already in use')"
T=$(csrf "$P")
P=$(post "setup.php" -d csrf=$T -d do=save_odb -d tab=odbs -d name=ODB-K1 -d zone_id=1 -d ports=8 -d lat=-1.2210 -d lng=36.8960)
check "ODB added" "$(has "$P" 'ODB saved')"
T=$(csrf "$P")
P=$(post setup.php -d csrf=$T -d do=save_speed -d tab=speeds -d "name=Home 10" -d down_mbps=10 -d up_mbps=5)
check "speed profile added" "$(both "$(has "$P" 'Speed profile saved')" "$(has "$P" '10 Mbps')")"
P=$(get "setup.php?tab=types")
check "ONU types come seeded" "$(has "$P" 'EG8145V5')"
check "setup has no PHP errors" "$(noerr "$P")"

P=$(get "unconfigured.php?olt=1")
check "unconfigured shows the last read without searching" "$(has "$P" '2 waiting on Main OLT')"
P=$(get "unconfigured.php?olt=1&find=1")
check "unconfigured finds 2 ONUs" "$(has "$P" '2 waiting on Main OLT')"
check "unconfigured has no PHP errors" "$(noerr "$P")"
check "profile picked by default is the one in use" "$(has "$P" 'value="10" selected')"
check "ONU type picked from the model" "$(has "$P" 'value="5" selected>EG8145V5')"
T=$(csrf "$P")
P=$(post unconfigured.php -d csrf=$T -d do=authorize -d olt_id=1 -d sn=485754434D2F5A9A -d frame=0 -d slot=1 -d port=0 -d mode=bridge -d line_profile=10 -d srv_profile=20 -d vlan=100 -d gemport=1 -d "description=Dave House" -d customer_ref=dave -d onu_type_id=5 -d speed_profile_id=1 -d zone_id=1 -d odb_id=1 -d odb_port=3 -d "address=Block 4" -d model=EG8145V5)
check "authorize lands on the new ONU" "$(has "$P" 'ONU authorized as ONU ID 2')"
check "new ONU page reads it live" "$(has "$P" 'What the OLT reports')"
check "new ONU keeps its speed" "$(has "$P" 'selected>Home 10')"
check "ONU page has no PHP errors" "$(noerr "$P")"
ID=$(grep -o 'name="id" value="[0-9]*"' <<< "$P" | head -1 | grep -o '[0-9]*')

T=$(csrf "$P")
P=$(post onu.php -d csrf=$T -d do=details -d id=$ID -d zone_id=1 -d onu_type_id=5 -d odb_id=1 -d odb_port=4 -d "address=Block 5" -d lat=-1.2211 -d lng=36.8961)
check "details saved" "$(both "$(has "$P" 'Details saved')" "$(has "$P" 'Block 5')")"
T=$(csrf "$P")
P=$(post onu.php -d csrf=$T -d do=details -d id=$ID -d lat=200 -d lng=1)
check "bad location refused" "$(has "$P" 'latitude and longitude')"
T=$(csrf "$P")
P=$(post onu.php -d csrf=$T -d do=service -d id=$ID -d vlan=200 -d mode=bridge -d speed_profile_id=1)
check "service changed on the OLT" "$(both "$(has "$P" 'Service changed on the OLT')" "$(has "$P" 'value="200"')")"
T=$(csrf "$P")
P=$(post onu.php -d csrf=$T -d do=disable -d id=$ID)
check "disable" "$(both "$(has "$P" 'ONU disabled')" "$(has "$P" '>Enable<')")"
T=$(csrf "$P")
P=$(post onu.php -d csrf=$T -d do=enable -d id=$ID)
check "enable" "$(has "$P" 'ONU enabled')"
check "status history records both" "$(both "$(has "$P" 'Disabled</span>')" "$(has "$P" '>enabled<')")"
T=$(csrf "$P")
P=$(post onu.php -d csrf=$T -d do=replace -d id=$ID -d new_sn=485754434D2F0001)
check "replace with a serial already in use is refused" "$(has "$P" 'already has that serial')"
T=$(csrf "$P")
P=$(post onu.php -d csrf=$T -d do=replace -d id=$ID -d new_sn=HWTC-AAAA0001)
check "replace serial" "$(has "$P" 'Serial changed to 48575443AAAA0001')"

P=$(get "onus.php?status=offline")
check "offline filter lists bob_shop, not alice" "$(both "$(has "$P" 'bob_shop')" "$(hasnt "$P" 'alice_home')")"
P=$(get "onus.php?status=los")
check "LOS filter lists erin_flat only" "$(both "$(has "$P" 'erin_flat')" "$(hasnt "$P" 'bob_shop')")"
P=$(get "onus.php?status=power_fail")
check "power fail filter lists bob_shop only" "$(both "$(has "$P" 'bob_shop')" "$(hasnt "$P" 'erin_flat')")"
P=$(get "onus.php?zone=1")
check "zone filter" "$(both "$(has "$P" 'Dave_House')" "$(hasnt "$P" 'alice_home')")"
P=$(get "onus.php?pon=0/1/1")
check "PON port filter" "$(both "$(has "$P" 'carol')" "$(hasnt "$P" 'alice_home')")"
P=$(get onus.php)
check "ONU list shows signal and distance" "$(both "$(has "$P" '\-19.03 dBm')" "$(has "$P" '1.25 km')")"
check "ONU list shows VLAN read from the OLT" "$(has "$P" 'VLAN 100')"
check "ONU list has no PHP errors" "$(noerr "$P")"
T=$(csrf "$P")
P=$(post onus.php -d csrf=$T -d do=bulk -d bulk_action=reboot -d "ids[]=1" -d "ids[]=3")
check "bulk reboot" "$(has "$P" 'Rebooted 2 ONUs')"
T=$(csrf "$P")
P=$(post onus.php -d csrf=$T -d do=bulk -d bulk_action=speed -d bulk_speed=1 -d "ids[]=1" -d "ids[]=2" -d "ids[]=3")
check "bulk speed" "$(has "$P" 'New speed set on 3 ONUs')"
P=$(get "onus.php?speed=1")
check "speed filter shows the ONUs moved" "$(both "$(has "$P" 'alice_home')" "$(has "$P" 'carol')")"
C=$(get "onus.php?export=csv")
check "CSV export" "$(both "$(has "$C" 'Serial number')" "$(has "$C" 'erin_flat')")"

T=$(csrf "$(get onus.php)")
P=$(post onus.php -d csrf=$T -d do=reboot -d id=$ID)
check "reboot" "$(has "$P" 'Reboot sent')"
T=$(csrf "$P")
P=$(post onu.php -d csrf=$T -d do=delete -d id=$ID)
check "delete goes back to the list" "$(has "$P" 'ONU deleted from the OLT')"

P=$(get map.php)
check "map shows the ODB" "$(has "$P" 'ODB-K1')"
check "map has no PHP errors" "$(noerr "$P")"
P=$(get logs.php)
check "activity log lists the changes" "$(both "$(has "$P" 'Authorize ONU')" "$(has "$P" 'Set speed on many ONUs')")"
check "activity log has no PHP errors" "$(noerr "$P")"
P=$(get index.php)
check "overview shows the OLT and an offline ONU" "$(both "$(has "$P" 'Main OLT')" "$(has "$P" 'bob_shop')")"
check "overview lists the latest changes" "$(has "$P" 'Latest changes')"
P=$(get settings.php)
check "settings has no upload field" "$(hasnt "$P" 'type="file"')"
T=$(csrf "$P")
P=$(post settings.php -d csrf=$T -d "display_name=Test ISP" -d company_name=TestNet -d timezone=Africa/Nairobi -d theme=dark)
check "settings saved" "$(has "$P" 'Settings updated successfully')"

curl -s -b "$J" -c "$J" -L "$B/logout.php" > /dev/null
P=$(get onus.php)
check "after logout pages need a sign in again" "$(has "$P" 'Manage your OLTs and every ONU on them')"
P=$(post index.php -d username=admin -d password=Fibre-Test-2026)
check "sign in with the new password" "$(has "$P" 'Overview')"

rm -f "$J"
[ $FAILS -eq 0 ] && echo -e "\nALL PASSED" || echo -e "\n$FAILS FAILED"
exit $FAILS
