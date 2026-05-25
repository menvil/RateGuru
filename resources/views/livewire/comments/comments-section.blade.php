<section data-testid="comments-section" class="space-y-4">
    @if ($showHeader)
        <h3 class="text-sm font-semibold text-rg-text">Comments</h3>
    @endif

    <livewire:comments.comment-form :post-id="$postId" :key="'comment-form-'.$postId" />

    <div
        wire:loading
        wire:target="deleteComment,hideComment,refreshComments"
        data-testid="comments-loading"
        class="space-y-2 transition-opacity duration-200"
    >
        <x-ui.skeleton shape="line" width="w-3/4" />
        <x-ui.skeleton shape="line" width="w-1/2" />
    </div>

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
                    :can-delete="$this->canDeleteComment($comment)"
                    :can-hide="$this->userCanHideComments()"
                    wire:key="comment-{{ $comment->id }}"
                />
            @endforeach
        </div>
    @endif
</section>
