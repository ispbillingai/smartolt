<!-- backend/templates/sidebar.php -->
<?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
<aside id="sidebar"
    class="w-64 bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 fixed h-full z-50 transform -translate-x-full md:translate-x-0 transition-transform duration-300 flex flex-col">

    <div class="p-5 border-b border-slate-200 dark:border-slate-800 flex items-center gap-3">
        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 flex items-center justify-center shadow-glow">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
        </div>
        <h2 class="text-base font-bold text-slate-800 dark:text-slate-100 tracking-tight">Hotspot AP</h2>
    </div>

    <nav class="mt-3 px-3 flex-1 overflow-y-auto">
        <a href="index.php"
           class="group flex items-center gap-3 px-3 py-2.5 rounded-lg mb-1 text-sm font-medium transition
                  <?php echo $currentPage === 'index.php'
                    ? 'bg-brand-50 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300'
                    : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white'; ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
            </svg>
            Dashboard
        </a>

        <!-- Devices -->
        <a href="devices.php"
           class="group flex items-center gap-3 px-3 py-2.5 rounded-lg mb-1 text-sm font-medium transition
                  <?php echo $currentPage === 'devices.php'
                    ? 'bg-brand-50 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300'
                    : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white'; ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>
            </svg>
            Devices
        </a>

        <!-- Configurations Menu (Collapsible) -->
        <div class="mb-1">
            <button id="configurationsToggle"
                class="group flex items-center gap-3 w-full px-3 py-2.5 rounded-lg text-sm font-medium transition
                       <?php echo $currentPage === 'configurations.php'
                         ? 'bg-brand-50 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300'
                         : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white'; ?>">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path>
                </svg>
                <span>Configurations</span>
                <svg id="configurationsArrow" class="w-4 h-4 ml-auto transform transition-transform <?php echo $currentPage === 'configurations.php' ? 'rotate-180' : ''; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </button>
            <div id="configurationsSubmenu" class="ml-9 mt-1 space-y-0.5 <?php echo $currentPage === 'configurations.php' ? '' : 'hidden'; ?>">
                <a href="configurations.php" class="block text-sm text-slate-500 dark:text-slate-400 hover:text-brand-600 dark:hover:text-brand-400 hover:bg-slate-100 dark:hover:bg-slate-800 px-3 py-1.5 rounded-lg transition">Setup</a>
                <a href="configurations.php#tenda-config" class="block text-sm text-slate-500 dark:text-slate-400 hover:text-brand-600 dark:hover:text-brand-400 hover:bg-slate-100 dark:hover:bg-slate-800 px-3 py-1.5 rounded-lg transition">Tenda</a>
                <a href="configurations.php#ubiquiti-config" class="block text-sm text-slate-500 dark:text-slate-400 hover:text-brand-600 dark:hover:text-brand-400 hover:bg-slate-100 dark:hover:bg-slate-800 px-3 py-1.5 rounded-lg transition">Ubiquiti</a>
                <a href="configurations.php#huawei-config" class="block text-sm text-slate-500 dark:text-slate-400 hover:text-brand-600 dark:hover:text-brand-400 hover:bg-slate-100 dark:hover:bg-slate-800 px-3 py-1.5 rounded-lg transition">Huawei</a>
                <a href="configurations.php#tplink-config" class="block text-sm text-slate-500 dark:text-slate-400 hover:text-brand-600 dark:hover:text-brand-400 hover:bg-slate-100 dark:hover:bg-slate-800 px-3 py-1.5 rounded-lg transition">TP-Link</a>
                <a href="configurations.php#general-config" class="block text-sm text-slate-500 dark:text-slate-400 hover:text-brand-600 dark:hover:text-brand-400 hover:bg-slate-100 dark:hover:bg-slate-800 px-3 py-1.5 rounded-lg transition">General Knowledge</a>
            </div>
        </div>

        <a href="network.php"
           class="group flex items-center gap-3 px-3 py-2.5 rounded-lg mb-1 text-sm font-medium transition
                  <?php echo $currentPage === 'network.php'
                    ? 'bg-brand-50 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300'
                    : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white'; ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m0 0a9 9 0 019-9m-9 9a9 9 0 009 9"></path>
            </svg>
            Network
        </a>

        <?php $__sub = strtolower(explode('.', $_SERVER['HTTP_HOST'] ?? '')[0]); ?>
        <?php if (in_array($__sub, ['demo', 'final'], true)): // Monitor shown only on test subdomains ?>
        <a href="monitor.php"
           class="group flex items-center gap-3 px-3 py-2.5 rounded-lg mb-1 text-sm font-medium transition
                  <?php echo $currentPage === 'monitor.php'
                    ? 'bg-brand-50 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300'
                    : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white'; ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
            </svg>
            Monitor
        </a>
        <?php endif; ?>

        <a href="settings.php"
           class="group flex items-center gap-3 px-3 py-2.5 rounded-lg mb-1 text-sm font-medium transition
                  <?php echo $currentPage === 'settings.php'
                    ? 'bg-brand-50 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300'
                    : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white'; ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
            </svg>
            Settings
        </a>

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
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-gradient-to-r from-sky-500 to-brand-600 hover:from-sky-600 hover:to-brand-700 text-white shadow-glow transition">
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

<!-- JavaScript for Collapsible Devices/Configurations submenus -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var devicesToggle = document.getElementById('devicesToggle');
        var devicesSubmenu = document.getElementById('devicesSubmenu');
        var devicesArrow = document.getElementById('devicesArrow');
        if (devicesToggle && devicesSubmenu && devicesArrow) {
            devicesToggle.addEventListener('click', function () {
                devicesSubmenu.classList.toggle('hidden');
                devicesArrow.classList.toggle('rotate-180');
            });
        }

        var configurationsToggle = document.getElementById('configurationsToggle');
        var configurationsSubmenu = document.getElementById('configurationsSubmenu');
        var configurationsArrow = document.getElementById('configurationsArrow');
        if (configurationsToggle && configurationsSubmenu && configurationsArrow) {
            configurationsToggle.addEventListener('click', function () {
                configurationsSubmenu.classList.toggle('hidden');
                configurationsArrow.classList.toggle('rotate-180');
            });
        }
    });
</script>
