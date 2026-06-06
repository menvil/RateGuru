<?php

namespace App\Providers;

use App\Models\Tag;
use App\Policies\ModerationPolicy;
use App\Services\Images\CloudinaryImageStorage;
use App\Services\Images\ImageStorage;
use App\Services\Images\LocalImageStorage;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\View\AppLayoutData;
use App\Support\VisualRegression\PestVisualScreenshotRunner;
use App\Support\VisualRegression\VisualScreenshotRunner;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProjectSettingsManager::class);

        $this->app->bind(VisualScreenshotRunner::class, PestVisualScreenshotRunner::class);

        $this->app->bind(ImageStorage::class, function () {
            $driver = config('rateguru.images.driver');

            return match ($driver) {
                'local' => $this->app->make(LocalImageStorage::class),
                'cloudinary' => $this->app->make(CloudinaryImageStorage::class),
                default => throw new \InvalidArgumentException("Unsupported image driver: [{$driver}]."),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('moderate-content', [ModerationPolicy::class, 'moderateContent']);
        Gate::define('ban-user', [ModerationPolicy::class, 'banUser']);

        View::composer('layouts.app', function ($view): void {
            $view->with(app(AppLayoutData::class)->toArray());
        });

        View::composer('layouts.partials.app-sidebar', function ($view): void {
            $categories = collect(['All', 'Source A', 'Source B', 'Category A', 'Category B', 'Category C', 'Category D'])
                ->map(function (string $category): array {
                    $slug = str($category)->lower()->slug()->toString();

                    return [
                        'label' => $category,
                        'href' => $category === 'All' ? route('feed') : route('feed', ['category' => $slug]),
                        'active' => $category === 'All' ? blank(request('category')) : request('category') === $slug,
                    ];
                })
                ->all();

            $topTags = Tag::query()
                ->orderBy('name')
                ->limit(5)
                ->get()
                ->map(fn ($tag): array => [
                    'label' => '#'.$tag->slug,
                    'href' => route('feed', ['search' => $tag->slug]),
                ])
                ->all();

            $fallbackTags = collect(['sample-a', 'sample-b', 'sample-c', 'sample-d', 'sample-e'])
                ->map(fn (string $tag): array => [
                    'label' => '#'.$tag,
                    'href' => route('feed', ['search' => $tag]),
                ])
                ->all();

            $view->with([
                'navItems' => [
                    ['label' => 'Home', 'icon' => 'home', 'href' => route('feed'), 'active' => request()->routeIs('feed') && blank(request('sort')) && blank(request('category')) && blank(request('search'))],
                    ['label' => 'Top', 'icon' => 'flame', 'href' => route('feed', ['sort' => 'top']), 'active' => request('sort') === 'top'],
                    ['label' => 'New', 'icon' => 'plus', 'href' => route('feed', ['sort' => 'newest']), 'active' => request('sort') === 'newest'],
                    ['label' => 'Following', 'icon' => 'users', 'href' => '#', 'active' => false],
                ],
                'categories' => $categories,
                'topTags' => $topTags,
                'fallbackTags' => $fallbackTags,
            ]);
        });
    }
}
