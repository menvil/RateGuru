// Sets data-theme and data-theme-preference on <html> before CSS renders to prevent flash
(function () {
    var STORAGE_KEY = 'rateguru.theme.preference';
    var root = document.documentElement;

    var stored = null;
    try {
        stored = localStorage.getItem(STORAGE_KEY);
    } catch (e) {}

    var preference = stored || root.dataset.themePreference || 'system';

    var applied;
    if (preference === 'system') {
        applied = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark'
            : 'light';
    } else {
        applied = preference === 'light' ? 'light' : 'dark';
    }

    root.dataset.theme = applied;
    root.dataset.themePreference = preference;

    if (preference === 'system' && window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
            var pref = null;
            try { pref = localStorage.getItem(STORAGE_KEY); } catch (err) {}
            if ((pref || root.dataset.themePreference) === 'system') {
                root.dataset.theme = e.matches ? 'dark' : 'light';
            }
        });
    }
})();
