<?php

namespace App\Filament\Pages;

use App\Actions\Settings\SaveProjectSettingsAction;
use App\Enums\SocialProvider;
use App\Filament\Support\AdminNavigationGroup;
use App\Filament\Support\ContentTranslationStatus;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Queries\SocialProvidersInUseQuery;
use App\Services\Settings\ProjectPresetStatusService;
use App\Support\Auth\SocialProviderAvailability;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\TranslatableField;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The project's own settings, drawn entirely in Admin v2: its identity and
 * the names of what people post, the defaults, the feature flags, the
 * sign-in providers and the static pages, one tab at a time, all saved
 * together from the top bar (NAV-02).
 *
 * Everything here is English: the translations are Translation Center's,
 * so the page keeps none, and saving it never writes one back
 * (SaveProjectSettingsAction refuses them). Each card says which offered
 * languages miss what, and leads to Translation Center.
 *
 * The installation preset is shown, never applied: presets are applied once
 * from the server. What differs from it is listed, and a value can be put
 * back to the preset's in the draft.
 *
 * The draft is `data`; `saved` is what the server last saved, for the
 * browser to count unsaved changes. Switching uploads, guest viewing or a
 * sign-in provider off asks first, as the change locks people out.
 */
final class ProjectSettingsPage extends Page
{
    /** The tabs in order; the first is the default. */
    public const TABS = [
        'identity' => 'Site identity',
        'labels' => 'Object labels',
        'defaults' => 'Defaults',
        'features' => 'Feature flags',
        'providers' => 'Sign-in providers',
        'pages' => 'Static pages',
    ];

    /** The site's identity and the names of what people post, in their tabs and order. */
    public const TEXT_FIELDS = [
        'identity' => ['site_name' => 'Shown in the header, the browser tab and emails.', 'site_tagline' => 'A short line beside the site name.', 'site_description' => 'Used for search results and link previews.'],
        'labels' => ['object_singular_name' => 'Used in buttons and messages, as in “Report this post”.', 'object_plural_name' => 'Used in counts and headings, as in “1,231 posts”.', 'upload_cta_label' => 'The label of the main upload button.', 'feed_title' => 'The heading above the main feed.'],
    ];

    /** The text fields a project cannot leave blank. */
    public const REQUIRED = ['site_name', 'object_singular_name', 'object_plural_name', 'upload_cta_label', 'feed_title'];

    /** Each feature flag and what it does. */
    public const FLAGS = [
        'show_comments' => 'Comment threads under posts. Turning this off hides existing comments without deleting them.',
        'show_share_buttons' => 'Share links on post pages.',
        'show_vote_breakdown' => 'Per-option vote counts on each post instead of a single total.',
        'show_follow_buttons' => 'Lets people follow each other and get a following feed.',
        'post_detail_overlay_mode' => 'Posts open in an overlay above the feed instead of a page of their own.',
        'show_saved_posts' => 'People can save posts to a private list.',
        'allow_user_uploads' => 'Turn off to pause all new posts across the site. Existing posts stay visible.',
        'allow_guest_viewing' => 'Visitors can browse without an account. Off means every page asks to sign in.',
    ];

    /** The flags that lock people out when switched off, so switching them off asks first. */
    public const GUARDED_FLAGS = ['allow_user_uploads', 'allow_guest_viewing'];

    protected string $view = 'filament.pages.project-settings';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::CONFIGURATION;

    protected static ?string $navigationLabel = null;

    protected static ?string $title = null;

    protected static ?string $slug = 'project-settings';

    protected static ?int $navigationSort = 10;

    /** The open tab, in the address so a link or a reload opens it. Typed loosely: anything else opens the first. */
    #[Url(except: 'identity')]
    public mixed $tab = 'identity';

    /** The static page the Static pages tab edits. */
    #[Url(except: 'about')]
    public mixed $page = 'about';

    /** @var array<string, mixed> the draft */
    public ?array $data = [];

