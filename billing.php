<?php
require_once __DIR__ . '/backend/bootstrap.php';

$c = $mgr->counts();
$olts = $mgr->olts();

$pageTitle = 'Billing';
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo page_title('Billing', 'SmartOLT is part of your billing dashboard subscription. Pay and renew it there.'); ?>
<div class="so-half">
    <div><?php echo panel_open('This SmartOLT', '', 'credit-card'); ?>
        <dl class="so-dl">
            <dt>OLTs</dt><dd><?php echo count($olts); ?></dd>
            <dt>Authorized ONUs</dt><dd><?php echo (int)$c['total']; ?></dd>
            <dt>Online now</dt><dd><?php echo (int)$c['online']; ?></dd>
            <dt>Waiting authorization</dt><dd><?php echo (int)$c['waiting']; ?></dd>
        </dl>
    <?php echo panel_close(); ?></div>
    <div><?php echo panel_open('Your subscription', '', 'info-circle'); ?>
        <p style="margin-top:0">Your plan, invoices and payments are in your billing dashboard, under the SmartOLT menu. That is also where SmartOLT was turned on for you.</p>
        <p class="so-muted" style="margin-bottom:0">Need help? Use the support chat in your billing dashboard.</p>
    <?php echo panel_close(); ?></div>
</div>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
