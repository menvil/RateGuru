@props(['provider'])

@php
    $paths = [
        'facebook'  => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
        'x'         => '<path d="m4 4 5.5 7.4L4 20h1.9l4.6-5.4 4.7 5.4H21l-5.9-7.9 5.3-8h-1.9l-4.4 5-4.2-5H4z"/>',
        'telegram'  => '<path d="m22 2-7 20-4-9-9-4 20-7z"/><path d="M22 2 11 13"/>',
        'whatsapp'  => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"/>',
        'reddit'    => '<circle cx="12" cy="12" r="9"/><circle cx="9.5" cy="13" r="1"/><circle cx="14.5" cy="13" r="1"/><path d="M8.5 9.5a4 4 0 0 1 7 0"/><path d="M9.5 17c.8.5 1.6.8 2.5.8s1.7-.3 2.5-.8"/>',
        'pinterest' => '<circle cx="12" cy="12" r="9"/><path d="M10 17V7h3a3 3 0 0 1 0 6h-3"/>',
        'email'     => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 7L2 7"/>',
    ];

    $svgPath = $paths[$provider] ?? '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>';
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
    {!! $svgPath !!}
</svg>
