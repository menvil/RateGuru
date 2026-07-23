@props([
    'category',
    'testId' => null,
])

@if ($category->is_active)
    <a
        href="{{ route('feed', ['category' => [$category->slug]]) }}"
        wire:navigate
        x-on:click.stop
        @if($testId) data-testid="{{ $testId }}" @endif
        class="inline-flex rounded-rgPill focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
    >
        <x-ui.badge variant="accent">{{ $category->translatedName() }}</x-ui.badge>
    </a>
@else
    <span @if($testId) data-testid="{{ $testId }}" @endif class="inline-flex">
        <x-ui.badge variant="accent">{{ $category->translatedName() }}</x-ui.badge>
    </span>
@endif
