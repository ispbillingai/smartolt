<?php
// Without a session the login check below always fails and bounces to index.php,
// which made Settings look "inaccessible". Start the session first.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/auth/login.php';

// Initialize login handler
$loginHandler = new LoginHandler();

// Check if user is logged in
if (!$loginHandler->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Validate form inputs
        $errors = [];
        $display_name = trim($_POST['display_name'] ?? '');
        $company_name = trim($_POST['company_name'] ?? '');
        $timezone = $_POST['timezone'] ?? '';
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $theme = $_POST['theme'] ?? 'light';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($display_name)) {
            $errors[] = "Display Name is required.";
        }
        if (!empty($new_password)) {
            if (strlen($new_password) < 8) {
                $errors[] = "New password must be at least 8 characters long.";
            }
            if ($new_password !== $confirm_password) {
                $errors[] = "Passwords do not match.";
            }
        }

        // Handle profile picture upload
        $profile_picture = null;
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $file_name = $_SESSION['user_id'] . '_' . time() . '.' . pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $file_path = $upload_dir . $file_name;
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $file_path)) {
                $profile_picture = '/uploads/' . $file_name;
            } else {
                $errors[] = "Failed to upload profile picture.";
            }
        }

        if (empty($errors)) {
            // Update user settings (excluding username)
            $stmt = $db->prepare("UPDATE users SET 
                timezone = ?,
                display_name = ?, 
                company_name = ?,
                email_notifications = ?,
                theme = ?,
                profile_picture = COALESCE(?, profile_picture)
                WHERE id = ?");
            
            $stmt->execute([
                $timezone,
                $display_name,
                $company_name,
                $email_notifications,
                $theme,
                $profile_picture,
                $_SESSION['user_id']
            ]);

            // If password is being updated
            if (!empty($new_password)) {
                $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$new_password, $_SESSION['user_id']]);
            }

            // Log the successful update
            error_log("Settings updated successfully for user ID: " . $_SESSION['user_id']);
            $_SESSION['success_message'] = "Settings updated successfully!";
            header('Location: settings.php');
            exit;
        } else {
            $error = implode(' ', $errors);
        }
    } catch (Exception $e) {
        error_log("Error updating settings for user ID " . $_SESSION['user_id'] . ": " . $e->getMessage());
        $error = "Error updating settings: " . $e->getMessage();
    }
}

// Get current user settings
try {
    $database = new Database();
    $db = $database->getConnection();
    $stmt = $db->prepare("SELECT username, timezone, display_name, company_name, email_notifications, theme, profile_picture FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching user settings for user ID " . $_SESSION['user_id'] . ": " . $e->getMessage());
    $error = "Error fetching user settings: " . $e->getMessage();
}

include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
?>

<!-- Main Content -->
<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <div class="mb-6">
            <h2 class="text-2xl md:text-3xl font-bold text-slate-900 dark:text-white tracking-tight">Settings</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Manage your profile, preferences, and security.</p>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="mb-4 p-4 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800/50 text-emerald-700 dark:text-emerald-300 rounded-xl flex items-center gap-2">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                <span><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="mb-4 p-4 bg-rose-50 dark:bg-rose-900/30 border border-rose-200 dark:border-rose-800/50 text-rose-700 dark:text-rose-300 rounded-xl flex items-center gap-2">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6 md:p-8 max-w-3xl">
            <form method="POST" enctype="multipart/form-data" class="space-y-6">
                <!-- Profile Picture -->
                <div>
                    <label for="profile_picture" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Profile Picture</label>
                    <div class="flex items-center gap-4">
                        <?php if (!empty($user['profile_picture'])): ?>
                            <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Picture" class="w-16 h-16 rounded-full object-cover ring-2 ring-slate-200 dark:ring-slate-700">
                        <?php else: ?>
                            <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-700 flex items-center justify-center text-slate-400 dark:text-slate-500 ring-2 ring-slate-200 dark:ring-slate-700">
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="profile_picture" id="profile_picture" accept="image/*"
                               class="block w-full text-sm text-slate-500 dark:text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-brand-50 dark:file:bg-brand-900/40 file:text-brand-700 dark:file:text-brand-300 hover:file:bg-brand-100 dark:hover:file:bg-brand-900/60 file:cursor-pointer cursor-pointer">
                    </div>
                </div>

                <!-- Display Name -->
                <div>
                    <label for="display_name" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Display Name</label>
                    <input type="text" name="display_name" id="display_name"
                           value="<?php echo htmlspecialchars($user['display_name'] ?? ''); ?>"
                           class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 px-3 py-2 shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition text-sm" required>
                </div>

                <!-- Username (read-only) -->
                <div>
                    <label for="username" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Username</label>
                    <input type="text" name="username" id="username"
                           value="<?php echo htmlspecialchars($user['username']); ?>"
                           class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800/80 text-slate-500 dark:text-slate-400 px-3 py-2 shadow-sm cursor-not-allowed text-sm" readonly>
                </div>

                <!-- Company Name -->
                <div>
                    <label for="company_name" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Company Name</label>
                    <input type="text" name="company_name" id="company_name"
                           value="<?php echo htmlspecialchars($user['company_name'] ?? ''); ?>"
                           class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 px-3 py-2 shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition text-sm">
                </div>

                <!-- Timezone -->
                <div>
                    <label for="timezone" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Timezone</label>
                    <select name="timezone" id="timezone"
                            class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 px-3 py-2 shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition text-sm">
                        <?php
                        $timezones = DateTimeZone::listIdentifiers();
                        foreach ($timezones as $tz) {
                            $selected = ($tz === ($user['timezone'] ?? 'Africa/Nairobi')) ? 'selected' : '';
                            echo "<option value=\"$tz\" $selected>$tz</option>";
                        }
                        ?>
                    </select>
                </div>

                <!-- Email Notifications -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Email Notifications</label>
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="email_notifications"
                               class="rounded border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-brand-600 shadow-sm focus:ring-2 focus:ring-brand-500/30 focus:ring-offset-0"
                               <?php echo ($user['email_notifications'] ?? 0) ? 'checked' : ''; ?>>
                        <span class="text-sm text-slate-600 dark:text-slate-400">Enable email notifications for device status changes</span>
                    </label>
                </div>

                <!-- Theme Selection -->
                <div>
                    <label for="theme" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Theme (saved preference)</label>
                    <select name="theme" id="theme"
                            class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 px-3 py-2 shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition text-sm">
                        <option value="light" <?php echo ($user['theme'] ?? 'light') === 'light' ? 'selected' : ''; ?>>Light</option>
                        <option value="dark" <?php echo ($user['theme'] ?? 'light') === 'dark' ? 'selected' : ''; ?>>Dark</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Tip: you can also flip the theme any time using the toggle in the top bar.</p>
                </div>

                <!-- New Password -->
                <div>
                    <label for="new_password" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">New Password <span class="font-normal text-slate-400 dark:text-slate-500">(leave blank to keep current)</span></label>
                    <input type="password" name="new_password" id="new_password"
                           class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 px-3 py-2 shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition text-sm">
                </div>

                <!-- Confirm Password -->
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Confirm New Password</label>
                    <input type="password" name="confirm_password" id="confirm_password"
                           class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 px-3 py-2 shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition text-sm">
                </div>

                <div class="pt-2">
                    <button type="submit"
                            class="inline-flex justify-center rounded-lg bg-brand-600 hover:bg-brand-700 py-2.5 px-5 text-sm font-medium text-white shadow-glow focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-slate-900 transition">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </main>

    <?php include __DIR__ . '/backend/templates/footer.php'; ?>
</div>