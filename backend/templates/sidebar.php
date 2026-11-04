<!-- backend/templates/sidebar.php -->
<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$navItems = [
    // file => [label, icon path, pages that light it up]
    'index.php'        => ['Dashboard', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6', ['index.php']],
    'olts.php'         => ['OLTs', 'M5 12h14M5 12a2 2 0 01-2-2V7a2 2 0 012-2h14a2 2 0 012 2v3a2 2 0 01-2 2M5 12a2 2 0 00-2 2v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 00-2-2m-2-4h.01M17 16h.01', ['olts.php']],
    'unconfigured.php' => ['Unconfigured ONUs', 'M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z', ['unconfigured.php']],
    'onus.php'         => ['Configured ONUs', 'M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0', ['onus.php', 'onu.php']],
    'logs.php'         => ['Activity Log', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', ['logs.php']],
    'settings.php'     => ['Settings', 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z', ['settings.php']],
];
?>
<aside id="sidebar"
    class="w-64 bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 fixed h-full z-50 transform -translate-x-full md:translate-x-0 transition-transform duration-300 flex flex-col">

    <div class="p-5 border-b border-slate-200 dark:border-slate-800 flex items-center gap-3">
        <div class="w-9 h-9 rounded-xl bg-brand-600 flex items-center justify-center shadow-glow">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
        </div>
        <h2 class="text-base font-bold text-slate-800 dark:text-slate-100 tracking-tight">SmartOLT</h2>
    </div>

    <nav class="mt-3 px-3 flex-1 overflow-y-auto">
        <?php foreach ($navItems as $file => [$label, $icon, $pages]): ?>
        <a href="<?php echo $file; ?>"
           class="group flex items-center gap-3 px-3 py-2.5 rounded-lg mb-1 text-sm font-medium transition
                  <?php echo in_array($currentPage, $pages, true)
                    ? 'bg-brand-50 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300'
                    : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white'; ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?php echo $icon; ?>"></path>
            </svg>
            <?php echo $label; ?>
        </a>
        <?php endforeach; ?>

        <a href="logout.php"
           class="group flex items-center gap-3 px-3 py-2.5 rounded-lg mb-1 text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-rose-50 dark:hover:bg-rose-900/30 hover:text-rose-700 dark:hover:text-rose-300 transition">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
            </svg>
            Logout
        </a>
    </nav>

    <!-- Telegram Support footer in sidebar -->
    <div class="p-3 border-t border-slate-200 dark:border-slate-800">
        <a href="https://t.me/freeispradius/10374" target="_blank" rel="noopener"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white shadow-glow transition">
            <svg class="w-5 h-5" viewBox="0 0 240 240" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                <path d="M120 0C53.73 0 0 53.73 0 120s53.73 120 120 120 120-53.73 120-120S186.27 0 120 0z" fill="#37aee2"/>
                <path d="M99.88 174.54c-5.66 0-4.66-2.12-6.58-7.42L74 116.19l108-64.46" fill="#c8daea"/>
                <path d="M99.88 174.54c4.34 0 6.29-2.02 8.73-4.42l23.42-22.68-29.1-17.53" fill="#a9c9dd"/>
                <path d="M101.58 132.77l59.8 44.21c6.82 3.77 11.8 1.82 13.51-6.34l24.42-115.07c2.24-10.01-3.66-14.61-10.61-12.12L31.7 102.9c-9.88 3.71-9.8 8.91-1.79 11.35l39.8 12.43 92.34-58.13c4.34-2.67 8.31-1.2 5.06 1.62" fill="#fff"/>
            </svg>
            <span class="font-medium text-sm">Support on Telegram</span>
        </a>
    </div>
</aside>
