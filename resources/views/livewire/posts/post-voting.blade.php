<div data-testid="post-voting" class="flex items-center gap-2">
    @if($post === null)
        <span data-testid="post-voting-unavailable" class="text-xs text-rg-muted">Voting unavailable</span>
    @else
    <button
        type="button"
        wire:click="vote('up')"
        wire:target="vote"
        wire:loading.attr="disabled"
        wire:loading.class="opacity-60 cursor-wait"
        class="inline-flex min-w-[3.5rem] items-center justify-center gap-1 rounded-rgPill border border-rg-border bg-rg-card2 px-3 py-1.5 text-sm font-semibold text-rg-text transition hover:border-rg-accentBorder disabled:cursor-wait disabled:opacity-60"
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
        class="inline-flex min-w-[3.5rem] items-center justify-center gap-1 rounded-rgPill border border-rg-border bg-rg-card2 px-3 py-1.5 text-sm font-semibold text-rg-text transition hover:border-rg-accentBorder disabled:cursor-wait disabled:opacity-60"
    >
        <span wire:loading.remove wire:target="vote">▼ Down {{ $post->downvotes_count }}</span>
        <span wire:loading wire:target="vote">…</span>
    </button>
    @endif

    @if($error !== '')
        <span data-testid="post-voting-error" class="text-xs text-rg-danger">{{ $error }}</span>
    @endif
</div>