    /** @var array<string, mixed> what is saved, as the draft holds it */
    #[Locked]
    public array $saved = [];

    /** The confirmation on screen: `flag:{flag}` or `provider:{provider}`. */
    #[Locked]
    public ?string $confirming = null;

    public static function getNavigationLabel(): string
    {
        return __('admin.project_settings.nav_label');
    }

    public function getTitle(): string
    {
        return 'Project settings';
    }

    public static function canAccess(): bool
    {
        return Gate::allows('manage-project-settings');
    }

    public function mount(): void
    {
        $this->tab = array_key_exists((string) $this->tab, self::TABS) ? $this->tab : 'identity';
        $this->page = in_array($this->page, self::pageKeys(), true) ? $this->page : 'about';

        // What the project stores, as it stores it: a page with no text shows
        // empty, not filled in from config. An installation without a row
        // shows the bootstrap it would be created from. English only: the
        // translations are Translation Center's to edit, so they are never
        // in this draft, and saving it cannot write back a stale copy of them.
        $settings = ProjectSettings::query()->find(1);
        $stored = $settings ? $settings->toArray() : app(ProjectSettingsManager::class)->defaults();
        $defaults = app(ProjectSettingsManager::class)->defaults();
        $data = [];

        foreach ([...array_keys(self::TEXT_FIELDS['identity']), ...array_keys(self::TEXT_FIELDS['labels'])] as $field) {
            $data[$field] = $stored[$field] ?? null;
        }

        $data['default_theme'] = $stored['default_theme'] ?? $defaults['default_theme'];
        $data['default_sort'] = $stored['default_sort'] ?? $defaults['default_sort'];

        foreach (array_keys(self::FLAGS) as $flag) {
            $data['feature_flags'][$flag] = (bool) ($stored['feature_flags'][$flag] ?? $defaults['feature_flags'][$flag] ?? false);
        }

        // Every provider is on until it is switched off; an unsaved row or
        // a provider added later must not render as switched off.
        foreach (SocialProvider::values() as $provider) {
            $data['sign_in_providers'][$provider] = (bool) ($stored['sign_in_providers'][$provider] ?? true);
        }

        foreach (self::pageKeys() as $key) {
            $english = is_array($stored['static_pages'][$key][TranslatableField::REFERENCE_LOCALE] ?? null) ? $stored['static_pages'][$key][TranslatableField::REFERENCE_LOCALE] : [];
            $data['static_pages'][$key][TranslatableField::REFERENCE_LOCALE] = ['title' => $english['title'] ?? null, 'content' => $english['content'] ?? null];
        }

        $this->data = $data;
        $this->saved = $data;
    }

    /** A tab or a page that does not exist opens the first. */
    public function updatedTab(): void
    {
        $this->tab = array_key_exists((string) $this->tab, self::TABS) ? $this->tab : 'identity';
    }

    public function updatedPage(): void
    {
        $this->page = in_array($this->page, self::pageKeys(), true) ? $this->page : 'about';
    }

    /**
     * Switching off what locks people out asks first: guest viewing, uploads
     * and a sign-in provider. The switch goes back on until the dialog says
     * so; nothing is saved either way until Save.
     */
    public function updatedData(mixed $value, string $key): void
    {
        [$group, $name] = array_pad(explode('.', $key, 2), 2, null);

        if ($value !== false) {
            return;
        }

        if ($group === 'feature_flags' && in_array($name, self::GUARDED_FLAGS, true)) {
            $this->data['feature_flags'][$name] = true;
            $this->confirming = "flag:{$name}";
        }

        if ($group === 'sign_in_providers' && SocialProvider::tryFrom((string) $name) !== null) {
            $this->data['sign_in_providers'][$name] = true;
            $this->confirming = "provider:{$name}";
        }
    }

