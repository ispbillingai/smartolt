<!DOCTYPE html>
<html lang="en" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password — Hotspot AP</title>
    <link rel="icon" type="image/png" href="https://hotspots.co.ke/logo.png">
    <link rel="apple-touch-icon" href="https://hotspots.co.ke/logo.png">
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('lanaccess-theme');
                var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (stored === 'dark' || (!stored && prefersDark)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) { /* ignore */ }
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eef4ff', 100: '#dbe6ff', 200: '#bccfff', 300: '#8badff',
                            400: '#5b85ff', 500: '#3b63f6', 600: '#2547e0', 700: '#1d39b8',
                            800: '#1c3293', 900: '#1c2f75'
                        }
                    },
                    boxShadow: {
                        'glow': '0 0 0 1px rgba(59, 99, 246, 0.15), 0 8px 24px -4px rgba(59, 99, 246, 0.25)'
                    }
                }
            }
        };
    </script>
    <style>
        .auth-bg {
            background: linear-gradient(135deg, #1c2f75 0%, #2547e0 50%, #1d39b8 100%);
            background-size: 200% 200%;
            animation: bgShift 18s ease infinite;
        }
        html.dark .auth-bg {
            background: linear-gradient(135deg, #0f172a 0%, #1c3293 50%, #1d39b8 100%);
            background-size: 200% 200%;
            animation: bgShift 18s ease infinite;
        }
        @keyframes bgShift {
            0%   { background-position: 0% 50%; }
            50%  { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .glass {
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(14px);
        }
        html.dark .glass {
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(14px);
        }
    </style>
</head>
<body class="auth-bg min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="glass border border-white/30 dark:border-slate-700/60 rounded-2xl shadow-2xl p-8">
            <div class="flex flex-col items-center text-center mb-6">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700 flex items-center justify-center shadow-glow mb-3">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">Change Your Password</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Your current password is the default. Please set a new password.</p>
            </div>

            <?php if (isset($error)): ?>
                <div class="mb-4 p-3 bg-rose-50 dark:bg-rose-900/30 border border-rose-200 dark:border-rose-800/50 text-rose-700 dark:text-rose-300 rounded-lg text-sm flex items-start gap-2">
                    <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form class="space-y-4" method="POST">
                <div>
                    <label for="new_password" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">New Password</label>
                    <input id="new_password" name="new_password" type="password" required
                           class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/50 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 px-3 py-2.5 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition"
                           placeholder="Minimum 8 characters">
                </div>
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Confirm New Password</label>
                    <input id="confirm_password" name="confirm_password" type="password" required
                           class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/50 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 px-3 py-2.5 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition"
                           placeholder="Repeat your new password">
                </div>

                <button type="submit"
                        class="w-full inline-flex justify-center items-center rounded-lg bg-brand-600 hover:bg-brand-700 text-white py-2.5 px-4 text-sm font-medium shadow-glow focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-slate-900 transition">
                    Update Password
                </button>
            </form>
        </div>
    </div>
</body>
</html>
