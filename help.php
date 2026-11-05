<?php
require_once __DIR__ . '/backend/bootstrap.php';

$pageTitle = 'User guide';
include __DIR__ . '/backend/templates/header.php';
$sections = [
    'start' => ['Getting started', [
        'Add your OLT under Settings, OLTs. If it has no public IP, choose "Through the SmartOLT VPN" and paste the commands it gives you into the MikroTik the OLT sits behind.',
        'SmartOLT logs in by Telnet and reads the OLT every 5 minutes: boards, profiles, VLANs, every ONU with its status, cause and signal, and the ONUs waiting for authorization.',
        'Set up Zones, Splitters, ONU types and Speed profiles under Settings. They are labels and limits you pick when you authorize; zones and splitters change nothing on the OLT.',
    ]],
    'authorize' => ['Authorizing ONUs', [
        'Unconfigured lists the ONUs plugged in but not authorized. Press Refresh to ask the OLT now. Authorize opens the form: type, mode, VLAN, speed, zone, splitter, name and location.',
        'Presets fill that form in one click. Auto actions authorize matching ONUs by themselves with a preset, every 5 minutes.',
        'Add ONU for later authorization: enter the serial of an ONU before it is installed; it is authorized as soon as an OLT sees it.',
        'After authorizing many ONUs, press the save icon in the top bar so the OLT keeps them after a restart.',
        'The service template records your intended handoff. Bridging sets the native VLAN on Ethernet port 1. Routing needs WAN settings provisioned by an ONU profile or its management system; selecting Routing does not create those settings.',
    ]],
    'onus' => ['Configured ONUs', [
        'Filter by OLT, board, port, zone, splitter, VLAN, type, speed and sector, and by the status and signal icons. B and R filter the saved service template, not a live check of the ONU gateway configuration.',
        'The list icon turns on ticking, for rebooting, disabling, enabling or setting the speed of many ONUs at once.',
        'An ONU page reads the ONU live: status, signal, distance, temperature. From there you change its service, reboot, resync, disable, replace it by a new serial or delete it.',
    ]],
    'outage' => ['Statuses and PON outage', [
        'Power fail: the ONU lost power (dying gasp). LOS: the light to the ONU is lost, usually a cut or bent fiber. Offline N/A: offline without a known cause.',
        'Full PON LOS: every ONU on a PON port is down with LOS, usually a feeder cut. Partial LOS: two or more LOS on one port, usually a splitter or a drop.',
        'Power fail on the dashboard lists ports with two or more power fails, usually an area power cut. Signal variations: ONUs whose light is 3 dB or more below their own week.',
        'Signal: below -27 dBm (or above -8) is critical, -25 to -27 is a warning.',
    ]],
    'reports' => ['Reports', [
        'Find config mismatches compares what the OLT has with what SmartOLT saved, for example a speed changed on the OLT by hand. Resync puts the saved setup back.',
        'Splitter check finds splitters with more ONUs than ports, ports used twice and ONUs from different PON ports on one splitter.',
        'Export and Import move ONU details in and out as CSV; Import matches by serial number.',
    ]],
    'monitoring' => ['SNMP and traffic', [
        'Enable read-only SNMPv2c in the OLT settings. Enter the community configured on the OLT and its UDP port. Use the Uplink tab to test the connection and see Ethernet interface counters.',
        'For a VPN connection, copy the generated MikroTik commands again after changing SNMP or OLT port settings. The tunnel carries the configured Telnet and SNMP connections.',
        'Interface rates need two valid reads. A reboot, unavailable counter or counter wrap may leave a gap. A failed SNMP read does not mean that Telnet is unavailable.',
        'Open an ONU and choose Open traffic monitor. Read counters once or start live readings for a selected Ethernet port. Export MAC table downloads learned addresses from the OLT.',
        'Ethernet traffic reports show the most recent collected port readings. Byte counters cover time since the ONU reset its counters; they are not billing usage for the selected period.',
    ]],
    'backups' => ['Configuration backups', [
        'Settings, Configuration backups can capture a snapshot from an OLT, download a saved snapshot or delete it. The application encrypts configuration content before storing it.',
        'Choose a schedule per OLT: every 6, 12 or 24 hours, or weekly. The polling job captures due backups and shows the latest result. A failed read leaves prior snapshots intact and retries on a later run.',
        'An OLT configuration save writes the running settings to the OLT itself. A backup snapshot is a separate copy in this application. Restoring a whole OLT configuration is not automated.',
    ]],
    'acs' => ['TR-069 and WiFi', [
        'Connect your FreeISP ACS under Settings, VPN & TR069. Use the ACS website URL and an API key. The connection requires HTTPS except for local test servers.',
        'The TR-069 button on an ONU opens its ACS record by matching the serial number. Read device fetches the latest ACS information. An unmatched serial stays unlinked.',
        'WiFi and LAN values are the parameters the ONU reported to its ACS, with the last read time. A missing signal value means it was not reported; it does not mean zero signal.',
        'Device changes sent through ACS are queued. Check the job result before assuming the ONU applied a WiFi change or reboot. The ONU must contact the ACS for queued work to run.',
    ]],
    'appearance' => ['Appearance and time', [
        'The half moon icon switches between light and dark themes across the dashboard and all pages. Your browser remembers the choice.',
        'Settings, General controls your display time zone. Reports, dates and scheduled ticket times use that zone. Existing stored timestamps remain compatible with older installations.',
        'The dashboard marks stale device readings. Its refresh switch reloads the overview; the server polling job performs the actual periodic device reads.',
    ]],
];
?>
<?php echo page_title('User guide', 'How SmartOLT works, page by page.'); ?>
<div class="so-two">
    <div>
        <?php foreach ($sections as $id => [$title, $points]): ?>
            <div class="so-panel" id="<?php echo $id; ?>"><div class="so-panel-h"><span><?php echo e($title); ?></span></div>
                <div class="so-panel-b"><ul style="margin:0;padding-left:18px"><?php foreach ($points as $p): ?><li style="margin-bottom:6px"><?php echo e($p); ?></li><?php endforeach; ?></ul></div></div>
        <?php endforeach; ?>
    </div>
    <div><?php echo panel_open('Contents', '', 'book'); ?>
        <?php foreach ($sections as $id => [$title]): ?><div style="padding:4px 0"><a href="#<?php echo $id; ?>"><?php echo e($title); ?></a></div><?php endforeach; ?>
    <?php echo panel_close(); ?></div>
</div>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
