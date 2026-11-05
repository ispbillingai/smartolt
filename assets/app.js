// Menu dropdowns, theme switch, confirm boxes, modals and the "talking to the OLT" overlay.
(function () {
    var html = document.documentElement;

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
    window.soClose = function (m) { m.classList.remove('show'); };
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
    function busy(f) {
        var text = f.getAttribute('data-busy');
        if (text === null) return;
        document.getElementById('soBusyText').textContent = text || 'Talking to the OLT...';
        document.getElementById('soBusy').classList.add('show');
    }
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (f.hasAttribute('data-confirm') && f !== pending) {
            e.preventDefault();
            pending = f;
            document.getElementById('soConfirmText').textContent = f.getAttribute('data-confirm');
            ask.classList.add('show');
            return;
        }
        pending = null;
        busy(f);
    });
    if (ask) document.getElementById('soConfirmYes').addEventListener('click', function () {
        ask.classList.remove('show');
        if (pending) {
            var f = pending;
            busy(f);
            if (f.requestSubmit) { f.requestSubmit(); } else { pending = null; f.submit(); }
        }
    });

    // Copy buttons: data-copy="id of the element whose text is copied".
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
