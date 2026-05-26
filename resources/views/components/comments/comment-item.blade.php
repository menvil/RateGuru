@props([
    'comment',
    'canDelete' => false,
    'canHide' => false,
    'canReply' => false,
])

<article data-testid="comment-item" class="grid grid-cols-[32px_minmax(0,1fr)] gap-2.5 text-[13px]">
    <x-ui.avatar
        :src="$comment->user?->avatar_url"
        :name="$comment->user?->name ?? 'User'"
        size="md"
    />

    <div class="min-w-0">
        <p>
            <span class="font-semibold text-rg-text">
                {{ $comment->user?->username ? '@'.$comment->user->username : ($comment->user?->name ?? 'Unknown user') }}
            </span>

            @if ($comment->created_at)
                <time datetime="{{ $comment->created_at->toIso8601String() }}" class="text-xs text-rg-muted">
                    {{ $comment->created_at->diffForHumans() }}
                </time>
            @endif
        </p>

        <p class="mt-1 break-words leading-5 text-rg-text2">{{ $comment->body }}</p>

        <div class="mt-2 flex items-center gap-3">
            @auth
                @if($canReply)
                    <button
                        type="button"
                        wire:click="startReply({{ $comment->id }})"
                        class="cursor-pointer text-xs font-semibold text-rg-muted transition hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    >
                        Reply
                    </button>
                @endif
            @endauth

            @if ($comment->exists && auth()->id() !== $comment->user_id)
                <div data-testid="comment-report">
                    <livewire:reports.report-modal
                        reportable-type="comment"
                        :reportable-id="$comment->id"
                        :key="'comment-report-'.$comment->id"
                    />
                </div>
            @endif
        </div>

        @if ($canDelete || $canHide)
            <div class="mt-2 flex gap-3">
                @if ($canHide)
                    <button
                        type="button"
                        wire:click="hideComment({{ $comment->id }})"
                        wire:confirm="Hide this comment?"
                        class="cursor-pointer text-xs font-semibold text-rg-muted transition hover:text-rg-dangerText"
                    >
                        Hide
                    </button>
                @endif

                @if ($canDelete)
                    <button
                        type="button"
                        wire:click="deleteComment({{ $comment->id }})"
                        wire:confirm="Delete this comment?"
                        class="cursor-pointer text-xs font-semibold text-rg-muted transition hover:text-rg-dangerText"
                    >
                        Delete
                    </button>
                @endif
            </div>
        @endif
    </div>
</article>
