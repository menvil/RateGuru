<div data-testid="saved-posts-page" class="mx-auto w-full max-w-[820px] min-w-0 px-4 py-6 sm:px-6">
    <h1 class="mb-6 text-xl font-bold text-rg-text">{{ __('saved_posts.page_title') }}</h1>

    @if($savedPosts->isEmpty())
        <div data-testid="saved-posts-empty-state" class="flex flex-col items-center justify-center py-16 text-center">
            <x-ui.icon name="bookmark" class="mb-4 size-10 text-rg-muted" />
            <p class="text-base font-semibold text-rg-text">{{ __('saved_posts.empty_title') }}</p>
            <p class="mt-1 text-sm text-rg-muted">{{ __('saved_posts.empty_description') }}</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach($savedPosts as $post)
                <x-feed.post-card :post="$post" />
            @endforeach
        </div>

        <div class="mt-6">
            {{ $savedPosts->links() }}
        </div>
    @endif
</div>
