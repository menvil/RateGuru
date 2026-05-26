<div
    data-testid="post-voting"
    x-on:click.stop
    x-on:keydown.stop
    class="{{ in_array($variant, ['rail', 'pill'], true) ? 'flex justify-center' : 'flex flex-wrap items-center gap-2' }}"
>
    @if($post === null)
        <span data-testid="post-voting-unavailable" class="text-xs text-rg-muted">Voting unavailable</span>
    @else
    @php
        $baseClass = 'inline-flex min-w-[3.5rem] items-center justify-center gap-1 rounded-rgControl border px-3 py-1.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg disabled:cursor-not-allowed disabled:opacity-60';
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
                wire:click.stop="vote('up')"
                wire:target="vote"
                wire:loading.attr="disabled"
                @disabled($votingDisabled)
                aria-pressed="{{ $upActive ? 'true' : 'false' }}"
                data-state="{{ $upActive ? 'active' : 'idle' }}"
                class="{{ $upActive ? 'text-rg-good' : 'text-rg-muted' }} cursor-pointer rounded-rgSm p-1 transition hover:bg-rg-card2 hover:text-rg-good focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent disabled:cursor-not-allowed disabled:opacity-60"
            >
                <x-ui.icon name="arrow-up" class="size-4" />
            </button>

            <span
                class="{{ $upActive ? 'text-rg-good' : ($downActive ? 'text-rg-accent2' : 'text-rg-text2') }} text-[13px] font-bold"
                title="Score"
                aria-label="Score {{ $score }}"
            >
                {{ $score }}
            </span>

            <button
                type="button"
                aria-label="Vote down"
                wire:click.stop="vote('down')"
                wire:target="vote"
                wire:loading.attr="disabled"
                @disabled($votingDisabled)
                aria-pressed="{{ $downActive ? 'true' : 'false' }}"
                data-state="{{ $downActive ? 'active' : 'idle' }}"
                class="{{ $downActive ? 'text-rg-accent2' : 'text-rg-muted' }} cursor-pointer rounded-rgSm p-1 transition hover:bg-rg-card2 hover:text-rg-accent2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent disabled:cursor-not-allowed disabled:opacity-60"
            >
                <x-ui.icon name="arrow-down" class="size-4" />
            </button>
        </div>
    @elseif($variant === 'pill')
        <div class="inline-flex items-center gap-1.5 rounded-rgPill border border-rg-border2 bg-rg-card2 px-2.5 py-1" data-testid="post-voting-pill">
            <button
                type="button"
                aria-label="Vote up"
                wire:click.stop="vote('up')"
                wire:target="vote"
                wire:loading.attr="disabled"
                @disabled($votingDisabled)
                aria-pressed="{{ $upActive ? 'true' : 'false' }}"
                data-state="{{ $upActive ? 'active' : 'idle' }}"
                class="{{ $upActive ? 'text-rg-good' : 'text-rg-muted' }} cursor-pointer rounded-rgSm p-0.5 transition hover:text-rg-good focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent disabled:cursor-not-allowed disabled:opacity-60"
            >
                <x-ui.icon name="arrow-up" class="size-4" />
            </button>

            <span
                class="{{ $upActive ? 'text-rg-good' : ($downActive ? 'text-rg-accent2' : 'text-rg-text') }} min-w-4 text-center text-[13px] font-bold"
                title="Score"
                aria-label="Score {{ $score }}"
            >
                {{ $score }}
            </span>

            <button
                type="button"
                aria-label="Vote down"
                wire:click.stop="vote('down')"
                wire:target="vote"
                wire:loading.attr="disabled"
                @disabled($votingDisabled)
                aria-pressed="{{ $downActive ? 'true' : 'false' }}"
                data-state="{{ $downActive ? 'active' : 'idle' }}"
                class="{{ $downActive ? 'text-rg-accent2' : 'text-rg-muted' }} cursor-pointer rounded-rgSm p-0.5 transition hover:text-rg-accent2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent disabled:cursor-not-allowed disabled:opacity-60"
            >
                <x-ui.icon name="arrow-down" class="size-4" />
            </button>
        </div>
    @else
    <button
        type="button"
        wire:click.stop="vote('up')"
        wire:target="vote"
        wire:loading.attr="disabled"
        wire:loading.class="opacity-60 cursor-not-allowed"
        @disabled($votingDisabled)
        aria-pressed="{{ $upActive ? 'true' : 'false' }}"
        data-state="{{ $upActive ? 'active' : 'idle' }}"
        class="{{ $baseClass }} {{ $upClass }}"
    >
        <span wire:loading.remove wire:target="vote">▲ Up {{ $post->upvotes_count }}</span>
        <span wire:loading wire:target="vote">…</span>
    </button>

    <button
        type="button"
        wire:click.stop="vote('down')"
        wire:target="vote"
        wire:loading.attr="disabled"
        wire:loading.class="opacity-60 cursor-not-allowed"
        @disabled($votingDisabled)
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
    @elseif(($isOwnPost ?? false) && $variant !== 'rail')
        <span data-testid="post-voting-error" class="text-xs text-rg-muted">You cannot vote on your own post.</span>
    @endif
</div>
