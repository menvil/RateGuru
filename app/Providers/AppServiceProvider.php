<?php

namespace App\Providers;

use App\Models\RatingGroup;
use App\Models\Tag;
use App\Policies\ModerationPolicy;
use App\Services\Images\CloudinaryImageStorage;
use App\Services\Images\ImageStorage;
use App\Services\Images\LocalImageStorage;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Theme\ThemeManager;
use App\Support\Translations\TranslatableField;
use App\Support\View\AppLayoutData;
use App\Support\VisualRegression\PestVisualScreenshotRunner;
use App\Support\VisualRegression\VisualScreenshotRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProjectSettingsManager::class);
        $this->app->singleton(ThemeManager::class);

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

        View::composer(['layouts.app', 'layouts.guest'], function ($view): void {
            $themeManager = app(ThemeManager::class);
            $user = auth()->user();
            $themePreference = $themeManager->preferenceForUser($user);
            $appliedTheme = $themeManager->appliedThemeFromPreference($themePreference);

            $view->with([
                'themePreference' => $themePreference,
                'appliedTheme' => $appliedTheme,
            ]);
        });

        View::composer('layouts.app', function ($view): void {
            $settings = app(ProjectSettingsManager::class)->current();
            $view->with(array_merge(app(AppLayoutData::class)->toArray(), [
                'projectSettings' => $settings,
            ]));
        });

        View::composer('layouts.guest', function ($view): void {
            $view->with('projectSettings', app(ProjectSettingsManager::class)->current());
        });

        View::composer('layouts.partials.app-sidebar-content', function ($view): void {
            $locale = app()->getLocale();
            $activeOrigin = (array) request('origin');
            $activeCuisine = (array) request('cuisine');
            $noFilters = $activeOrigin === [] && $activeCuisine === [];

            // Cached as plain arrays, not Eloquent models: the file cache store's
            // serializable_classes=false setting silently corrupts cached objects
            // on read (unserialize returns __PHP_Incomplete_Class).
            $firstGroupOptions = Cache::remember('sidebar-nav-rating-group-options', 300, function () {
                $firstGroup = RatingGroup::query()
                    ->active()
                    ->orderBy('sort_order')
                    ->with(['options' => fn ($q) => $q->active()->ordered()])
                    ->first();

                if ($firstGroup === null) {
                    return [];
                }

                return $firstGroup->options
                    ->map(fn ($option): array => [
                        'key' => $option->key,
                        'label' => $option->label,
                        'label_translations' => $option->label_translations,
                    ])
                    ->all();
            });

            // Only first group shown in sidebar (second group is a feed-page dropdown only)
            $categories = [
                [
                    'label' => __('ui.feed.all'),
                    'href' => route('feed'),
                    'active' => $noFilters && blank(request('search')) && blank(request('sort')),
                ],
            ];

            foreach ($firstGroupOptions as $option) {
                $categories[] = [
                    'label' => TranslatableField::resolve($option['label_translations'], $option['label'], $locale),
                    'href' => route('feed', ['origin' => [$option['key']]]),
                    'active' => in_array($option['key'], $activeOrigin, true),
                ];
            }

            // Not locale-sensitive: labels/hrefs are built from slugs, not translations.
            $topTags = Cache::remember('sidebar-nav-top-tags', 300, function () {
                return Tag::query()
                    ->whereHas('posts', fn ($q) => $q->published())
                    ->withCount(['posts as published_posts_count' => fn ($q) => $q->published()])
                    ->orderByDesc('published_posts_count')
                    ->orderBy('name')
                    ->limit(5)
                    ->get()
                    ->map(fn ($tag): array => [
                        'label' => '#'.$tag->slug,
                        'href' => route('feed', ['search' => $tag->slug]),
                    ])
                    ->all();
            });

            $fallbackTags = collect(['sample-a', 'sample-b', 'sample-c', 'sample-d', 'sample-e'])
                ->map(fn (string $tag): array => [
                    'label' => '#'.$tag,
                    'href' => route('feed', ['search' => $tag]),
                ])
                ->all();

            $settings = app(ProjectSettingsManager::class);
            $user = auth()->user();

            $navItems = [
                ['label' => __('ui.nav.home'), 'icon' => 'home', 'href' => route('feed'), 'active' => request()->routeIs('feed') && blank(request('sort')) && blank(request('search')) && blank(request('feed')) && $noFilters, 'testid' => null],
                ['label' => __('ui.nav.top'), 'icon' => 'flame', 'href' => route('feed', ['sort' => 'top']), 'active' => request('sort') === 'top', 'testid' => null],
                ['label' => __('ui.nav.new'), 'icon' => 'plus', 'href' => route('feed', ['sort' => 'newest']), 'active' => request('sort') === 'newest', 'testid' => null],
                ['label' => __('ui.nav.hot'), 'icon' => 'trending', 'href' => route('feed', ['sort' => 'hot']), 'active' => request('sort') === 'hot', 'testid' => 'nav-hot'],
            ];

            if ($user !== null) {
                $navItems[] = ['label' => __('ui.nav.following'), 'icon' => 'users', 'href' => route('feed', ['feed' => 'following']), 'active' => request('feed') === 'following', 'testid' => 'nav-following'];
            }

            if ($user !== null && $settings->featureEnabled('show_saved_posts')) {
                $navItems[] = ['label' => __('saved_posts.saved_posts'), 'icon' => 'bookmark', 'href' => route('saved-posts.index'), 'active' => request()->routeIs('saved-posts.index'), 'testid' => 'nav-saved-posts'];
            }

            $view->with([
                'navItems' => $navItems,
                'categories' => $categories,
                'topTags' => $topTags,
                'fallbackTags' => $fallbackTags,
            ]);
        });
    }
}
