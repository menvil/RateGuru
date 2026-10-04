@props([
    'title',
    'icon' => 'search-x',
    'tone' => 'neutral',
    'headingLevel' => 3,
])

@php
    /*
     * Tells a finished queue (tone success) from a search that found nothing
     * (tone neutral). The action slot is only for something that changes the
     * outcome, such as Clear filters.
     */
    if (! in_array($tone, ['neutral', 'success'], true)) {
        throw new InvalidArgumentException("Unknown admin empty state tone [{$tone}].");
    }

    $heading = 'h'.max(1, min(6, (int) $headingLevel));
@endphp

<div {{ $attributes->class(['rg-admin-empty-state']) }}>
    <span @class(['rg-admin-empty-state__icon', 'rg-admin-empty-state__icon--success' => $tone === 'success'])>
        <x-admin.ui.icon :name="$icon" :size="18" />
    </span>
    <{{ $heading }} class="rg-admin-empty-state__title">{{ $title }}</{{ $heading }}>
    @if ($slot->isNotEmpty())
        <p class="rg-admin-empty-state__body">{{ $slot }}</p>
    @endif
    @isset($action)
        <div class="rg-admin-empty-state__action">{{ $action }}</div>
    @endisset
</div>
