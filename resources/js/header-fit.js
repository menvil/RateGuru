// Fits the header's row to the width it has: the optional controls are shown
// in order of importance for as long as they all fit, and the rest stay in the
// menu. A control marked data-header-fit="N" is optional — the higher N, the
// longer it stays — and is shown with the display data-header-fit-display
// names. Until this has run, and without JavaScript, the row is laid out by
// its breakpoints alone, which leave every language room.
//
// The row fits while nothing runs past its end, the brand's whole name is
// drawn, and the search, where the row has one, keeps room to type in. Inlined
// right after the header, so the row is fitted before the page is first drawn;
// fitted again when its width changes, once the fonts are in, and after
// wire:navigate swaps the page. Each fit writes the width it fitted the row to
// on the row (data-header-fitted).
(function () {
    var SEARCH_MIN_WIDTH = 240;

    // Run again by a page wire:navigate brought in: the listeners are set.
    if (typeof window.rgFitHeader === 'function') {
        window.rgFitHeader();
        return;
    }

    var observed = null;
    var observer = typeof ResizeObserver === 'function'
        ? new ResizeObserver(function () { fit(); })
        : null;

    function fits(row) {
        if (row.scrollWidth > row.clientWidth) {
            return false;
        }

        var name = row.querySelector('[data-header-fit-brand]');
        if (name && name.scrollWidth > name.clientWidth) {
            return false;
        }

        var search = row.querySelector('[data-header-fit-search]');

        return ! search || search.offsetWidth === 0 || search.offsetWidth >= SEARCH_MIN_WIDTH;
    }

    function fit() {
        var row = document.querySelector('[data-header-fit-row]');
        if (! row) {
            return;
        }

        if (observer && observed !== row) {
            if (observed) {
                observer.unobserve(observed);
            }
            observer.observe(row);
            observed = row;
        }

        // Least important first: all of them shown, then one after another
        // put away until the rest fit.
        var optional = Array.prototype.slice.call(row.querySelectorAll('[data-header-fit]'))
            .sort(function (a, b) { return Number(a.dataset.headerFit) - Number(b.dataset.headerFit); });

        optional.forEach(function (control) {
            control.style.display = control.dataset.headerFitDisplay;
        });

        for (var i = 0; i < optional.length && ! fits(row); i++) {
            optional[i].style.display = 'none';
        }

        row.dataset.headerFitted = String(row.clientWidth);
    }

    window.rgFitHeader = fit;

    document.addEventListener('livewire:navigated', fit);
    document.addEventListener('alpine:initialized', fit);

    if (document.fonts) {
        document.fonts.ready.then(fit);
        document.fonts.addEventListener('loadingdone', fit);
    }

    fit();
})();
