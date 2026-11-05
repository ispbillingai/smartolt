<?php
require_once __DIR__ . '/backend/bootstrap.php';

$db = $mgr->db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $errors = [];
    $display_name = trim($_POST['display_name'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $timezone = in_array($_POST['timezone'] ?? '', DateTimeZone::listIdentifiers(), true) ? $_POST['timezone'] : 'Africa/Nairobi';
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    $theme = ($_POST['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($display_name === '') {
        $errors[] = "Display Name is required.";
    }
    if ($new_password !== '') {
        if (strlen($new_password) < 8) {
            $errors[] = "New password must be at least 8 characters long.";
        }
        if ($new_password !== $confirm_password) {
            $errors[] = "Passwords do not match.";
        }
    }

    if (!$errors) {
        $db->prepare("UPDATE users SET timezone = ?, display_name = ?, company_name = ?, email_notifications = ?, theme = ? WHERE id = ?")
            ->execute([$timezone, $display_name, $company_name, $email_notifications, $theme, $_SESSION['user_id']]);
        if ($new_password !== '') {
            $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($new_password, PASSWORD_DEFAULT), $_SESSION['user_id']]);
        }
        $_SESSION['success_message'] = "Settings updated successfully!";
        header('Location: settings.php');
        exit;
    }
    $error = implode(' ', $errors);
}

$stmt = $db->prepare("SELECT username, timezone, display_name, company_name, email_notifications, theme FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);


$pageTitle = 'General';
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo page_title('General', 'Your account on this SmartOLT: name, company, time zone and password.'); ?>
<?php if (isset($_SESSION['success_message'])): ?><div class="so-alert a-ok"><?php echo e($_SESSION['success_message']); unset($_SESSION['success_message']); ?></div><?php endif; ?>
<?php if (isset($error)): ?><div class="so-alert a-err"><?php echo e($error); ?></div><?php endif; ?>
<form method="POST" class="so-panel" style="max-width:720px">
    <div class="so-panel-b">
        <?php echo csrf_field(); ?>
        <div class="so-grid2">
            <div class="so-field"><label class="so-label" for="display_name">Display name</label><input type="text" name="display_name" id="display_name" value="<?php echo e($user['display_name'] ?? ''); ?>" class="so-input" required></div>
            <div class="so-field"><label class="so-label" for="username">Username</label><input type="text" id="username" value="<?php echo e($user['username']); ?>" class="so-input" readonly style="opacity:.7"></div>
            <div class="so-field"><label class="so-label" for="company_name">Company name</label><input type="text" name="company_name" id="company_name" value="<?php echo e($user['company_name'] ?? ''); ?>" class="so-input"></div>
            <div class="so-field"><label class="so-label" for="timezone">Time zone</label>
                <select name="timezone" id="timezone" class="so-select">
                    <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option value="<?php echo e($tz); ?>" <?php echo $tz === ($user['timezone'] ?? 'Africa/Nairobi') ? 'selected' : ''; ?>><?php echo e($tz); ?></option><?php endforeach; ?>
                </select></div>
            <div class="so-field"><label class="so-label" for="new_password">New password</label><input type="password" name="new_password" id="new_password" class="so-input" placeholder="Leave blank to keep the current one" autocomplete="new-password"></div>
            <div class="so-field"><label class="so-label" for="confirm_password">Confirm new password</label><input type="password" name="confirm_password" id="confirm_password" class="so-input" autocomplete="new-password"></div>
        </div>
        <p class="so-muted" style="font-size:14px;margin-top:0">Dark or light look: the half moon icon in the top bar.</p>
        <button type="submit" class="so-btn so-btn-blue">Save changes</button>
    </div>
</form>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
