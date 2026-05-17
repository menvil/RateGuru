@props(['post'])

<x-ui.card variant="post" data-testid="post-card">
    @if($post->image_url)
        <img
            src="{{ $post->image_url }}"
            alt="{{ $post->title }}"
            class="aspect-video w-full rounded-rgMedia object-cover"
        >
    @else
        <x-ui.image-placeholder label="Food image" ratio="feed" />
    @endif

    <h3 class="mt-3 text-base font-bold text-rg-text">{{ $post->title }}</h3>
</x-ui.card>
