@php
    /*
     * A checkbox that reads as checked or not checked and nothing else: an
     * accent fill under the forms plugin's white check, and no focus ring on
     * a click or on focus.
     *
     * checked:bg-* is required: bg-rg-card (utilities layer) overrides the
     * forms plugin's base-layer checked background, leaving the plugin's
     * white checkmark invisible on the card background. focus:ring-offset-0
     * goes with focus:ring-0, because the plugin's 2px offset alone still
     * draws a ring.
     */
    $baseClass = 'size-4 rounded border-rg-border2 bg-rg-card text-rg-accent checked:border-rg-accent checked:bg-rg-accent focus:outline-none focus:ring-0 focus:ring-offset-0';
@endphp

<input type="checkbox" {{ $attributes->class($baseClass) }}>
