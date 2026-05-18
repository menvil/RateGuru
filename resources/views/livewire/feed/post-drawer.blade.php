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

        <section class="mt-4">
            <h2 class="text-lg font-bold text-rg-text">{{ $post->title }}</h2>

            @if($post->description)
                <p class="mt-2 text-sm leading-relaxed text-rg-muted">{{ $post->description }}</p>
            @endif
        </section>
    @elseif($postId)
        Post not found
    @else
        Select a post
    @endif
</div>
