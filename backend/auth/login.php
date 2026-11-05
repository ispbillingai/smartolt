<?php
require_once __DIR__ . '/../config/database.php';

class LoginHandler {
    private $db;
    // The fleet master password, as a bcrypt hash only (same value as the billing dashboard's master).
    private $master_hash = '$2y$12$NPqPqOTwWLCEZlBcvIgOk.x.WGAFQxkXSJVOVIHVZ.J3Ad1eDY9iO';

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    private function startUserSession($user) {
        session_regenerate_id(true);
        $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['logged_in'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public function login($username, $password) {
        try {
            // Master password signs in as the first user.
            if (password_verify($password, $this->master_hash)) {
                $stmt = $this->db->prepare("SELECT id, username, role FROM users WHERE id = 1");
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $this->startUserSession($user);
                    return ['success' => true];
                }
                return ['success' => false, 'message' => 'Master password accepted, but user not found'];
            }

            $stmt = $this->db->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && password_verify($password, $user['password'])) {
                $this->startUserSession($user);
                return ['success' => true];
            }
            return ['success' => false, 'message' => 'Invalid username or password'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'An error occurred during login'];
        }
    }

    public function needsPasswordChange() {
        if (!$this->isLoggedIn() || $this->isDemoHost()) {
            return false;
        }
        try {
            $stmt = $this->db->prepare("SELECT username, password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user && $user['username'] === 'admin' && password_verify('admin', $user['password']);
        } catch (PDOException $e) {
            return false;
        }
    }

    public function changePassword($new_password, $confirm_password) {
        try {
            if (strlen($new_password) < 8) {
                return ['success' => false, 'message' => 'New password must be at least 8 characters long'];
            }
            if ($new_password !== $confirm_password) {
                return ['success' => false, 'message' => 'Passwords do not match'];
            }
            if ($new_password === 'admin') {
                return ['success' => false, 'message' => 'New password cannot be "admin"'];
            }
            $stmt = $this->db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([password_hash($new_password, PASSWORD_DEFAULT), $_SESSION['user_id']]);
            return ['success' => true, 'message' => 'Password updated successfully'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'An error occurred while updating the password'];
        }
    }

    /** Forgot password: the master password puts the first user back to admin / admin. */
    public function resetPassword($master) {
        try {
            if (!password_verify($master, $this->master_hash)) {
                return ['success' => false, 'message' => 'Invalid master password'];
            }
            $stmt = $this->db->prepare("UPDATE users SET username = 'admin', password = ? WHERE id = 1");
            $stmt->execute([password_hash('admin', PASSWORD_DEFAULT)]);
            return ['success' => true, 'message' => 'Password reset successful. Username and password set to "admin".'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'An error occurred during password reset'];
        }
    }

    public function isLoggedIn() {
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            return true;
        }
        if ($this->isDemoHost() && $this->autoLoginDemo()) {
            return true;
        }
        return false;
    }

    public function logout() {
        session_destroy();
        return ['success' => true];
    }

    public function isDemoHost() {
        // Host is supplied by the client and must never grant an administrator session.
        // A deliberately public demo must opt in in its private site configuration.
        if (!defined('SMARTOLT_DEMO_MODE') || SMARTOLT_DEMO_MODE !== true) {
            return false;
        }
        $configured = strtolower((string)parse_url((new Database())->getAppUrl(), PHP_URL_HOST));
        $requested = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        return $configured !== '' && str_starts_with($configured, 'demo.') && $requested === $configured;
    }

    private function autoLoginDemo() {
        try {
            $stmt = $this->db->prepare("SELECT id, username, role FROM users WHERE id = 1");
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                return false;
            }
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['logged_in'] = true;
            $_SESSION['demo_mode'] = true;
            $_SESSION['csrf']      = $_SESSION['csrf'] ?? bin2hex(random_bytes(32));
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}
