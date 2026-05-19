<section data-testid="comments-section" class="space-y-4">
    <h3 class="text-sm font-semibold text-rg-text">Comments</h3>

    <livewire:comments.comment-form :post-id="$postId" :key="'comment-form-'.$postId" />

    @if ($this->comments->isEmpty())
        <x-ui.empty-state
            title="No comments yet"
            description="Be the first to comment."
        />
    @else
        <div class="space-y-3">
            @foreach ($this->comments as $comment)
                <x-comments.comment-item
                    :comment="$comment"
                    :can-delete="auth()->id() === $comment->user_id"
                    :can-hide="$this->userCanHideComments()"
                    wire:key="comment-{{ $comment->id }}"
                />
            @endforeach
        </div>
    @endif
</section>
