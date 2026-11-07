@php
    /*
     * The group's active options against the range the draft sets (STS-04,
     * editor size), and what that means: valid with room for more, at the
     * maximum, or outside the range — which blocks Save only while the group
     * would be active. Notices are FBK-02; outside the range is a warning,
     * since danger is kept for irreversible dialogs.
     */
    $min = is_numeric($draftMin) ? (int) $draftMin : (int) $saved['min'];
    $max = is_numeric($draftMax) ? (int) $draftMax : (int) $saved['max'];
    $valid = $active >= $min && $active <= $max && $min <= $max;
    $missing = max(0, $min - $active);
    $extra = max(0, $active - $max);
    $room = max(0, $max - $active);

    [$tone, $icon, $text] = match (true) {
        $creating => ['info', 'info', 'A new group starts without options. Add them once it is created; it can be active once it has '.$min.'–'.$max.'.'],
        $min > $max => ['warning', 'circle-x', 'The minimum is higher than the maximum. Lower it, or raise the maximum.'],
        $extra > 0 => ['warning', 'circle-x', 'Too many active options for this maximum. '.($draftActive ? 'Saving is blocked until you archive or deactivate '.$extra.'.' : 'Archive or deactivate '.$extra.' before the group is active.')],
        $missing > 0 => ['warning', 'circle-x', 'Too few active options for this minimum. '.($draftActive ? 'Saving is blocked until you activate '.$missing.' more.' : 'Activate '.$missing.' more before the group is active.')],
        $room === 0 => ['info', 'info', 'At the maximum. New options are created inactive, and activating one needs a higher maximum.'],
        default => ['success', 'circle-check', 'Valid. '.$room.' more '.\Illuminate\Support\Str::plural('option', $room).' can be activated.'],
    };
@endphp

<div class="rg-admin-card__body rg-admin-rating-group__check">
    <div class="rg-admin-section-label">
        <h2 class="rg-admin-section-label__text">Active options check</h2>
        <span class="rg-admin-section-label__rule" aria-hidden="true"></span>
        <span class="rg-admin-section-label__trailing">{{ $active }} active · {{ $min }}–{{ $max }} allowed</span>
    </div>

    <x-admin.ui.range-slots size="editor" :active="$active" :min="$min" :max="$max" />

    <x-admin.ui.inline-notice :tone="$tone" :icon="$icon">{{ $text }}</x-admin.ui.inline-notice>
</div>
