<?php

use Symfony\Component\Finder\Finder;

it('does not contain forbidden food domain words in active ui code', function () {
    $roots = [
        app_path('Actions'),
        app_path('Http'),
        app_path('Livewire'),
        resource_path('views'),
    ];

    $allowedLegacyPaths = collect([
        app_path('Actions/Counters/RecalculatePostCountersAction.php'),
        app_path('Actions/Posts/CreatePostAction.php'),
        app_path('Actions/Votes/VoteCuisineAction.php'),
        app_path('Livewire/Feed/FeedPage.php'),
        app_path('Livewire/Feed/PostDrawer.php'),
        app_path('Livewire/Feed/PostFeed.php'),
        app_path('Livewire/Feed/UploadPostForm.php'),
        app_path('Livewire/Posts/CategoryVoting.php'),
        app_path('Livewire/Posts/CuisineVoting.php'),
        app_path('Livewire/Posts/OriginVoting.php'),
        app_path('Livewire/Posts/PostShow.php'),
        app_path('Livewire/Posts/SourceVoting.php'),
        app_path('Http/Requests/StorePostRequest.php'),
        resource_path('views/components/feed/post-card.blade.php'),
        resource_path('views/components/ui/dish-placeholder.blade.php'),
        resource_path('views/components/ui/image-placeholder.blade.php'),
        resource_path('views/livewire/feed/feed-page.blade.php'),
        resource_path('views/livewire/feed/post-drawer.blade.php'),
        resource_path('views/livewire/feed/post-feed.blade.php'),
        resource_path('views/livewire/feed/upload-post-form.blade.php'),
        resource_path('views/livewire/posts/category-voting.blade.php'),
        resource_path('views/livewire/posts/cuisine-voting.blade.php'),
        resource_path('views/livewire/posts/origin-voting.blade.php'),
        resource_path('views/livewire/posts/post-show.blade.php'),
        resource_path('views/livewire/posts/source-voting.blade.php'),
    ])->map(fn (string $path): string => realpath($path) ?: $path);

    $files = Finder::create()
        ->files()
        ->in($roots)
        ->exclude('dev')
        ->name(['*.php', '*.blade.php']);

    $content = collect($files)
        ->reject(fn (SplFileInfo $file): bool => $allowedLegacyPaths->contains($file->getRealPath()))
        ->map(fn (SplFileInfo $file): string => strtolower(file_get_contents($file->getRealPath())))
        ->implode("\n");

    foreach ([
        'dish',
        'dishes',
        'cuisine',
        'homemade',
        'restaurant',
        'food photo',
    ] as $forbiddenWord) {
        expect($content)->not->toContain($forbiddenWord);
    }
});
