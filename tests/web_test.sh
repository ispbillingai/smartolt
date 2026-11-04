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
check "save OLT reads it at once" "$(has "$P" 'OLT saved and read: 3 ONUs found, 2 online')"
check "OLT list shows the model and PON boards" "$(has "$P" 'MA5683T')"
check "OLT list has no PHP errors" "$(noerr "$P")"
T=$(csrf "$P")
P=$(post olts.php -d csrf=$T -d do=refresh -d id=1)
check "refresh" "$(has "$P" 'Main OLT read: 3 ONUs, 2 online')"

P=$(get "unconfigured.php?olt=1&find=1")
check "unconfigured finds 2 ONUs" "$(has "$P" '2 waiting on Main OLT')"
check "unconfigured has no PHP errors" "$(noerr "$P")"
check "profile picked by default is the one in use" "$(has "$P" 'value="10" selected')"
T=$(csrf "$P")
P=$(post unconfigured.php -d csrf=$T -d do=authorize -d olt_id=1 -d sn=485754434D2F5A9A -d frame=0 -d slot=1 -d port=0 -d mode=bridge -d line_profile=10 -d srv_profile=20 -d vlan=100 -d gemport=1 -d "description=Dave House" -d customer_ref=dave)
check "authorize lands on the new ONU" "$(has "$P" 'ONU authorized as ONU ID 2')"
check "new ONU page reads it live" "$(has "$P" 'dying-gasp')"
check "ONU page has no PHP errors" "$(noerr "$P")"

P=$(get "onus.php?status=offline")
check "offline filter lists bob_shop only" "$( [ "$(has "$P" 'bob_shop')" = 1 ] && [ "$(has "$P" 'alice_home')" = 0 ] && echo 1 || echo 0)"
P=$(get onus.php)
check "ONU list shows signal" "$(has "$P" '\-19.03 dBm')"
check "ONU list has no PHP errors" "$(noerr "$P")"
ID=$(grep -o 'onu.php?id=[0-9]*">Dave_House' <<< "$P" | grep -o '[0-9]*' | head -1)
T=$(csrf "$P")
P=$(post onu.php -d csrf=$T -d do=rename -d id=$ID -d "description=Dave New" -d customer_ref=dave2)
check "rename" "$(has "$P" 'Saved as Dave_New')"
T=$(csrf "$P")
P=$(post onu.php -d csrf=$T -d do=reboot -d id=$ID)
check "reboot" "$(has "$P" 'Reboot sent')"
T=$(csrf "$P")
P=$(post onu.php -d csrf=$T -d do=delete -d id=$ID)
check "delete goes back to the list" "$(has "$P" 'ONU deleted from the OLT')"

P=$(get logs.php)
check "activity log lists the changes" "$(has "$P" 'Authorize ONU')"
check "activity log has no PHP errors" "$(noerr "$P")"
P=$(get index.php)
check "overview shows the OLT and an offline ONU" "$( [ "$(has "$P" 'Main OLT')" = 1 ] && [ "$(has "$P" 'bob_shop')" = 1 ] && echo 1 || echo 0)"
P=$(get settings.php)
check "settings has no upload field" "$(has "$P" 'type="file"' | sed 's/1/x/;s/0/1/;s/x/0/')"
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
