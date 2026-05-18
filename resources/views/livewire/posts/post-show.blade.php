<div data-testid="post-show" class="mx-auto w-full max-w-2xl">
    <div data-testid="post-show-hero">
        @if($post->image_url)
            <img
                src="{{ $post->image_url }}"
                alt="{{ $post->title }}"
                class="aspect-[16/10] w-full rounded-rgCard object-cover"
            >
        @else
            <x-ui.image-placeholder label="Image preview" ratio="video" />
        @endif
    </div>

    <section class="mt-6">
        <h1 class="text-2xl font-bold text-rg-text sm:text-3xl">{{ $post->title }}</h1>

        @if($post->description)
            <p class="mt-3 text-sm leading-relaxed text-rg-muted">{{ $post->description }}</p>
        @endif
    </section>
</div>
