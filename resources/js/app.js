// Alpine is provided by Livewire 3 — do not import it separately.
import './share.js';
import './auth-modal.js';
import './modal.js';
import './feed-links.js';
import './games.js';

// Shows a theme preference on the page, and remembers nothing.
function rgApplyTheme(pref) {
    var applied = pref === 'system'
        ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
        : pref;
    document.documentElement.dataset.theme = applied;
    document.documentElement.dataset.themePreference = pref;
}

// A theme the visitor chose: shown, and kept in this browser.
window.rgSetTheme = function (pref) {
    rgApplyTheme(pref);
    try { localStorage.setItem('rateguru.theme.preference', pref); } catch (e) {}
};

// Re-apply theme after wire:navigate swaps the page, because the server always
// renders data-theme="dark" for system-preference users. Applied only, never
// stored: this also runs on the first load, and storing then would keep the
// first theme a browser ever saw — the project default, say — for good.
document.addEventListener('livewire:navigated', function () {
    var STORAGE_KEY = 'rateguru.theme.preference';
    var stored = null;
    try { stored = localStorage.getItem(STORAGE_KEY); } catch (e) {}
    var root = document.documentElement;
    var pref = root.dataset.themeSource === 'user'
        ? (root.dataset.themePreference || 'system')
        : (stored || root.dataset.themePreference || 'system');
    rgApplyTheme(pref);
});
