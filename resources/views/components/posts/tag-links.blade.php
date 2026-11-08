@props([
    'tags',
    'testId' => null,
])

{{-- A post's tags, each opening the feed filtered by it. Named by slug, as the
     sidebar's top tags and the feed's tag filter name them, so a tag reads the
     same wherever it appears. --}}
<ul @if($testId) data-testid="{{ $testId }}" @endif class="flex flex-wrap gap-2">
    @foreach ($tags as $tag)
        <li>
            <a
                href="{{ route('feed', ['tag' => $tag->slug]) }}"
                wire:navigate
                x-on:click.stop
                data-testid="post-tag-link"
                class="inline-flex rounded-rgPill border border-rg-border bg-rg-card px-2.5 py-1 text-xs font-semibold text-rg-text2 transition hover:border-rg-accent hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
            >#{{ $tag->slug }}</a>
        </li>
    @endforeach
</ul>
