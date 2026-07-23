<?php

namespace App\Providers;

use App\Enums\PostStatus;
use App\Models\Category;
use App\Models\Tag;
use App\Policies\ModerationPolicy;
use App\Policies\ProjectSettingsPolicy;
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
        Gate::define('manage-project-settings', [ProjectSettingsPolicy::class, 'manage']);

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
            $activeCategories = (array) request('category');
            $activeRatings = (array) request('ratings');
            $noFilters = $activeCategories === [] && $activeRatings === [] && blank(request('tag'));

            // Cached as plain arrays, not Eloquent models: the file cache store's
            // serializable_classes=false setting silently corrupts cached objects
            // on read (unserialize returns __PHP_Incomplete_Class).
            $sidebarCategories = Cache::remember('sidebar-nav-categories', 300, function () {
                return Category::query()
                    ->active()
                    ->ordered()
                    ->get()
                    ->map(fn (Category $category): array => [
                        'slug' => $category->slug,
                        'name' => $category->name,
                        'name_translations' => $category->name_translations,
                    ])
                    ->all();
            });

            $categories = [
                [
                    'label' => __('ui.feed.all'),
                    'href' => route('feed'),
                    'active' => $noFilters && blank(request('search')) && blank(request('sort')) && blank(request('feed')),
                ],
            ];

            foreach ($sidebarCategories as $category) {
                $categories[] = [
                    'label' => TranslatableField::resolve(
                        $category['name_translations'],
                        $category['name'],
                        $locale,
                    ),
                    'href' => route('feed', ['category' => [$category['slug']]]),
                    'active' => in_array($category['slug'], $activeCategories, true),
                ];
            }

            // Not locale-sensitive: labels/hrefs are built from slugs, not translations.
            $topTags = Cache::remember('sidebar-nav-top-tags', 300, function () {
                return Tag::query()
                    ->whereHas('posts', fn ($query) => $query->where('status', PostStatus::Published))
                    ->withCount(['posts as published_posts_count' => fn ($query) => $query->where('status', PostStatus::Published)])
                    ->orderByDesc('published_posts_count')
                    ->orderBy('name')
                    ->limit(5)
                    ->get()
                    ->map(fn ($tag): array => [
                        'label' => '#'.$tag->slug,
                        'href' => route('feed', ['tag' => $tag->slug]),
                    ])
                    ->all();
            });

            $fallbackTags = collect(['sample-a', 'sample-b', 'sample-c', 'sample-d', 'sample-e'])
                ->map(fn (string $tag): array => [
                    'label' => '#'.$tag,
                    'href' => route('feed', ['tag' => $tag]),
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
