<?php
// Without a session the login check below always fails and bounces to index.php.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/auth/login.php';

$loginHandler = new LoginHandler();
if (!$loginHandler->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Initialize session data if not exists
if (!isset($_SESSION['metrics'])) {
    $_SESSION['metrics'] = [
        'timestamps' => [],
        'cpu' => [],
        'memory' => [],
        'disk' => []
    ];
}

// Function to get CPU usage
function getCpuUsage() {
    $load = sys_getloadavg();
    return round($load[0], 2);
}

// Function to get memory usage
function getMemoryUsage() {
    $free = shell_exec('free');
    $free = (string)trim($free);
    $free_arr = explode("\n", $free);
    $mem = explode(" ", $free_arr[1]);
    $mem = array_filter($mem);
    $mem = array_merge($mem);
    $memory_usage = $mem[2]/$mem[1]*100;
    return round($memory_usage, 2);
}

// Function to get disk usage
function getDiskUsage() {
    $disk_total = disk_total_space("/");
    $disk_free = disk_free_space("/");
    $disk_used = $disk_total - $disk_free;
    $disk_usage_percent = ($disk_used / $disk_total) * 100;
    return [
        'total' => round($disk_total / (1024*1024*1024), 2),
        'used' => round($disk_used / (1024*1024*1024), 2),
        'free' => round($disk_free / (1024*1024*1024), 2),
        'percent' => round($disk_usage_percent, 2)
    ];
}

// Function to get server uptime
function getServerUptime() {
    $uptime = shell_exec('uptime -p');
    return trim($uptime);
}

// Store current metrics in session
function storeMetrics($cpu, $memory, $disk) {
    $timestamp = time();
    $_SESSION['metrics']['timestamps'][] = $timestamp;
    $_SESSION['metrics']['cpu'][] = $cpu;
    $_SESSION['metrics']['memory'][] = $memory;
    $_SESSION['metrics']['disk'][] = $disk;

    // Keep only last 60 minutes of data
    $cutoff = $timestamp - 3600; // 1 hour ago
    while (!empty($_SESSION['metrics']['timestamps']) && $_SESSION['metrics']['timestamps'][0] < $cutoff) {
        array_shift($_SESSION['metrics']['timestamps']);
        array_shift($_SESSION['metrics']['cpu']);
        array_shift($_SESSION['metrics']['memory']);
        array_shift($_SESSION['metrics']['disk']);
    }
}

// Detect spikes (e.g., value > 2x previous value or > 90%)
function detectSpikes($data) {
    $spikes = [];
    for ($i = 1; $i < count($data); $i++) {
        $current = $data[$i];
        $previous = $data[$i - 1];
        if ($current > 90 || ($previous > 0 && $current > $previous * 2)) {
            $spikes[$i] = true;
        }
    }
    return $spikes;
}

// Handle AJAX request (must return before any layout is emitted)
if (isset($_GET['action']) && $_GET['action'] == 'get_metrics') {
    $cpu_usage = getCpuUsage();
    $memory_usage = getMemoryUsage();
    $disk_usage = getDiskUsage();
    storeMetrics($cpu_usage, $memory_usage, $disk_usage['percent']);

    // Prepare historical data
    $historical_data = $_SESSION['metrics'];
    $cpu_spikes = detectSpikes($historical_data['cpu']);
    $memory_spikes = detectSpikes($historical_data['memory']);
    $disk_spikes = detectSpikes($historical_data['disk']);

    header('Content-Type: application/json');
    echo json_encode([
        'cpu' => $cpu_usage,
        'memory' => $memory_usage,
        'disk' => $disk_usage,
        'uptime' => getServerUptime(),
        'historical' => $historical_data,
        'spikes' => [
            'cpu' => $cpu_spikes,
            'memory' => $memory_spikes,
            'disk' => $disk_spikes
        ]
    ]);
    exit;
}

// Initial data collection
$cpu_usage = getCpuUsage();
$memory_usage = getMemoryUsage();
$disk_usage = getDiskUsage();
$uptime = getServerUptime();
storeMetrics($cpu_usage, $memory_usage, $disk_usage['percent']);

// Render inside the shared app shell (header + sidebar + footer)
include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
?>

<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <div class="mb-6 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
            <div>
                <h2 class="text-2xl md:text-3xl font-bold text-slate-900 dark:text-white tracking-tight">Monitor</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Real-time system metrics with 1-hour trends. Red dots indicate spikes.</p>
            </div>
            <button onclick="fetchMetrics()" class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium shadow-glow focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-slate-900 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                Refresh
            </button>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- CPU -->
            <div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6">
                <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">CPU Usage</h3>
                <div class="w-full h-3 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden">
                    <div id="cpu-progress" class="h-full rounded-full bg-blue-500 transition-all duration-500" style="width: <?php echo $cpu_usage; ?>%;"></div>
                </div>
                <div id="cpu-info" class="mt-3 text-sm text-slate-600 dark:text-slate-300">Usage: <?php echo $cpu_usage; ?>%</div>
                <div class="mt-4 h-48"><canvas id="cpu-chart"></canvas></div>
                <div id="cpu-spike-note" class="mt-2 text-xs text-red-500" style="display:none;">Spike detected</div>
            </div>

            <!-- Memory -->
            <div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6">
                <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">Memory Usage</h3>
                <div class="w-full h-3 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden">
                    <div id="memory-progress" class="h-full rounded-full bg-emerald-500 transition-all duration-500" style="width: <?php echo $memory_usage; ?>%;"></div>
                </div>
                <div id="memory-info" class="mt-3 text-sm text-slate-600 dark:text-slate-300">Usage: <?php echo $memory_usage; ?>%</div>
                <div class="mt-4 h-48"><canvas id="memory-chart"></canvas></div>
                <div id="memory-spike-note" class="mt-2 text-xs text-red-500" style="display:none;">Spike detected</div>
            </div>

            <!-- Disk -->
            <div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6">
                <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">Disk Usage</h3>
                <div class="w-full h-3 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden">
                    <div id="disk-progress" class="h-full rounded-full bg-red-500 transition-all duration-500" style="width: <?php echo $disk_usage['percent']; ?>%;"></div>
                </div>
                <div id="disk-info" class="mt-3 text-sm text-slate-600 dark:text-slate-300 space-y-0.5">
                    <div>Total: <?php echo $disk_usage['total']; ?> GB</div>
                    <div>Used: <?php echo $disk_usage['used']; ?> GB</div>
                    <div>Free: <?php echo $disk_usage['free']; ?> GB</div>
                    <div>Usage: <?php echo $disk_usage['percent']; ?>%</div>
                </div>
                <div class="mt-4 h-48"><canvas id="disk-chart"></canvas></div>
                <div id="disk-spike-note" class="mt-2 text-xs text-red-500" style="display:none;">Spike detected</div>
            </div>

        </div>

        <!-- Uptime -->
        <div class="mt-6 bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6 max-w-md">
            <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-2">Server Uptime</h3>
            <div id="uptime-info" class="text-sm text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars($uptime); ?></div>
        </div>
    </main>

    <?php include __DIR__ . '/backend/templates/footer.php'; ?>
</div>

<script>
// Chart.js is already loaded by backend/templates/header.php
(function () {
    function makeChart(id, color) {
        return new Chart(document.getElementById(id).getContext('2d'), {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: '', data: [], borderColor: color, fill: false, tension: 0.4,
                    pointBackgroundColor: [], pointBorderColor: [], pointRadius: []
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, max: 100 } },
                plugins: { legend: { display: false } },
                animation: { duration: 800 }
            }
        });
    }

    const cpuChart    = makeChart('cpu-chart', '#3b82f6');
    const memoryChart = makeChart('memory-chart', '#10b981');
    const diskChart   = makeChart('disk-chart', '#ef4444');

    function apply(chart, values, spikes, baseColor) {
        const colors = values.map((_, i) => spikes[i] ? '#ef4444' : baseColor);
        const radius = values.map((_, i) => spikes[i] ? 8 : 3);
        chart.data.datasets[0].data = values;
        chart.data.datasets[0].pointBackgroundColor = colors;
        chart.data.datasets[0].pointBorderColor = colors;
        chart.data.datasets[0].pointRadius = radius;
        chart.update();
    }

    window.fetchMetrics = function () {
        fetch('?action=get_metrics')
            .then(r => r.json())
            .then(data => {
                document.getElementById('cpu-progress').style.width = data.cpu + '%';
                document.getElementById('cpu-info').textContent = 'Usage: ' + data.cpu + '%';

                document.getElementById('memory-progress').style.width = data.memory + '%';
                document.getElementById('memory-info').textContent = 'Usage: ' + data.memory + '%';

                document.getElementById('disk-progress').style.width = data.disk.percent + '%';
                document.getElementById('disk-info').innerHTML =
                    '<div>Total: ' + data.disk.total + ' GB</div>' +
                    '<div>Used: ' + data.disk.used + ' GB</div>' +
                    '<div>Free: ' + data.disk.free + ' GB</div>' +
                    '<div>Usage: ' + data.disk.percent + '%</div>';

                document.getElementById('uptime-info').textContent = data.uptime;

                const labels = data.historical.timestamps.map(t => new Date(t * 1000).toLocaleTimeString());
                cpuChart.data.labels = labels;
                memoryChart.data.labels = labels;
                diskChart.data.labels = labels;

                apply(cpuChart, data.historical.cpu, data.spikes.cpu, '#3b82f6');
                apply(memoryChart, data.historical.memory, data.spikes.memory, '#10b981');
                apply(diskChart, data.historical.disk, data.spikes.disk, '#ef4444');

                document.getElementById('cpu-spike-note').style.display    = Object.keys(data.spikes.cpu).length    ? 'block' : 'none';
                document.getElementById('memory-spike-note').style.display = Object.keys(data.spikes.memory).length ? 'block' : 'none';
                document.getElementById('disk-spike-note').style.display   = Object.keys(data.spikes.disk).length   ? 'block' : 'none';
            })
            .catch(() => {});
    };

    fetchMetrics();
    setInterval(fetchMetrics, 10000);
})();
</script>
