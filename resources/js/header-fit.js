// Fits the header's row to the width it has: the brand at its full size and
// the optional controls in order of importance, for as long as they all fit.
// A control marked data-header-fit="N" is optional — the higher N, the longer
// it stays — and is shown with the display data-header-fit-display names. One
// that has nowhere else to go at some widths names them in
// data-header-fit-always, a media query: while it matches, the control is
// always shown (the language and theme switchers from the desktop width, where
// the menu that also holds them is gone). A short form
// (data-header-fit-short="key") stands in for the long one beside it
// (data-header-fit-long="key") while the long one is put away: the language
// switcher's code for its full name.
//
// The brand's full size comes before every optional control: the brand link
// is drawn at data-header-fit-brand-size (px) while the controls are put away,
// and only when the row does not fit with all of them put away does it step
// down a pixel at a time, no lower than data-header-fit-brand-min-size, to the
// largest size at which its whole name is drawn. Should even that be too
// wide, the name is cut with its ellipsis at the least size. The desktop
// search's width plays no part in the brand's size: the brand steps down only
// while the row runs past its end or cuts the name. Until this has run, and
// without JavaScript, the row is laid out by the breakpoint classes of the
// controls and the brand.
//
// The row fits while nothing runs past its end, the brand's whole name is
// drawn — measured to the fraction of a pixel, as the ellipsis is — and the
// desktop search keeps the width its data-header-fit-search names. Inlined
// right after the header, so the row is fitted before the page is first
// drawn; fitted again, each time from the brand's full size with every
// control shown, when its width changes, once the fonts are in, once Alpine
// has started, and after wire:navigate swaps the page. Each fit writes the
// width it fitted the row to on the row (data-header-fitted).
(function () {
    // Run again by a page wire:navigate brought in: the listeners are set.
    if (typeof window.rgFitHeader === 'function') {
        window.rgFitHeader();
        return;
    }

    var observed = null;
    var observer = typeof ResizeObserver === 'function'
        ? new ResizeObserver(function () { fit(); })
        : null;

    function drawnWhole(name) {
        var text = document.createRange();
        text.selectNodeContents(name);

        return text.getBoundingClientRect().width <= name.getBoundingClientRect().width + 0.01;
    }

    // Nothing runs past the row's end and the brand's whole name is drawn.
    function brandFits(row) {
        if (row.scrollWidth > row.clientWidth) {
            return false;
        }

        var name = row.querySelector('[data-header-fit-brand]');

        return ! name || drawnWhole(name);
    }

    function fits(row) {
        if (! brandFits(row)) {
            return false;
        }

        var search = row.querySelector('[data-header-fit-search]');

        return ! search || search.offsetWidth === 0
            || search.getBoundingClientRect().width >= Number(search.dataset.headerFitSearch);
    }

    // A control put away is also marked data-header-fit-away, for the styles
    // of what holds it: an upload button whose words are away is square.
    function show(control, shown) {
        control.style.display = shown ? control.dataset.headerFitDisplay : 'none';
        control.toggleAttribute('data-header-fit-away', ! shown);
    }

    // Each short form shown exactly while its long form is put away.
    function pairShortForms(row) {
        row.querySelectorAll('[data-header-fit-short]').forEach(function (short) {
            var long = short.parentElement.querySelector('[data-header-fit-long="' + short.dataset.headerFitShort + '"]');

            show(short, long !== null && long.style.display === 'none');
        });
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

        var brand = row.querySelector('[data-header-fit-brand-size]');
        var brandSize = brand ? Number(brand.dataset.headerFitBrandSize) : 0;
        var brandMinSize = brand ? Number(brand.dataset.headerFitBrandMinSize) : 0;

        if (brand) {
            brand.style.fontSize = brandSize + 'px';
        }

        var optional = [];

        row.querySelectorAll('[data-header-fit]').forEach(function (control) {
            var always = control.dataset.headerFitAlways;

            show(control, true);

            if (! always || ! window.matchMedia(always).matches) {
                optional.push(control);
            }
        });

        // Least important first: all of them shown, then one after another
        // put away until the rest fit.
        optional.sort(function (a, b) { return Number(a.dataset.headerFit) - Number(b.dataset.headerFit); });
        pairShortForms(row);

        for (var i = 0; i < optional.length && ! fits(row); i++) {
            show(optional[i], false);
            pairShortForms(row);
        }

        // Every optional control is put away and the name is still cut: the
        // brand steps down until its whole name is drawn, or to its least size.
        while (brand && brandSize > brandMinSize && ! brandFits(row)) {
            brandSize -= 1;
            brand.style.fontSize = brandSize + 'px';
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
