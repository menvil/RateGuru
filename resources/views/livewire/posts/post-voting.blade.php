<div data-testid="post-voting" class="flex flex-wrap items-center gap-2">
    @if($post === null)
        <span data-testid="post-voting-unavailable" class="text-xs text-rg-muted">Voting unavailable</span>
    @else
    @php
        $upActive = $currentVote === 'up';
        $downActive = $currentVote === 'down';
        $baseClass = 'inline-flex min-w-[3.5rem] items-center justify-center gap-1 rounded-rgPill border px-3 py-1.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg disabled:cursor-wait disabled:opacity-60';
        $idleClass = 'border-rg-border bg-rg-card2 text-rg-text2 hover:border-rg-accentBorder hover:bg-rg-cardHover hover:text-rg-text';
        $upClass = $upActive
            ? 'border-rg-goodBorder bg-rg-goodSoft text-rg-good'
            : $idleClass;
        $downClass = $downActive
            ? 'border-rg-dangerBorder bg-rg-dangerSoft text-rg-dangerText'
            : $idleClass;
    @endphp

    <button
        type="button"
        wire:click="vote('up')"
        wire:target="vote"
        wire:loading.attr="disabled"
        wire:loading.class="opacity-60 cursor-wait"
        aria-pressed="{{ $upActive ? 'true' : 'false' }}"
        data-state="{{ $upActive ? 'active' : 'idle' }}"
        class="{{ $baseClass }} {{ $upClass }}"
    >
        <span wire:loading.remove wire:target="vote">▲ Up {{ $post->upvotes_count }}</span>
        <span wire:loading wire:target="vote">…</span>
    </button>

    <button
        type="button"
        wire:click="vote('down')"
        wire:target="vote"
        wire:loading.attr="disabled"
        wire:loading.class="opacity-60 cursor-wait"
        aria-pressed="{{ $downActive ? 'true' : 'false' }}"
        data-state="{{ $downActive ? 'active' : 'idle' }}"
        class="{{ $baseClass }} {{ $downClass }}"
    >
        <span wire:loading.remove wire:target="vote">▼ Down {{ $post->downvotes_count }}</span>
        <span wire:loading wire:target="vote">…</span>
    </button>
    @endif

    @if($error !== '')
        <span data-testid="post-voting-error" class="text-xs text-rg-danger">{{ $error }}</span>
    @endif
</div>
