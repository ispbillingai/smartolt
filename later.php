<?php
// Add ONU for later authorization: the serial of an ONU that is not plugged in yet, with everything
// it should get. The first read that sees it waiting authorizes it (see OltManager::runAuto).
require_once __DIR__ . '/backend/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $mgr->savePending($_POST);
        back('unconfigured.php', 'success', 'Saved. The ONU is authorized with these details as soon as an OLT sees it.');
    } catch (InvalidArgumentException $e) {
        $_SESSION['later_form'] = $_POST;
        back('later.php', 'error', $e->getMessage());
    }
}
$sel = $_SESSION['later_form'] ?? [];
unset($_SESSION['later_form'], $sel['csrf']);
$form = 'later';
$olts = $mgr->olts();

$pageTitle = 'Offline authorization ONU';
include __DIR__ . '/backend/templates/header.php';
?>
<div class="so-pagehead"><h1><i class="fa fa-clock-o"></i> Add ONU for later authorization</h1><?php echo btn('Back', 'grey', 'unconfigured.php', 'list'); ?></div>
<p class="so-muted" style="margin-top:0;font-size:14px">Enter an ONU before it is installed. When an OLT sees it waiting, SmartOLT authorizes it with these details within 5 minutes.</p>
<?php echo flash_messages(); ?>
<form method="POST" class="so-section so-form" style="max-width:none">
    <?php echo csrf_field(); ?>
    <?php include __DIR__ . '/backend/templates/onu_form.php'; ?>
    <div class="so-row"><label></label><div><button class="so-btn so-btn-green"><i class="fa fa-floppy-o"></i> Save</button> <a href="unconfigured.php" style="margin-left:10px">Cancel</a></div></div>
</form>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
