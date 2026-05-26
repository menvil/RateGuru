<div
    data-testid="comment-voting"
    x-on:click.stop
    x-on:keydown.stop
    class="flex items-center gap-1.5"
>
    @if($comment === null)
        <span data-testid="comment-voting-unavailable" class="text-xs text-rg-muted">Voting unavailable</span>
    @else
        @php
            $upActive = $currentVote === 'up';
            $downActive = $currentVote === 'down';
            $votingDisabled = $isOwnComment;
            $score = (int) ($comment->score ?? ((int) $comment->upvotes_count - (int) $comment->downvotes_count));
        @endphp

        <button
            type="button"
            aria-label="Vote up"
            wire:click.stop="vote('up')"
            wire:target="vote"
            wire:loading.attr="disabled"
            @disabled($votingDisabled)
            aria-pressed="{{ $upActive ? 'true' : 'false' }}"
            data-state="{{ $upActive ? 'active' : 'idle' }}"
            class="{{ $upActive ? 'text-rg-good' : 'text-rg-muted' }} cursor-pointer bg-transparent p-0.5 transition hover:text-rg-good focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent disabled:cursor-not-allowed disabled:opacity-60"
        >
            <x-ui.icon name="arrow-up" class="size-3.5" />
        </button>

        <span class="{{ $upActive ? 'text-rg-good' : ($downActive ? 'text-rg-accent2' : 'text-rg-text2') }} text-[12.5px] font-semibold">
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
            class="{{ $downActive ? 'text-rg-accent2' : 'text-rg-muted' }} cursor-pointer bg-transparent p-0.5 transition hover:text-rg-accent2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent disabled:cursor-not-allowed disabled:opacity-60"
        >
            <x-ui.icon name="arrow-down" class="size-3.5" />
        </button>
    @endif

    @if($error !== '')
        <span data-testid="comment-voting-error" class="text-xs text-rg-danger">{{ $error }}</span>
    @endif
</div>