    /** Switches off what the confirmation on screen is about, in the draft. */
    public function confirmSwitchOff(): void
    {
        [$kind, $name] = array_pad(explode(':', (string) $this->confirming, 2), 2, null);

        if ($kind === 'flag' && in_array($name, self::GUARDED_FLAGS, true)) {
            $this->data['feature_flags'][$name] = false;
        }

        if ($kind === 'provider' && SocialProvider::tryFrom((string) $name) !== null) {
            $this->data['sign_in_providers'][$name] = false;
        }

        $this->closeConfirmation();
    }

    public function closeConfirmation(): void
    {
        $this->confirming = null;
    }

    #[On('discard-project-settings')]
    public function discard(): void
    {
        $this->data = $this->saved;
        $this->resetErrorBag();
    }

    #[On('save-project-settings')]
    public function save(): void
    {
        $fields = ProjectTranslationCatalog::FIELDS;
        $rules = [];
        $messages = [];

        foreach ([...self::TEXT_FIELDS['identity'], ...self::TEXT_FIELDS['labels']] as $field => $help) {
            [$limit] = $fields['project_settings'][$field];
            $rules["data.{$field}"] = [in_array($field, self::REQUIRED, true) ? 'required' : 'nullable', 'string', "max:{$limit}"];
            $messages["data.{$field}.required"] = 'Enter the '.Str::lower(self::fieldLabel($field)).'.';
            $messages["data.{$field}.max"] = 'Keep it to '.number_format($limit).' characters or fewer.';
        }

        $rules['data.default_theme'] = ['required', 'in:system,light,dark'];
        $rules['data.default_sort'] = ['required', 'in:hot,new,top'];

        // Each flag and provider the project has, and nothing a request adds beside them.
        foreach (array_keys(self::FLAGS) as $flag) {
            $rules["data.feature_flags.{$flag}"] = ['required', 'boolean'];
        }

        foreach (SocialProvider::values() as $provider) {
            $rules["data.sign_in_providers.{$provider}"] = ['required', 'boolean'];
        }

        foreach (self::pageKeys() as $key) {
            foreach (['title', 'content'] as $field) {
                [$limit] = $fields['static_pages'][$field];
                // English is what every other language falls back to, and
                // nothing falls back for English: it cannot be left blank.
                $rules["data.static_pages.{$key}.en.{$field}"] = ['required', 'string', "max:{$limit}"];
                $messages["data.static_pages.{$key}.en.{$field}.required"] = "Enter the {$field} of the ".__("admin.static_pages.{$key}").' page.';
                $messages["data.static_pages.{$key}.en.{$field}.max"] = 'Keep it to '.number_format($limit).' characters or fewer.';
            }
        }

        $validated = $this->validate($rules, $messages)['data'];

        foreach (['feature_flags', 'sign_in_providers'] as $switches) {
            $validated[$switches] = array_map(boolval(...), $validated[$switches]);
        }

        foreach ([...array_keys(self::TEXT_FIELDS['identity']), ...array_keys(self::TEXT_FIELDS['labels'])] as $field) {
            $validated[$field] = is_string($validated[$field] ?? null) ? trim($validated[$field]) : null;
        }

        app(SaveProjectSettingsAction::class)->handle($validated);

        // The draft becomes what was saved, trimmed, so the page shows what is
        // saved and keeps nothing a request added beside the settings.
        $this->data = $validated;
        $this->saved = $validated;
        $this->dispatch('rg-admin-toast', message: 'Project settings saved. Visitors see the changes on their next page load.', tone: 'success');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $settings = ProjectSettings::query()->find(1);
        $status = app(ContentTranslationStatus::class);

        return [
            'tab' => array_key_exists((string) $this->tab, self::TABS) ? $this->tab : 'identity',
            'page' => in_array($this->page, self::pageKeys(), true) ? $this->page : 'about',
            'pages' => $this->staticPages($settings, $status),
            'preset' => $this->preset($settings),
            'host' => (string) parse_url((string) config('app.url'), PHP_URL_HOST),
            'translations' => [
                'identity' => $this->settingsTranslations($settings, $status, array_keys(self::TEXT_FIELDS['identity'])),
                'labels' => $this->settingsTranslations($settings, $status, array_keys(self::TEXT_FIELDS['labels'])),
            ],
            'providers' => $this->providers(),
            'published' => Post::query()->published()->count(),
            'confirmation' => $this->confirmation(),
        ];
    }

