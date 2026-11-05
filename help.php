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
    ]],
    'onus' => ['Configured ONUs', [
        'Filter by OLT, board, port, zone, splitter, VLAN, type, speed and sector, and by the status and signal icons. B and R show bridge or router ONUs.',
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
