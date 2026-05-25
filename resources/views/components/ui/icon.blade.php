@props(['name'])

@php
    $icons = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/>',
        'flame' => '<path d="M12 22c4 0 7-3 7-7 0-3-2-5-4-7 .2 2-.6 3.4-2 4-1-3-3-5-5-6 .3 3-2 5-2 9 0 4 3 7 6 7Z"/>',
        'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'upload' => '<path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M5 20h14"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 7h18s-3 0-3-7"/><path d="M10 21h4"/>',
        'comment' => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z"/>',
        'share' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4"/><path d="m15.4 6.5-6.8 4"/>',
        'save' => '<path d="M19 21 12 17 5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2Z"/>',
        'more' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
        'arrow-up' => '<path d="m6 15 6-6 6 6"/>',
        'arrow-down' => '<path d="m6 9 6 6 6-6"/>',
        'image' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10.5" r="1.5"/><path d="m21 15-5-5L5 19"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'bookmark' => '<path d="M19 21 12 17 5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2Z"/>',
        'leaf' => '<path d="M11 20A7 7 0 0 1 4 13c0-5 4-8 12-9 1 8-2 12-7 12-2 0-4-1-5-3"/><path d="M4 20c2-4 5-7 10-9"/>',
        'chef' => '<path d="M6 13.5h12"/><path d="M7 13.5 8 21h8l1-7.5"/><path d="M8 8a4 4 0 0 1 8 0 3 3 0 1 1 1 5H7a3 3 0 1 1 1-5Z"/>',
    ];
@endphp

<svg
    {{ $attributes->class(['size-4']) }}
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
>
    {!! $icons[$name] ?? $icons['more'] !!}
</svg>
