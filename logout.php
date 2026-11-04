<?php
// Match the session cookie params used by the rest of the app so the
// destroy targets the same session the user is logged in with.
$session_lifetime = 30 * 24 * 60 * 60;
session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

require_once __DIR__ . '/backend/auth/login.php';

$loginHandler = new LoginHandler();
$loginHandler->logout();

// Clear in-memory session data
$_SESSION = [];

// Remove the session cookie from the browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Strict',
        ]
    );
}

header('Location: index.php');
exit;
