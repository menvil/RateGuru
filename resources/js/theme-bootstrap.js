// Sets data-theme and data-theme-preference on <html> before CSS renders to prevent flash
(function () {
    var STORAGE_KEY = 'rateguru.theme.preference';
    var root = document.documentElement;

    var stored = null;
    try {
        stored = localStorage.getItem(STORAGE_KEY);
    } catch (e) {}

    // A choice saved to the signed-in account wins over whatever this browser
    // kept — a guest's choice, or one made on another account — and replaces
    // it. Otherwise the browser's own choice is the one there is.
    var fromAccount = root.dataset.themeSource === 'user';
    var preference = fromAccount
        ? (root.dataset.themePreference || 'system')
        : (stored || root.dataset.themePreference || 'system');

    if (fromAccount) {
        try {
            localStorage.setItem(STORAGE_KEY, preference);
        } catch (e) {}
    }

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

    // Listened to whatever the preference is now: System can be chosen later
    // on this page, and the operating system's theme is followed from then on.
    // The preference on the page is the one that counts at that moment.
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
            if (root.dataset.themePreference === 'system') {
                root.dataset.theme = e.matches ? 'dark' : 'light';
            }
        });
    }
})();
