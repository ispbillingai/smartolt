<!-- backend/templates/header.php -->
<!DOCTYPE html>
<html lang="en" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotspot AP Dashboard</title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://hotspots.co.ke/logo.png">
    <link rel="apple-touch-icon" href="https://hotspots.co.ke/logo.png">

    <!-- Tailwind config must come before the CDN runtime -->
    <script>
        // Apply persisted theme before Tailwind paints to avoid FOUC
        (function () {
            try {
                var stored = localStorage.getItem('lanaccess-theme');
                var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (stored === 'dark' || (!stored && prefersDark)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) { /* localStorage unavailable */ }
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
                            50:  '#eef4ff',
                            100: '#dbe6ff',
                            200: '#bccfff',
                            300: '#8badff',
                            400: '#5b85ff',
                            500: '#3b63f6',
                            600: '#2547e0',
                            700: '#1d39b8',
                            800: '#1c3293',
                            900: '#1c2f75'
                        }
                    },
                    boxShadow: {
                        'soft': '0 4px 20px -2px rgba(15, 23, 42, 0.06)',
                        'glow': '0 0 0 1px rgba(59, 99, 246, 0.15), 0 8px 24px -4px rgba(59, 99, 246, 0.25)'
                    }
                }
            }
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* Smooth color transitions when toggling theme */
        html.theme-transition,
        html.theme-transition *,
        html.theme-transition *:before,
        html.theme-transition *:after {
            transition: background-color 200ms ease, border-color 200ms ease, color 200ms ease, fill 200ms ease, stroke 200ms ease !important;
            transition-delay: 0 !important;
        }
        /* Subtle scrollbar styling in dark mode */
        html.dark ::-webkit-scrollbar { width: 10px; height: 10px; }
        html.dark ::-webkit-scrollbar-track { background: #1f2937; }
        html.dark ::-webkit-scrollbar-thumb { background: #374151; border-radius: 8px; }
        html.dark ::-webkit-scrollbar-thumb:hover { background: #4b5563; }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-slate-100 min-h-screen antialiased">
    <!-- Header -->
    <header class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800 fixed w-full z-40 shadow-soft">
        <div class="mx-auto px-4 md:px-6 py-3 flex justify-between items-center">
            <div class="flex items-center space-x-3">
                <!-- Hamburger Menu Button (Visible on Mobile) -->
                <button id="hamburgerMenu" class="md:hidden text-slate-600 dark:text-slate-300 hover:text-brand-600 dark:hover:text-brand-400 focus:outline-none p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path>
                    </svg>
                </button>
                <div class="flex items-center gap-2">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 flex items-center justify-center shadow-glow">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V7a2 2 0 012-2h14a2 2 0 012 2v3a2 2 0 01-2 2M5 12a2 2 0 00-2 2v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                        </svg>
                    </div>
                    <h1 class="text-lg md:text-xl font-bold text-slate-800 dark:text-slate-100 tracking-tight">Hotspot AP Dashboard</h1>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="hidden sm:inline text-sm text-slate-500 dark:text-slate-400 px-3"><?php echo date('F j, Y'); ?></span>
                <!-- Theme toggle -->
                <button id="themeToggle" type="button"
                    class="relative inline-flex items-center justify-center w-10 h-10 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:text-brand-600 dark:hover:text-brand-400 hover:border-brand-400 dark:hover:border-brand-500 transition focus:outline-none focus:ring-2 focus:ring-brand-500"
                    aria-label="Toggle dark mode">
                    <!-- Sun (visible in dark mode) -->
                    <svg class="w-5 h-5 hidden dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                    <!-- Moon (visible in light mode) -->
                    <svg class="w-5 h-5 block dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                    </svg>
                </button>
            </div>
        </div>
    </header>

    <!-- Theme toggle + sidebar JS -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Theme toggle
            var html = document.documentElement;
            var btn = document.getElementById('themeToggle');
            if (btn) {
                btn.addEventListener('click', function () {
                    html.classList.add('theme-transition');
                    html.classList.toggle('dark');
                    try {
                        localStorage.setItem('lanaccess-theme', html.classList.contains('dark') ? 'dark' : 'light');
                    } catch (e) { /* ignore */ }
                    // Dispatch event so charts can re-render with theme-aware colors
                    window.dispatchEvent(new CustomEvent('themechange', { detail: { dark: html.classList.contains('dark') } }));
                    setTimeout(function () { html.classList.remove('theme-transition'); }, 250);
                });
            }

            // Sidebar (mobile)
            var hamburgerMenu = document.getElementById('hamburgerMenu');
            var sidebar = document.getElementById('sidebar');
            if (hamburgerMenu && sidebar) {
                hamburgerMenu.addEventListener('click', function (e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('-translate-x-full');
                });
                var sidebarLinks = sidebar.querySelectorAll('a');
                sidebarLinks.forEach(function (link) {
                    link.addEventListener('click', function () {
                        if (window.innerWidth < 768) {
                            sidebar.classList.add('-translate-x-full');
                        }
                    });
                });
                document.addEventListener('click', function (event) {
                    if (window.innerWidth < 768 && !sidebar.contains(event.target) && !hamburgerMenu.contains(event.target)) {
                        sidebar.classList.add('-translate-x-full');
                    }
                });
            }
        });
    </script>
