@props([
    'src' => null,
    'name' => '',
    'size' => 'md',
    'color' => 'purple',
])

@php
    use Illuminate\Support\Str;

    $sizeClass = [
        'sm' => 'size-6 text-[10px]',
        'md' => 'size-7 text-[11px]',
        'lg' => 'size-9 text-sm',
        'xl' => 'size-[42px] text-base',
    ][$size] ?? 'size-7 text-[11px]';

    $colors = [
        'purple' => 'from-rg-accent to-rg-accent2',
        'blue' => 'from-[#2563eb] to-[#60a5fa]',
        'sky' => 'from-[#0284c7] to-[#7dd3fc]',
        'yellow' => 'from-[#b45309] to-[#facc15]',
        'green' => 'from-[#15803d] to-[#86efac]',
    ];

    $colorClass = $colors[$color] ?? $colors['purple'];

    $initials = Str::of(trim($name))->substr(0, 1)->upper()->toString();
    $initials = $initials !== '' ? $initials : '?';
@endphp

<span
    {{ $attributes->merge([
        'class' => "{$sizeClass} inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-to-br {$colorClass} font-bold text-white ring-1 ring-rg-borderSoft",
    ]) }}
    @if (blank($src))
        role="img"
        aria-label="{{ $name !== '' ? $name : $initials }}"
    @endif
>
    @if (filled($src))
        <img src="{{ $src }}" alt="{{ $name }}" class="size-full object-cover">
    @else
        <span aria-hidden="true">{{ $initials }}</span>
    @endif
</span>
