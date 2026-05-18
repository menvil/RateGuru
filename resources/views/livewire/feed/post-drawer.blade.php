<div data-testid="post-drawer">
    @if($post)
        {{ $post->title }}
        {{ $post->description }}
    @elseif($postId)
        Post not found
    @else
        Select a post
    @endif
</div>
