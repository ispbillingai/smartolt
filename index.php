<?php
// Set session cookie parameters before starting the session
$session_lifetime = 30 * 24 * 60 * 60; // 30 days
session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path' => '/',
    'domain' => '',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/auth/login.php';

// Initialize login handler
$loginHandler = new LoginHandler();

// Check if user is logged in
if (!$loginHandler->isLoggedIn()) {
    // If it's a login attempt
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['password'])) {
        $result = $loginHandler->login($_POST['username'], $_POST['password']);
        if (!$result['success']) {
            $error = $result['message'];
        } else {
            // Extend session lifetime if "Remember Me" is checked
            if (isset($_POST['remember_me'])) {
                setcookie(session_name(), session_id(), [
                    'expires' => time() + $session_lifetime,
                    'path' => '/',
                    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]);
            }
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    // Handle password reset
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
        $result = $loginHandler->resetPassword(trim($_POST['master_email'] ?? ''));
        if ($result['success']) {
            $success_message = $result['message'];
        } else {
            $error = $result['message'];
        }
    }

    // Show login form if not logged in
    include __DIR__ . '/backend/templates/login_form.php';
    exit;
}

// Check if the user needs to change their password
if ($loginHandler->needsPasswordChange()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password']) && isset($_POST['confirm_password'])) {
        $result = hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))
            ? $loginHandler->changePassword((string)$_POST['new_password'], (string)$_POST['confirm_password'])
            : ['success' => false, 'message' => 'Your session expired. Please try again.'];
        if ($result['success']) {
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        $error = $result['message'];
    }
    include __DIR__ . '/backend/templates/change_password_form.php';
    exit;
}

require_once __DIR__ . '/backend/bootstrap.php';

require __DIR__ . '/backend/templates/dashboard.php';