    /** The English name of a text field. */
    public static function fieldLabel(string $field): string
    {
        return match ($field) {
            'site_name' => 'Site name',
            'site_tagline' => 'Tagline',
            'site_description' => 'Description',
            'object_singular_name' => 'Singular name',
            'object_plural_name' => 'Plural name',
            'upload_cta_label' => 'Upload button label',
            'feed_title' => 'Feed title',
            default => Str::headline($field),
        };
    }

    /** @return list<string> the built-in static pages, in config order */
    private static function pageKeys(): array
    {
        return array_map(strval(...), array_keys((array) config('static-pages.defaults', [])));
    }

    /**
     * The installation preset, and the values it gives the settings it
     * controls — the names of what people post, the defaults and the feature
     * flags — by their path in the draft, for the browser to compare.
     *
     * @return array{applied: bool, label: ?string, status: string, values: array<string, string|bool>, labels: array<string, string>}
     */
    private function preset(?ProjectSettings $settings): array
    {
        $key = $settings?->preset_applied_at !== null ? $settings->active_preset_key : null;
        $preset = $key !== null ? config("project_presets.{$key}") : null;
        $values = [];
        $labels = [];

        if (is_array($preset)) {
            foreach (array_keys(self::TEXT_FIELDS['labels']) as $field) {
                $value = $preset['settings'][$field][TranslatableField::REFERENCE_LOCALE] ?? null;

                if (is_string($value)) {
                    $values[$field] = $value;
                    $labels[$field] = self::fieldLabel($field);
                }
            }

            foreach (['default_theme' => 'Default theme', 'default_sort' => 'Default sort'] as $field => $label) {
                if (is_string($preset['settings'][$field] ?? null)) {
                    $values[$field] = $preset['settings'][$field];
                    $labels[$field] = $label;
                }
            }

            foreach (array_keys(self::FLAGS) as $flag) {
                if (is_bool($preset['feature_flags'][$flag] ?? null)) {
                    $values["feature_flags.{$flag}"] = $preset['feature_flags'][$flag];
                    $labels["feature_flags.{$flag}"] = __("admin.fields.{$flag}");
                }
            }
        }

        return [
            'applied' => is_array($preset),
            'label' => is_array($preset) ? (string) ($preset['label'] ?? $key) : null,
            'status' => app(ProjectPresetStatusService::class)->display(),
            'values' => $values,
            'labels' => $labels,
        ];
    }

    /**
     * Which offered languages miss which of these settings, in words, and
     * Translation Center on the first missing one.
     *
     * @param  list<string>  $fields
     * @return array{text: string, url: ?string}
     */
    private function settingsTranslations(?ProjectSettings $settings, ContentTranslationStatus $status, array $fields): array
    {
        $units = [];

        foreach ($fields as $field) {
            if (filled($settings?->{$field})) {
                $units["project_settings:{$field}"] = $status->chips($settings->{"{$field}_translations"});
            }
        }

        return [
            'text' => self::translationSummary($status, $units, fn (string $unit): string => Str::lower(self::fieldLabel(Str::after($unit, ':')))),
            'url' => $units === [] || $status->languages() === [] ? null : $status->translateUnitsUrl(ProjectContentSection::ProjectSettings, $units),
        ];
    }

