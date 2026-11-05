<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartOLT · Sign in</title>
    <link rel="icon" type="image/svg+xml" href="assets/icon.svg">
    <script>try { if (localStorage.getItem('smartolt-theme') === 'light') document.documentElement.classList.add('light'); } catch(e) {}</script>
    <link rel="stylesheet" href="assets/app.css?v=<?php echo filemtime(__DIR__ . '/../../assets/app.css'); ?>">
</head>
<body class="so-auth">
<main class="so-auth-wrap">
    <section class="so-panel so-auth-card">
        <div class="so-auth-brand"><img src="assets/icon.svg" width="52" height="52" alt=""><h1>SmartOLT</h1><p>Manage your OLTs and every ONU on them</p></div>
        <?php if (isset($error)): ?><div class="so-alert a-err" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if (isset($success_message)): ?><div class="so-alert a-ok" role="status"><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <div id="login-form" class="<?php echo isset($_GET['reset']) ? 'hidden' : ''; ?>">
            <form method="POST">
                <div class="so-field"><label for="username" class="so-label">Username</label><input class="so-input" id="username" name="username" autocomplete="username" required placeholder="Your username"></div>
                <div class="so-field"><label for="password" class="so-label">Password</label><input class="so-input" id="password" name="password" type="password" autocomplete="current-password" required></div>
                <label class="so-check so-auth-remember"><input id="remember_me" name="remember_me" type="checkbox"> Remember me for 30 days</label>
                <button type="submit" class="so-btn so-btn-blue so-auth-submit">Sign in</button>
            </form>
            <p class="so-auth-links"><a href="?reset=true">Forgot Password?</a></p>
        </div>
        <div id="reset-form" class="<?php echo !isset($_GET['reset']) ? 'hidden' : ''; ?>">
            <form method="POST">
                <input type="hidden" name="reset_password" value="1">
                <div class="so-field"><label for="master_email" class="so-label">Master Password</label><input class="so-input" id="master_email" name="master_email" type="password" autocomplete="current-password" required></div>
                <div class="so-inline"><button type="submit" class="so-btn so-btn-blue">Reset Password</button><a class="so-btn so-btn-grey" href="?">Cancel</a></div>
            </form>
        </div>
    </section>
    <p class="so-auth-footer">Powered by FreeIspRadius</p>
</main>
</body>
</html>
