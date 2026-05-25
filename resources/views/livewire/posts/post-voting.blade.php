<div data-testid="post-voting" class="{{ $variant === 'rail' ? 'flex justify-center' : 'flex flex-wrap items-center gap-2' }}">
    @if($post === null)
        <span data-testid="post-voting-unavailable" class="text-xs text-rg-muted">Voting unavailable</span>
    @else
    @php
        $upActive = $currentVote === 'up';
        $downActive = $currentVote === 'down';
        $score = (int) ($post->score ?? ((int) $post->upvotes_count - (int) $post->downvotes_count));
        $personalScore = $upActive ? 1 : ($downActive ? -1 : 0);
        $baseClass = 'inline-flex min-w-[3.5rem] items-center justify-center gap-1 rounded-rgPill border px-3 py-1.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg disabled:cursor-wait disabled:opacity-60';
        $idleClass = 'border-rg-border bg-rg-card2 text-rg-text2 hover:border-rg-accentBorder hover:bg-rg-cardHover hover:text-rg-text';
        $upClass = $upActive
            ? 'border-rg-goodBorder bg-rg-goodSoft text-rg-good'
            : $idleClass;
        $downClass = $downActive
            ? 'border-rg-dangerBorder bg-rg-dangerSoft text-rg-dangerText'
            : $idleClass;
    @endphp

    @if($variant === 'rail')
        <div class="flex w-8 flex-col items-center gap-1 pt-1" data-testid="post-voting-rail">
            <button
                type="button"
                aria-label="Vote up"
                wire:click="vote('up')"
                wire:target="vote"
                wire:loading.attr="disabled"
                aria-pressed="{{ $upActive ? 'true' : 'false' }}"
                data-state="{{ $upActive ? 'active' : 'idle' }}"
                class="{{ $upActive ? 'text-rg-good' : 'text-rg-muted' }} cursor-pointer rounded-rgSm p-1 transition hover:bg-rg-card2 hover:text-rg-good focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent disabled:cursor-wait disabled:opacity-60"
            >
                <x-ui.icon name="arrow-up" class="size-4" />
            </button>

            <span
                class="{{ $upActive ? 'text-rg-good' : ($downActive ? 'text-rg-accent2' : 'text-rg-text2') }} text-[13px] font-bold"
                title="Your vote"
                aria-label="Your vote {{ $personalScore }}"
            >
                {{ $personalScore }}
            </span>

            <button
                type="button"
                aria-label="Vote down"
                wire:click="vote('down')"
                wire:target="vote"
                wire:loading.attr="disabled"
                aria-pressed="{{ $downActive ? 'true' : 'false' }}"
                data-state="{{ $downActive ? 'active' : 'idle' }}"
                class="{{ $downActive ? 'text-rg-accent2' : 'text-rg-muted' }} cursor-pointer rounded-rgSm p-1 transition hover:bg-rg-card2 hover:text-rg-accent2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent disabled:cursor-wait disabled:opacity-60"
            >
                <x-ui.icon name="arrow-down" class="size-4" />
            </button>
        </div>
    @else
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
    @endif

    @if($error !== '')
        <span data-testid="post-voting-error" class="text-xs text-rg-danger">{{ $error }}</span>
    @endif
</div>
