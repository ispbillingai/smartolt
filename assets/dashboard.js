(function () {
    'use strict';
    var on = true;
    try { on = localStorage.getItem('smartolt-autorefresh') !== 'off'; } catch (ignore) {}
    var toggle = document.getElementById('autoRefresh');
    function showRefresh() {
        toggle.setAttribute('aria-pressed', String(on));
        document.getElementById('autoText').textContent = on ? 'Auto-refresh on' : 'Auto-refresh off';
    }
    showRefresh();
    toggle.addEventListener('click', function () {
        on = !on; showRefresh();
        try { localStorage.setItem('smartolt-autorefresh', on ? 'on' : 'off'); } catch (ignore) {}
    });
    setInterval(function () {
        var active = document.activeElement;
        if (on && !document.hidden && !document.querySelector('.so-modal.show, details[open]')
            && !(active && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName))) location.reload();
    }, 60000);
    if (typeof Chart === 'undefined') {
        document.querySelectorAll('.dash-chart-fallback').forEach(function (el) { el.hidden = false; });
        return;
    }
    var data = JSON.parse(document.getElementById('dashboardData').textContent), charts = [];
    function build() {
        charts.forEach(function (chart) { chart.destroy(); }); charts = [];
        var light = document.documentElement.classList.contains('light');
        var tick = light ? '#65768a' : '#91a5b8', grid = light ? 'rgba(72,98,120,.1)' : 'rgba(136,160,180,.10)';
        var common = { responsive: true, maintainAspectRatio: false, animation: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: false }, tooltip: { padding: 12, backgroundColor: '#142831', titleColor: '#fff', bodyColor: '#deedf0', cornerRadius: 8 } },
            scales: { x: { ticks: { color: tick, maxTicksLimit: 7, maxRotation: 0, font: { size: 11 } }, grid: { display: false }, border: { display: false } },
                y: { beginAtZero: true, ticks: { color: tick, precision: 0, maxTicksLimit: 5, font: { size: 11 } }, grid: { color: grid }, border: { display: false } } } };
        charts.push(new Chart(document.getElementById('authChart'), { type: 'bar',
            data: { labels: Object.keys(data.auth).map(function (day) { return day.slice(8) + '/' + day.slice(5, 7); }),
                datasets: [{ label: 'ONUs authorized', data: Object.values(data.auth), backgroundColor: '#35b7a4', hoverBackgroundColor: '#68d1be', borderRadius: 4, maxBarThickness: 22 }] }, options: common }));
        var net = document.getElementById('netChart');
        if (!net) return;
        var lines = [['Online', 'on', '#35b7a4'], ['Power fail', 'pf', '#7c9fe8'], ['Signal loss', 'los', '#e7ac57'], ['Other offline', 'na', '#a2a6b5']];
        var options = JSON.parse(JSON.stringify(common));
        options.plugins.legend = { display: true, position: 'bottom', align: 'start', labels: { color: tick, usePointStyle: true, pointStyle: 'circle', boxWidth: 7, boxHeight: 7, padding: 20, font: { size: 11 } } };
        charts.push(new Chart(net, { type: 'line', data: { labels: data.net.map(function (p) { return p.t; }), datasets: lines.map(function (line, i) {
            return { label: line[0], data: data.net.map(function (p) { return p[line[1]]; }), borderColor: line[2], backgroundColor: i === 0 ? 'rgba(53,183,164,.09)' : 'transparent',
                fill: i === 0, tension: .25, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4 };
        }) }, options: options }));
    }
    build(); window.addEventListener('themechange', build);
})();
