<section data-testid="comments-section" class="space-y-4">
    <h3 class="text-sm font-semibold text-rg-text">Comments</h3>

    <livewire:comments.comment-form :post-id="$postId" :key="'comment-form-'.$postId" />

    <div class="space-y-3">
        @foreach ($this->comments as $comment)
            <x-comments.comment-item :comment="$comment" wire:key="comment-{{ $comment->id }}" />
        @endforeach
    </div>
</section>
