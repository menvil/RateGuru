// Lays a page-level dialog out in the room it really has.
//
// The app header is sticky and sits above page-level dialogs, so a tall one —
// the image viewer — used to slide its title row and close button under it.
// A dialog that opts in (`below-header` on <x-ui.modal>) is placed between the
// header's bottom edge and the bottom of the screen instead, and is told how
// much room that is, so its content can scale to fit and the gap above it
// equals the gap below it at every screen size.
//
// Everything is measured, never assumed: the header is taller on mobile while
// the search row is open, and a dialog inside a panel that moves (the post
// drawer) is positioned against that panel, not against the viewport.
window.rgPlaceModalBelowHeader = function (dialog) {
    const backdrop = dialog.querySelector('[data-testid="modal-backdrop"]');
    const panel = dialog.querySelector('[data-modal-panel]');

    if (! backdrop || ! panel) {
        return;
    }

    // The backdrop is `fixed inset-0`: exactly the box the dialog is positioned against.
    const frame = backdrop.getBoundingClientRect();

    if (frame.height === 0) {
        return;
    }

    const header = document.querySelector('[data-app-header]');
    const headerBottom = header ? header.getBoundingClientRect().bottom : 0;
    const top = Math.min(Math.max(0, headerBottom - frame.top), frame.height);

    // Everything of the dialog that is not its content: title row, body padding, borders.
    const body = panel.querySelector('[data-modal-body]');
    const bodyStyle = body ? window.getComputedStyle(body) : null;
    const chrome = body
        ? panel.offsetHeight - body.offsetHeight + parseFloat(bodyStyle.paddingTop) + parseFloat(bodyStyle.paddingBottom)
        : 0;

    dialog.style.setProperty('--rg-modal-top', `${top}px`);
    dialog.style.setProperty('--rg-modal-height', `${frame.height - top}px`);
    dialog.style.setProperty('--rg-modal-chrome', `${chrome}px`);
};
