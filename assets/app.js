// Menu dropdowns, theme switch, confirm boxes, modals and the "talking to the OLT" overlay.
(function () {
    var html = document.documentElement;

    function chartTheme() {
        if (!window.Chart || !window.soChart) return;
        Chart.defaults.color = soChart.tick();
        Chart.defaults.borderColor = soChart.grid();
        Object.values(Chart.instances).forEach(function (chart) {
            if (chart.options.plugins && chart.options.plugins.legend) chart.options.plugins.legend.labels.color = soChart.tick();
            Object.values(chart.options.scales || {}).forEach(function (scale) {
                if (scale.ticks) scale.ticks.color = soChart.tick();
                if (scale.grid) scale.grid.color = soChart.grid();
                if (scale.border) scale.border.color = soChart.grid();
                if (scale.title) scale.title.color = soChart.tick();
            });
            chart.update('none');
        });
    }
    window.addEventListener('themechange', chartTheme);
    chartTheme();

    function closeMenus(except) {
        document.querySelectorAll('.so-menu > li.open').forEach(function (li) { if (li !== except) li.classList.remove('open'); });
    }
    document.querySelectorAll('.so-menu > li > button[data-drop]').forEach(function (b) {
        b.addEventListener('click', function (e) {
            e.stopPropagation();
            var li = b.parentNode;
            closeMenus(li);
            li.classList.toggle('open');
        });
    });
    document.addEventListener('click', function () { closeMenus(null); });

    var burger = document.getElementById('soBurger');
    if (burger) burger.addEventListener('click', function () { document.getElementById('soNav').classList.toggle('open'); });

    var theme = document.getElementById('soTheme');
    if (theme) theme.addEventListener('click', function (e) {
        e.preventDefault();
        html.classList.toggle('light');
        try { localStorage.setItem('smartolt-theme', html.classList.contains('light') ? 'light' : 'dark'); } catch (err) { /* private mode */ }
        window.dispatchEvent(new CustomEvent('themechange'));
    });

    // Modals: any element with data-open="id" opens <div class="so-modal" id="id">.
    window.soOpen = function (id) { var m = document.getElementById(id); if (m) m.classList.add('show'); };
    window.soClose = function (m) {
        m.classList.remove('show');
        if (m.id === 'soConfirm') { pending = null; pendingSubmitter = null; }
    };
    document.addEventListener('click', function (e) {
        var o = e.target.closest('[data-open]');
        if (o) { e.preventDefault(); window.soOpen(o.getAttribute('data-open')); return; }
        var c = e.target.closest('[data-close]');
        if (c) { e.preventDefault(); window.soClose(c.closest('.so-modal')); return; }
        if (e.target.classList && e.target.classList.contains('so-modal')) window.soClose(e.target);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.querySelectorAll('.so-modal.show').forEach(window.soClose);
    });

    // Forms with data-confirm ask first in our own box; data-busy shows the overlay while the OLT answers.
    var ask = document.getElementById('soConfirm');
    var pending = null;
    var pendingSubmitter = null;
    var approved = null;
    function busy(f) {
        var text = f.getAttribute('data-busy');
        if (text === null) return;
        document.getElementById('soBusyText').textContent = text || 'Talking to the OLT...';
        document.getElementById('soBusy').classList.add('show');
    }
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (f.hasAttribute('data-confirm') && f !== approved) {
            e.preventDefault();
            pending = f;
            pendingSubmitter = e.submitter || null;
            document.getElementById('soConfirmText').textContent = f.getAttribute('data-confirm');
            ask.classList.add('show');
            return;
        }
        approved = null;
        busy(f);
    });
    if (ask) document.getElementById('soConfirmYes').addEventListener('click', function () {
        ask.classList.remove('show');
        if (pending) {
            var f = pending, submitter = pendingSubmitter;
            pending = null; pendingSubmitter = null; approved = f;
            if (f.requestSubmit) { f.requestSubmit(submitter || undefined); }
            else { busy(f); approved = null; f.submit(); }
            // Invalid forms may not dispatch a submit event; never retain their approval.
            approved = null;
        }
    });

    // Copy buttons: data-copy="id of the element whose text is copied".
    window.soLocate = function (button, found) {
        var status = button.parentNode.querySelector('[data-location-status]');
        if (!status) {
            status = document.createElement('div'); status.className = 'so-help';
            status.setAttribute('data-location-status', ''); status.setAttribute('role', 'status');
            button.parentNode.appendChild(status);
        }
        if (!navigator.geolocation) { status.textContent = 'Location is unavailable in this browser. Enter latitude and longitude manually.'; return; }
        button.disabled = true; status.textContent = 'Waiting for location permission…';
        navigator.geolocation.getCurrentPosition(function (p) {
            button.disabled = false; status.textContent = 'Location filled. Save the form to keep it.'; found(p);
        }, function (error) {
            button.disabled = false;
            status.textContent = error.code === 1 ? 'Location permission was denied. Allow location access or enter coordinates manually.' : 'Could not find your location. Enter latitude and longitude manually.';
        }, { timeout: 10000, maximumAge: 60000 });
    };
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-copy]');
        if (!b) return;
        var text = document.getElementById(b.getAttribute('data-copy')).textContent;
        var old = b.innerHTML;
        (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () {
            b.textContent = 'Copied';
            setTimeout(function () { b.innerHTML = old; }, 1500);
        }, function () { window.getSelection().selectAllChildren(document.getElementById(b.getAttribute('data-copy'))); });
    });
})();
