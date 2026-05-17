@props(['post'])

<x-ui.card variant="post" data-testid="post-card">
    <h3 class="text-base font-bold text-rg-text">{{ $post->title }}</h3>
</x-ui.card>
