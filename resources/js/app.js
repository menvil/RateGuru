// Alpine is provided by Livewire 3 — do not import it separately.
import './share.js';

window.rgSetTheme = function (pref) {
    var applied = pref === 'system'
        ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
        : pref;
    document.documentElement.dataset.theme = applied;
    document.documentElement.dataset.themePreference = pref;
    try { localStorage.setItem('rateguru.theme.preference', pref); } catch (e) {}
};

// Re-apply theme from localStorage after wire:navigate swaps the page,
// because the server always renders data-theme="dark" for system-preference users.
document.addEventListener('livewire:navigated', function () {
    var STORAGE_KEY = 'rateguru.theme.preference';
    var stored = null;
    try { stored = localStorage.getItem(STORAGE_KEY); } catch (e) {}
    var pref = stored || document.documentElement.dataset.themePreference || 'system';
    window.rgSetTheme(pref);
});
