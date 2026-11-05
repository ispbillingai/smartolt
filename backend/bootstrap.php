<?php
// Included first by every signed in page: session, login gate, CSRF, flash messages and the OLT layer.

$session_lifetime = 30 * 24 * 60 * 60; // 30 days, same as the login page
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $session_lifetime,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/auth/login.php';
require_once __DIR__ . '/lib/OltManager.php';
require_once __DIR__ . '/templates/ui.php';

$loginHandler = new LoginHandler();
if (!$loginHandler->isLoggedIn() || $loginHandler->needsPasswordChange()) {
    header('Location: index.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$mgr = new OltManager();
$mgr->user = (string)($_SESSION['username'] ?? '');

/** Stop a POST that did not come from our own form. */
function require_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        flash('error', 'Your session expired. Please try again.');
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

/** Send the browser back with a message (POST, redirect, GET). */
function back(string $url, string $type, string $message): void
{
    flash($type, $message);
    header('Location: ' . $url);
    exit;
}
