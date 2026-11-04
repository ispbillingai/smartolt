<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotspot AP Dashboard — Sign In</title>
    <link rel="icon" type="image/png" href="https://hotspots.co.ke/logo.png">
    <link rel="apple-touch-icon" href="https://hotspots.co.ke/logo.png">
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('hotspotap-theme');
                if (stored === 'light') {
                    document.documentElement.classList.remove('dark');
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
                            50: '#ecfdf5', 100: '#d1fae5', 200: '#a7f3d0', 300: '#6ee7b7',
                            400: '#34d399', 500: '#10b981', 600: '#059669', 700: '#047857',
                            800: '#065f46', 900: '#064e3b'
                        }
                    },
                    boxShadow: {
                        'glow': '0 10px 30px -10px rgba(16, 185, 129, 0.45)'
                    }
                }
            }
        };
    </script>
    <style>
        /* Flat, professional backdrop (no gradients) */
        .auth-bg { background-color: #f1f5f9; }
        html.dark .auth-bg { background-color: #0b1120; }
        .card { background-color: #ffffff; }
        html.dark .card { background-color: #0f172a; }
        /* subtle accent hairline at the top of the card */
        .card-accent { box-shadow: inset 0 2px 0 0 #10b981; }
    </style>
</head>
<body class="auth-bg min-h-screen flex items-center justify-center p-4 antialiased">
    <div class="w-full max-w-md">
        <div class="card card-accent border border-slate-200 dark:border-slate-800 rounded-2xl shadow-xl p-8">
            <!-- Brand mark -->
            <div class="flex flex-col items-center text-center mb-7">
                <div class="w-14 h-14 rounded-2xl bg-brand-500 flex items-center justify-center shadow-glow mb-4">
                    <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M4.222 12.404a11 11 0 0115.556 0M1 8.404a16.5 16.5 0 0122 0M12 20h.01"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-semibold text-slate-900 dark:text-white tracking-tight">Hotspot AP Dashboard</h1>
                <p class="mt-1.5 text-sm text-slate-500 dark:text-slate-400">Secure remote access to your access points</p>
            </div>

            <?php if (isset($error)): ?>
                <div class="mb-4 p-3 bg-rose-50 dark:bg-rose-900/30 border border-rose-200 dark:border-rose-800/50 text-rose-700 dark:text-rose-300 rounded-lg text-sm flex items-start gap-2">
                    <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($success_message)): ?>
                <div class="mb-4 p-3 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800/50 text-emerald-700 dark:text-emerald-300 rounded-lg text-sm flex items-start gap-2">
                    <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    <span><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <div id="login-form" class="<?php echo isset($_GET['reset']) ? 'hidden' : ''; ?>">
                <form class="space-y-4" method="POST">
                    <div>
                        <label for="username" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Username</label>
                        <input id="username" name="username" type="text" required
                               class="block w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800/60 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 px-3.5 py-2.5 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition"
                               placeholder="your username">
                    </div>
                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Password</label>
                        <input id="password" name="password" type="password" required
                               class="block w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800/60 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 px-3.5 py-2.5 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition"
                               placeholder="••••••••">
                    </div>

                    <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300 select-none">
                        <input id="remember_me" name="remember_me" type="checkbox"
                               class="rounded border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-brand-600 shadow-sm focus:ring-2 focus:ring-brand-500/30 focus:ring-offset-0">
                        Remember me
                    </label>

                    <button type="submit"
                            class="w-full inline-flex justify-center items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white py-2.5 px-4 text-sm font-semibold shadow-glow focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-slate-900 transition">
                        Sign in
                    </button>
                </form>
                <div class="text-center mt-5">
                    <a href="?reset=true" class="text-sm text-brand-600 dark:text-brand-400 hover:text-brand-500 font-medium transition">
                        Forgot Password?
                    </a>
                </div>
            </div>

            <!-- Password Reset Form -->
            <div id="reset-form" class="<?php echo !isset($_GET['reset']) ? 'hidden' : ''; ?>">
                <form class="space-y-4" method="POST">
                    <input type="hidden" name="reset_password" value="1">
                    <div>
                        <label for="master_email" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Master Email</label>
                        <input id="master_email" name="master_email" type="email" required
                               class="block w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800/60 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 px-3.5 py-2.5 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition"
                               placeholder="example@gmail.com">
                    </div>

                    <div class="flex gap-2">
                        <button type="submit"
                                class="flex-1 inline-flex justify-center items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white py-2.5 px-4 text-sm font-semibold shadow-glow focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-slate-900 transition">
                            Reset Password
                        </button>
                        <a href="?"
                           class="flex-1 inline-flex justify-center items-center rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 py-2.5 px-4 text-sm font-medium transition">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Powered by FreeISPRadius -->
        <div class="text-center mt-6">
            <p class="text-sm text-slate-500 dark:text-slate-400 flex items-center justify-center gap-2">
                Powered by FreeIspRadius
                <img src="https://demo.ispledger.com/ui/ui/images/logo.png" alt="FreeISPRadius" class="h-5">
            </p>
        </div>
    </div>
</body>
</html>
