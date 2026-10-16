@props([
    'title',
    'icon' => 'circle',
    'initials' => null,
    'time' => null,
    'tone' => 'default',
    'last' => false,
])

@php
    /*
     * DOM-02. One link of a report chain — the report, what it points to,
     * that content's author — read top to bottom: a 32 marker (an icon in a
     * hairline circle, or the person's initials), a hairline down to the next
     * link, the title in 14/500 with an optional time on the right, and under
     * it the link's details and the actions that belong to it. `last` ends
     * the chain without the hairline; `tone="danger"` marks a link in the
     * danger colours.
     *
     * The default slot is the details; the `addon` slot sits beside the
     * title, for a badge.
     */
    if (! in_array($tone, ['default', 'danger'], true)) {
        throw new InvalidArgumentException("Unknown admin timeline tone [{$tone}].");
    }
@endphp

<div {{ $attributes->class(['rg-admin-timeline-item', 'rg-admin-timeline-item--last' => $last, 'rg-admin-timeline-item--danger' => $tone === 'danger']) }}>
    @unless ($last)
        <span class="rg-admin-timeline-item__line" aria-hidden="true"></span>
    @endunless
    <div>
        @if ($initials !== null)
            <span class="rg-admin-avatar rg-admin-avatar--md" aria-hidden="true">{{ $initials }}</span>
        @else
            <span class="rg-admin-timeline-item__marker" aria-hidden="true"><x-admin.ui.icon :name="$icon" :size="16" /></span>
        @endif
    </div>
    <div class="rg-admin-timeline-item__content">
        <div class="rg-admin-timeline-item__head">
            <h3 class="rg-admin-timeline-item__title">{{ $title }}</h3>
            {{ $addon ?? '' }}
            @if ($time !== null)
                <span class="rg-admin-timeline-item__time">{{ $time }}</span>
            @endif
        </div>
        @if (trim((string) $slot) !== '')
            <div class="rg-admin-timeline-item__body">{{ $slot }}</div>
        @endif
    </div>
</div>
