<div class="flex flex-wrap gap-2 pb-1" role="tablist" data-testid="tag-tabs">
    <button
        type="button"
        role="tab"
        wire:click="$set('selected', null)"
        aria-selected="{{ $selected === null ? 'true' : 'false' }}"
        tabindex="{{ $selected === null ? '0' : '-1' }}"
        class="shrink-0 rounded-rgPill border px-3 py-1 text-[13px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent {{ $selected === null ? 'border-rg-accent bg-rg-accent text-rg-onAccent' : 'border-rg-border2 bg-rg-card2 text-rg-text2 hover:border-rg-accent hover:text-rg-text' }}"
    >
        All
    </button>

    @foreach($tags as $tag)
        <button
            type="button"
            role="tab"
            wire:click="$set('selected', '{{ $tag->slug }}')"
            aria-selected="{{ $selected === $tag->slug ? 'true' : 'false' }}"
            tabindex="{{ $selected === $tag->slug ? '0' : '-1' }}"
            class="shrink-0 rounded-rgPill border px-3 py-1 text-[13px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent {{ $selected === $tag->slug ? 'border-rg-accent bg-rg-accent text-rg-onAccent' : 'border-rg-border2 bg-rg-card2 text-rg-text2 hover:border-rg-accent hover:text-rg-text' }}"
        >
            {{ $tag->name }}
        </button>
    @endforeach
</div>
