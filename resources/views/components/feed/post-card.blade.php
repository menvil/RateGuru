@props(['post'])

<x-ui.card variant="post" data-testid="post-card">
    <div class="flex items-center gap-2">
        <x-ui.avatar :name="$post->user?->name ?? 'User'" size="md" />
        <div class="min-w-0">
            <span class="block text-[13px] font-semibold text-rg-text">{{ $post->user?->name ?? 'Unknown user' }}</span>
            @if($post->user?->username)
                <span class="block text-xs text-rg-muted">{{ '@' . $post->user->username }}</span>
            @endif
        </div>
    </div>

    @if($post->image_url)
        <img
            src="{{ $post->image_url }}"
            alt="{{ $post->title }}"
            class="mt-3 aspect-video w-full rounded-rgMedia object-cover"
        >
    @else
        <div class="mt-3">
            <x-ui.image-placeholder label="Food image" ratio="feed" />
        </div>
    @endif

    <h3 class="mt-3 text-base font-bold text-rg-text">{{ $post->title }}</h3>
</x-ui.card>
