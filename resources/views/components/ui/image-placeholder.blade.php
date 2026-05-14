@props([
    'label',
    'ratio' => 'square',
])

@php
    $ratioClass = match ($ratio) {
        'video' => 'aspect-video',
        'portrait' => 'aspect-[3/4]',
        default => 'aspect-square',
    };

    $ratioValue = in_array($ratio, ['square', 'video', 'portrait'], true) ? $ratio : 'square';
@endphp

<div
    {{ $attributes->merge([
        'class' => "{$ratioClass} relative flex w-full shrink-0 items-center justify-center overflow-hidden rounded-lg bg-zinc-950 bg-[radial-gradient(circle_at_35%_28%,rgba(192,132,252,0.34),transparent_30%),radial-gradient(circle_at_58%_45%,rgba(217,119,6,0.28),transparent_34%),linear-gradient(135deg,#18181b_0%,#0a0a0f_56%,#21111b_100%)] ring-1 ring-white/10",
    ]) }}
    role="img"
    aria-label="{{ $label }}"
    data-ratio="{{ $ratioValue }}"
>
    <span class="absolute inset-4 rounded-full border border-purple-400/35 bg-zinc-900/45 shadow-[0_0_48px_rgba(168,85,247,0.18)]"></span>
    <span class="absolute inset-x-8 top-1/2 h-px bg-gradient-to-r from-transparent via-amber-300/35 to-transparent"></span>
    <span class="absolute right-4 top-4 size-2 rounded-full bg-purple-300/80 shadow-[0_0_18px_rgba(216,180,254,0.7)]"></span>

    <span class="relative max-w-[80%] text-center text-sm font-semibold text-zinc-100 drop-shadow">
        {{ $label }}
    </span>
</div>
