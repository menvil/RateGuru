@props([
    'comment',
    'canDelete' => false,
])

<article data-testid="comment-item" class="rounded-rgCard border border-rg-border bg-rg-card2 p-3">
    <div class="flex items-center gap-2">
        <x-ui.avatar
            :src="$comment->user?->avatar_url"
            :name="$comment->user?->name ?? 'User'"
            size="sm"
        />

        <div class="min-w-0">
            <span class="text-sm font-semibold text-rg-text">{{ $comment->user?->name ?? 'Unknown user' }}</span>

            @if ($comment->user?->username)
                <span class="text-xs text-rg-muted">{{ '@' . $comment->user->username }}</span>
            @endif
        </div>

        @if ($comment->created_at)
            <time
                datetime="{{ $comment->created_at->toIso8601String() }}"
                class="ml-auto text-xs text-rg-muted"
            >
                {{ $comment->created_at->diffForHumans() }}
            </time>
        @endif
    </div>

    <p class="mt-2 text-sm leading-6 text-rg-text">{{ $comment->body }}</p>

    @if ($canDelete)
        <div class="mt-2 flex justify-end">
            <button
                type="button"
                wire:click="deleteComment({{ $comment->id }})"
                wire:confirm="Delete this comment?"
                class="text-xs font-semibold text-rg-muted transition hover:text-rg-dangerText"
            >
                Delete
            </button>
        </div>
    @endif
</article>
