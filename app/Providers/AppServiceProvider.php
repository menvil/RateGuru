<?php

namespace App\Providers;

use App\Enums\AuthModalMode;
use App\Enums\PostStatus;
use App\Enums\ThemePreference;
use App\Http\Middleware\EnsureGamesAreEnabled;
use App\Http\Middleware\RequireSignInWhileGuestViewingIsOff;
use App\Models\Category;
use App\Models\Tag;
use App\Policies\GamePolicy;
use App\Policies\MediaDiagnosticsPolicy;
use App\Policies\ModerationPolicy;
use App\Policies\PersonaPolicy;
use App\Policies\ProjectSettingsPolicy;
use App\Policies\StudioPlanPolicy;
use App\Queries\SocialProvidersInUseQuery;
use App\Services\Media\FilesystemMediaStorage;
use App\Services\Media\FilesystemMediaUrlResolver;
use App\Services\Media\GdImageIngestor;
use App\Services\Media\GdImageVariantProcessor;
use App\Services\Media\ImageIngestor;
use App\Services\Media\ImageVariantProcessor;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaUrlResolver;
use App\Support\Auth\RememberSessionGenerationOnLogin;
use App\Support\Auth\SocialProviderAvailability;
use App\Support\Feed\FeedAddress;
use App\Support\Games\Play\ClaimGameAttemptsOnSignIn;
use App\Support\Import\Dns\DnsHostResolver;
use App\Support\Import\Dns\HostResolver;
use App\Support\Import\ImportHttpTransport;
use App\Support\Import\PinnedImportHttpTransport;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\StudioAi\OpenAiStudioDiscussionGenerator;
use App\Support\StudioAi\OpenAiStudioImageAnalyzer;
use App\Support\StudioAi\OpenAiStudioPostTextGenerator;
use App\Support\StudioAi\StudioDiscussionGenerator;
use App\Support\StudioAi\StudioImageAnalyzer;
use App\Support\StudioAi\StudioPostTextGenerator;
use App\Support\Theme\ThemeManager;
use App\Support\Translations\Telemetry\TranslationAiCallRecorder;
use App\Support\Translations\TranslatableField;
use App\Support\View\AppLayoutData;
use App\Support\VisualRegression\PestVisualScreenshotRunner;
use App\Support\VisualRegression\VisualScreenshotRunner;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not singleton: the settings are cached for one request or
        // one queued job, never for the life of a queue worker, which would
        // otherwise keep serving the languages and defaults it started with.
        // ThemeManager holds the settings manager, so it has to be scoped too —
        // a singleton would capture the first scope's instance for good.
        $this->app->scoped(ProjectSettingsManager::class);
        $this->app->scoped(ThemeManager::class);

        $this->app->bind(VisualScreenshotRunner::class, PestVisualScreenshotRunner::class);

        $this->app->singleton(MediaStorage::class, FilesystemMediaStorage::class);
        $this->app->singleton(MediaUrlResolver::class, FilesystemMediaUrlResolver::class);
        $this->app->singleton(ImageIngestor::class, GdImageIngestor::class);
        $this->app->singleton(ImageVariantProcessor::class, GdImageVariantProcessor::class);

        $this->app->singleton(HostResolver::class, DnsHostResolver::class);
        $this->app->singleton(ImportHttpTransport::class, PinnedImportHttpTransport::class);

        // A singleton, so a provider call is recorded once however often its
        // result is handed to the recorder within a request or a worker.
        $this->app->singleton(TranslationAiCallRecorder::class);

        // Reads its settings on every call, so it holds nothing worth keeping
        // and needs none of them to be built: a missing key never fails boot.
        $this->app->bind(StudioImageAnalyzer::class, OpenAiStudioImageAnalyzer::class);
        $this->app->bind(StudioPostTextGenerator::class, OpenAiStudioPostTextGenerator::class);
        $this->app->bind(StudioDiscussionGenerator::class, OpenAiStudioDiscussionGenerator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Stamps every signed-in session with its account's session
        // generation; EnsureSessionGenerationIsCurrent ends older ones.
        Event::listen(Login::class, RememberSessionGenerationOnLogin::class);
        Event::listen([Registered::class, Login::class], ClaimGameAttemptsOnSignIn::class);

        // A component drawn on the feed, a post or a profile keeps that
        // page's guest check on every later request it makes.
        Livewire::addPersistentMiddleware([RequireSignInWhileGuestViewingIsOff::class, EnsureGamesAreEnabled::class]);

        Gate::define('moderate-content', [ModerationPolicy::class, 'moderateContent']);
        Gate::define('ban-user', [ModerationPolicy::class, 'banUser']);
        Gate::define('manage-project-settings', [ProjectSettingsPolicy::class, 'manage']);
        Gate::define('view-media-diagnostics', [MediaDiagnosticsPolicy::class, 'view']);
        Gate::define('manage-personas', [PersonaPolicy::class, 'manage']);
        Gate::define('manage-studio-plans', [StudioPlanPolicy::class, 'manage']);
        Gate::define('manage-games', [GamePolicy::class, 'manage']);

        View::composer(['layouts.app', 'layouts.guest'], function ($view): void {
            $themeManager = app(ThemeManager::class);
            $user = auth()->user();
            $themePreference = $themeManager->preferenceForUser($user);
            $appliedTheme = $themeManager->appliedThemeFromPreference($themePreference);

            $view->with([
                'themePreference' => $themePreference,
                'appliedTheme' => $appliedTheme,
                // A choice saved to the account outranks one a browser kept
                // in localStorage — the client reads which this is. A stored
                // value that is no choice is none, and the browser's stands.
                'themeSource' => is_string($user?->theme_preference) && ThemePreference::isValid($user->theme_preference) ? 'user' : 'default',
            ]);
        });

        View::composer('layouts.app', function ($view): void {
            // The header renders auth()->user()->resolved_avatar_srcset,
            // which never lazy-loads variants (see AvatarUrlResolver) — load
            // it once here so the header actually gets a real srcset instead
            // of silently always falling back to the master image.
            auth()->user()?->loadMissing('avatarAsset.variants');

            $settings = app(ProjectSettingsManager::class)->current();
            $view->with(array_merge(app(AppLayoutData::class)->toArray(), [
                'projectSettings' => $settings,
            ]));
        });

        View::composer('components.auth.panel', function ($view): void {
            $availability = app(SocialProviderAvailability::class);
            $mode = AuthModalMode::fromInput($view->getData()['mode'] ?? null);

            $view->with([
                'socialProviders' => $availability->available(),
                // Only the login side names switched-off providers, and only
                // those somebody actually signs in with: a provider this site
                // never offered is not news to anyone.
                'unavailableSocialProviders' => $mode === AuthModalMode::Login
                    ? app(SocialProvidersInUseQuery::class)->among($availability->unavailable())
                    : [],
            ]);
        });

        View::composer('layouts.guest', function ($view): void {
            $view->with('projectSettings', app(ProjectSettingsManager::class)->current());
        });

        View::composer('layouts.partials.app-sidebar-content', function ($view): void {
            $locale = app()->getLocale();
            $onFeed = request()->routeIs('feed');

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

            // Each link changes one part of the feed and keeps the others: a
            // sort, a category and a tag narrow the feed together. What is
            // marked as chosen is the feed as FeedPage reads it.
            $address = FeedAddress::fromRequest(request(), array_column($sidebarCategories, 'slug'));
            $activeCategories = $address->part('category') ?? [];
            $activeTag = $address->part('tag');
            $sort = $address->part('sort');
            $feed = $address->part('feed');

            $categories = [
                [
                    'label' => __('ui.feed.all'),
                    'href' => $address->with(['category' => null]),
                    'active' => $onFeed && $activeCategories === [],
                ],
            ];

            foreach ($sidebarCategories as $category) {
                $categories[] = [
                    'label' => TranslatableField::resolve(
                        $category['name_translations'],
                        $category['name'],
                        $locale,
                    ),
                    'href' => $address->with(['category' => [$category['slug']]]),
                    'active' => in_array($category['slug'], $activeCategories, true),
                ];
            }

            // Not locale-sensitive: tags are named by slug, not by translation.
            $topTagSlugs = Cache::remember('sidebar-nav-top-tag-slugs', 300, function () {
                return Tag::query()
                    ->whereHas('posts', fn ($query) => $query->where('status', PostStatus::Published))
                    ->withCount(['posts as published_posts_count' => fn ($query) => $query->where('status', PostStatus::Published)])
                    ->orderByDesc('published_posts_count')
                    ->orderBy('name')
                    ->limit(5)
                    ->pluck('slug')
                    ->all();
            });

            $topTags = array_map(fn (string $slug): array => [
                'label' => '#'.$slug,
                'href' => $address->with(['tag' => $slug]),
                'active' => $activeTag === $slug,
            ], $topTagSlugs);

            $settings = app(ProjectSettingsManager::class);
            $user = auth()->user();

            // The sorts and the following feed are one choice: picking one
            // replaces the other, and keeps the categories, the tag and the search.
            $navItems = [
                ['label' => __('ui.nav.home'), 'icon' => 'home', 'href' => $address->with(['sort' => null, 'feed' => null]), 'active' => $onFeed && blank($sort) && blank($feed), 'testid' => 'nav-home'],
                ['label' => __('ui.nav.top'), 'icon' => 'flame', 'href' => $address->with(['sort' => 'top', 'feed' => null]), 'active' => $sort === 'top', 'testid' => 'nav-top'],
                ['label' => __('ui.nav.new'), 'icon' => 'plus', 'href' => $address->with(['sort' => 'newest', 'feed' => null]), 'active' => $sort === 'newest', 'testid' => 'nav-new'],
                ['label' => __('ui.nav.hot'), 'icon' => 'trending', 'href' => $address->with(['sort' => 'hot', 'feed' => null]), 'active' => $sort === 'hot', 'testid' => 'nav-hot'],
            ];

            if ($user !== null) {
                $navItems[] = ['label' => __('ui.nav.following'), 'icon' => 'users', 'href' => $address->with(['feed' => 'following', 'sort' => null]), 'active' => $feed === 'following', 'testid' => 'nav-following'];
            }

            if ($user !== null && $settings->featureEnabled('show_saved_posts')) {
                $navItems[] = ['label' => __('saved_posts.saved_posts'), 'icon' => 'bookmark', 'href' => route('saved-posts.index'), 'active' => request()->routeIs('saved-posts.index'), 'testid' => 'nav-saved-posts'];
            }

            $view->with([
                'navItems' => $navItems,
                'categories' => $categories,
                'topTags' => $topTags,
            ]);
        });
    }
}
