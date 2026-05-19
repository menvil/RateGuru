<section data-testid="comments-section">
    <h3 class="text-sm font-semibold text-rg-text">Comments</h3>

    <div class="mt-3 space-y-3">
        @foreach ($this->comments as $comment)
            <article wire:key="comment-{{ $comment->id }}" class="text-sm text-rg-text">
                {{ $comment->body }}
            </article>
        @endforeach
    </div>
</section>
