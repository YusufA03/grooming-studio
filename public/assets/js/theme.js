/* egs-theme: the light / dark switch.
   - remembers the visitor's choice in this browser (localStorage)
   - with no saved choice it follows the device setting
   - the button is added to the header by this script, so no page needs its own button
   Everything is wrapped in try/catch: if storage is blocked the switch still works for the visit. */
(function () {
    var KEY = 'egs-theme';
    var root = document.documentElement;
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function saved() {
        try {
            var v = localStorage.getItem(KEY);
            return (v === 'dark' || v === 'light') ? v : null;
        } catch (e) { return null; }
    }
    function systemTheme() { return (media && media.matches) ? 'dark' : 'light'; }
    function current() { return root.getAttribute('data-theme') || systemTheme(); }

    // Set the theme straight away (this script sits in the <head>), so the page never flashes the wrong colours.
    root.setAttribute('data-theme', saved() || systemTheme());

    // With no saved choice, follow the device if its setting changes while the page is open.
    if (media && media.addEventListener) {
        media.addEventListener('change', function () {
            if (!saved()) { root.setAttribute('data-theme', systemTheme()); refresh(); }
        });
    }

    var button = null;
    function label() { return current() === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'; }
    function refresh() {
        if (!button) { return; }
        button.setAttribute('aria-label', label());
        button.setAttribute('title', label());
    }

    function addButton() {
        var header = document.querySelector('.site-header');
        if (!header || header.querySelector('.theme-toggle')) { return; }
        button = document.createElement('button');
        button.type = 'button';
        button.className = 'theme-toggle';
        button.innerHTML =
            '<svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                '<path d="M20.5 13.2A8.5 8.5 0 1 1 10.8 3.5a6.8 6.8 0 0 0 9.7 9.7z"/></svg>' +
            '<svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                '<circle cx="12" cy="12" r="4"/>' +
                '<path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M5.3 18.7l1.6-1.6M17.1 6.9l1.6-1.6"/></svg>';
        button.addEventListener('click', function () {
            var next = current() === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem(KEY, next); } catch (e) { /* storage blocked: the choice lasts for this page only */ }
            refresh();
        });
        header.appendChild(button);
        refresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', addButton);
    } else {
        addButton();
    }
}());
