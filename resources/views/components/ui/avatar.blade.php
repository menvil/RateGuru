@props([
    'src' => null,
    'name' => '',
    'size' => 'md',
])

@php
    use Illuminate\Support\Str;

    $sizeClass = match ($size) {
        'sm' => 'size-8 text-xs',
        'lg' => 'size-12 text-base',
        default => 'size-10 text-sm',
    };

    $initials = collect(preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY))
        ->take(2)
        ->map(fn (string $part): string => Str::of($part)->substr(0, 1)->upper()->toString())
        ->implode('');

    $initials = $initials !== '' ? $initials : '?';
@endphp

<span
    {{ $attributes->merge([
        'class' => "{$sizeClass} inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-zinc-800 font-semibold text-zinc-100 ring-1 ring-white/10",
    ]) }}
    @if (blank($src))
        role="img"
        aria-label="{{ $name !== '' ? $name : $initials }}"
    @endif
>
    @if (filled($src))
        <img
            src="{{ $src }}"
            alt="{{ $name }}"
            class="size-full object-cover"
        >
    @else
        <span aria-hidden="true">{{ $initials }}</span>
    @endif
</span>
