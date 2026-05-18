<div data-testid="post-drawer">
    @if($post)
        @if($post->image_url)
            <img
                src="{{ $post->image_url }}"
                alt="{{ $post->title }}"
                class="aspect-[4/3] w-full rounded-rgCard object-cover"
            >
        @else
            <x-ui.image-placeholder label="Image preview" ratio="video" />
        @endif

        {{ $post->title }}
        {{ $post->description }}
    @elseif($postId)
        Post not found
    @else
        Select a post
    @endif
</div>