    /**
     * Each static page: its title, how many offered languages miss its title
     * or content, and Translation Center on the first missing one.
     *
     * @return array<string, array{label: string, url: string, missing: int, translations: string, translate: ?string}>
     */
    private function staticPages(?ProjectSettings $settings, ContentTranslationStatus $status): array
    {
        $stored = is_array($settings?->static_pages) ? $settings->static_pages : [];
        $pages = [];

        foreach (self::pageKeys() as $key) {
            $units = [];

            foreach (['title', 'content'] as $field) {
                $translations = [];

                foreach (array_keys($status->languages()) as $locale) {
                    $translations[$locale] = $stored[$key][$locale][$field] ?? null;
                }

                $units["static_pages:{$key}:{$field}"] = $status->chips($translations);
            }

            $missing = count(array_filter(array_keys($status->languages()), fn (string $locale): bool => collect($units)->contains(fn (array $chips): bool => collect($chips)->firstWhere('code', $locale)['value'] === null)));

            $pages[$key] = [
                'label' => __("admin.static_pages.{$key}"),
                'url' => route("pages.{$key}"),
                'missing' => $missing,
                'translations' => self::translationSummary($status, $units, fn (string $unit): string => Str::afterLast($unit, ':')),
                'translate' => $status->languages() === [] ? null : $status->translateUnitsUrl(ProjectContentSection::StaticPages, $units),
            ];
        }

        return $pages;
    }

    /**
     * “Translations: Русский and Deutsch complete · Български missing the
     * description.” — the offered languages that have every unit, then each
     * that misses some, naming the one it misses or counting them.
     *
     * @param  array<string, list<array{code: string, native: string, value: ?string}>>  $units
     * @param  callable(string): string  $name
     */
    private static function translationSummary(ContentTranslationStatus $status, array $units, callable $name): string
    {
        if ($status->languages() === []) {
            return 'English is the only language visitors are offered.';
        }

        if ($units === []) {
            return 'Nothing to translate yet.';
        }

        $complete = [];
        $missing = [];

        foreach ($status->languages() as $code => $language) {
            $absent = array_keys(array_filter($units, fn (array $chips): bool => collect($chips)->firstWhere('code', $code)['value'] === null));

            if ($absent === []) {
                $complete[] = $language['native'];
            } else {
                $missing[] = $language['native'].' missing '.(count($absent) === 1 ? 'the '.$name($absent[0]) : count($absent));
            }
        }

        $parts = array_filter([
            $complete === [] ? null : Str::of(implode(', ', array_slice($complete, 0, -1)))->whenNotEmpty(fn ($list) => $list->append(' and '))->append(end($complete)).' complete',
            ...$missing,
        ]);

        return 'Translations: '.implode(' · ', $parts).'.';
    }

    /**
     * Each sign-in provider: whether its keys are set on the server, and how
     * many accounts sign in with it.
     *
     * @return list<array{value: string, label: string, configured: bool, keys: string, accounts: int}>
     */
    private function providers(): array
    {
        $availability = app(SocialProviderAvailability::class);
        $accounts = app(SocialProvidersInUseQuery::class)->counts();

        return array_map(function (SocialProvider $provider) use ($availability, $accounts): array {
            $prefix = strtoupper($provider->value);

            return [
                'value' => $provider->value,
                'label' => $provider->label(),
                'configured' => $availability->isConfigured($provider),
                'keys' => "{$prefix}_CLIENT_ID, {$prefix}_CLIENT_SECRET",
                'accounts' => (int) ($accounts[$provider->value] ?? 0),
            ];
        }, SocialProvider::cases());
    }

    /**
     * The switch-off confirmation on screen, while it still applies.
     *
     * @return array{kind: string, name: string, label: string, accounts: int}|null
     */
    private function confirmation(): ?array
    {
        [$kind, $name] = array_pad(explode(':', (string) $this->confirming, 2), 2, null);

        return match (true) {
            $kind === 'flag' && in_array($name, self::GUARDED_FLAGS, true) => ['kind' => 'flag', 'name' => $name, 'label' => __("admin.fields.{$name}"), 'accounts' => 0],
            $kind === 'provider' && ($provider = SocialProvider::tryFrom((string) $name)) !== null => ['kind' => 'provider', 'name' => $provider->value, 'label' => $provider->label(), 'accounts' => (int) (app(SocialProvidersInUseQuery::class)->counts()[$provider->value] ?? 0)],
            default => null,
        };
    }
}
