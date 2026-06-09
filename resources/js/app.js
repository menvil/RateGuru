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
