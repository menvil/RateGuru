@props([
    'comment',
])

<article data-testid="comment-item" class="rounded-rgCard border border-rg-border bg-rg-card2 p-3">
    <p class="text-sm leading-6 text-rg-text">{{ $comment->body }}</p>
</article>
