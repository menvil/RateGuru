@props(['post'])

<x-ui.card
    variant="post"
    data-testid="post-card"
    role="button"
    tabindex="0"
    wire:click="$dispatch('open-post-drawer', { postId: {{ $post->id }} })"
    wire:keydown.enter="$dispatch('open-post-drawer', { postId: {{ $post->id }} })"
    wire:keydown.space.prevent="$dispatch('open-post-drawer', { postId: {{ $post->id }} })"
    class="cursor-pointer"
>
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

    <div class="mt-3">
        <h3 class="text-base font-bold text-rg-text">{{ $post->title }}</h3>
        @if($post->truncated_description)
            <p class="mt-1 text-[13px] leading-snug text-rg-muted">{{ $post->truncated_description }}</p>
        @endif
    </div>

    <footer class="mt-3 border-t border-rg-border pt-2.5">
        <div class="flex items-center gap-4 text-xs text-rg-muted">
            <span>Score <span class="font-semibold text-rg-text2">{{ $post->score }}</span></span>
            <span>{{ $post->comments_count ?? 0 }} comments</span>
        </div>
        <div class="mt-2 flex flex-wrap gap-2">
            <x-ui.badge>Homemade {{ $post->homemade_votes_count ?? 0 }}</x-ui.badge>
            <x-ui.badge>Restaurant {{ $post->restaurant_votes_count ?? 0 }}</x-ui.badge>
        </div>
        @if($post->exists)
            <div data-testid="post-card-voting" class="mt-2.5" wire:click.stop wire:keydown.stop>
                <livewire:posts.post-voting
                    :post-id="$post->id"
                    :key="'post-card-voting-'.$post->id"
                />
            </div>
            <div data-testid="post-card-origin-voting" class="mt-2.5" wire:click.stop wire:keydown.stop>
                <livewire:posts.origin-voting
                    :post-id="$post->id"
                    :key="'post-card-origin-voting-'.$post->id"
                />
            </div>
            <div data-testid="post-card-report" class="mt-2.5 flex justify-end" wire:click.stop wire:keydown.stop>
                <livewire:reports.report-modal
                    reportable-type="post"
                    :reportable-id="$post->id"
                    :key="'post-card-report-'.$post->id"
                />
            </div>
            <div data-testid="post-card-moderation" wire:click.stop wire:keydown.stop>
                <livewire:moderation.inline-post-moderation
                    :post-id="$post->id"
                    :key="'post-card-moderation-'.$post->id"
                />
            </div>
        @endif
    </footer>
</x-ui.card>
