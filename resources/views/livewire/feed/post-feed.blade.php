<div>
    <div wire:loading class="grid gap-4 transition-opacity duration-200 sm:gap-5" data-testid="post-feed-loading">
        <x-ui.card variant="post">
            <x-ui.skeleton shape="block" height="h-48" />
            <x-ui.skeleton shape="line" width="w-3/4" class="mt-3" />
            <x-ui.skeleton shape="line" width="w-1/2" class="mt-2" />
        </x-ui.card>
    </div>

    <div wire:loading.remove class="grid gap-4 transition-opacity duration-200 sm:gap-5">
        @if($posts->isEmpty())
            <x-ui.empty-state
                title="No posts yet"
                description="Published posts will appear here."
            />
        @else
            @foreach($posts as $post)
                <x-feed.post-card
                    :post="$post"
                    :selected="$selectedPostId === $post->id"
                    :rating-voting-state="$ratingVotingStates[$post->id] ?? []"
                    :can-delete-post="$deletePermissions[$post->id] ?? false"
                    :can-report-post="$reportPermissions[$post->id] ?? false"
                    :can-moderate-post="$moderationPermissions[$post->id] ?? false"
                    wire:key="{{ $post->id }}"
                />
            @endforeach
        @endif
    </div>
</div>
