<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password · SmartOLT</title>
    <link rel="icon" type="image/svg+xml" href="assets/icon.svg">
    <script>try { if (localStorage.getItem('smartolt-theme') === 'light') document.documentElement.classList.add('light'); } catch(e) {}</script>
    <link rel="stylesheet" href="assets/app.css?v=<?php echo filemtime(__DIR__ . '/../../assets/app.css'); ?>">
</head>
<body class="so-auth">
<main class="so-auth-wrap"><section class="so-panel so-auth-card">
    <div class="so-auth-brand"><img src="assets/icon.svg" width="52" height="52" alt=""><h1>Change Your Password</h1><p>Your current password is the default. Please set a new password.</p></div>
    <?php if (isset($error)): ?><div class="so-alert a-err" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <form method="POST">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8'); ?>">
        <div class="so-field"><label class="so-label" for="new_password">New Password</label><input class="so-input" id="new_password" name="new_password" type="password" autocomplete="new-password" required minlength="8" placeholder="Minimum 8 characters"></div>
        <div class="so-field"><label class="so-label" for="confirm_password">Confirm New Password</label><input class="so-input" id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required minlength="8" placeholder="Repeat your new password"></div>
        <button type="submit" class="so-btn so-btn-blue so-auth-submit">Update Password</button>
    </form>
</section></main>
</body>
</html>
