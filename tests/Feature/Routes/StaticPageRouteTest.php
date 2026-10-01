<?php

use App\Models\ProjectSettings;
use App\Support\Translations\MissingProjectTranslation;
use App\Support\Translations\MissingTranslationReason;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationCompleteness;

/**
 * Stores the configured about page with its English title rewritten, the way
 * the Project Settings form stores it, and this language's stored text
 * replaced by the given one — or left out when null.
 *
 * @param  array<string, string>|null  $localized
 */
function aboutPageWithRewrittenEnglishTitle(string $locale, ?array $localized): void
{
    $pages = config('static-pages.defaults');
    $pages['about']['en']['title'] = 'About TitsGuru';

    if ($localized === null) {
        unset($pages['about'][$locale]);
    } else {
        $pages['about'][$locale] = $localized;
    }

    ProjectSettings::factory()->create(['static_pages' => $pages]);
    offerEveryInstalledLocale();
}

/** Why the Languages page counts the about page title as missing in this language, if it does. */
function aboutTitleMissingReason(string $locale): ?MissingTranslationReason
{
    return collect(app(ProjectTranslationCompleteness::class)->report($locale)->missing)
        ->first(fn (MissingProjectTranslation $item): bool => $item->section === ProjectContentSection::StaticPages && $item->key === 'about' && $item->field === 'title')
        ?->reason;
}

it('serves the public about page with its default content', function () {
    $page = config('static-pages.defaults.about.en');

    $this->get(route('pages.about'))
        ->assertOk()
        ->assertSee('data-testid="static-page"', false)
        ->assertSee($page['title'])
        ->assertSee($page['content']);
});

it('serves legal and contact pages with editable default content', function (string $routeName, string $pageKey) {
    $page = config("static-pages.defaults.{$pageKey}.en");

    $response = $this->get(route($routeName))
        ->assertOk()
        ->assertSee($page['title'])
        ->assertSee($page['content']);

    if ($pageKey === 'contact') {
        $response->assertSee('data-testid="contact-form"', false);
    } else {
        $response->assertSee('data-testid="static-page"', false);
    }
})->with([
    'privacy' => ['pages.privacy', 'privacy'],
    'terms' => ['pages.terms', 'terms'],
    'contact' => ['pages.contact', 'contact'],
]);

it('falls back to configured legal content for settings saved with the former blank defaults', function () {
    $staticPages = config('static-pages.defaults');
    $staticPages['privacy']['en']['content'] = '';
    $staticPages['privacy']['ru']['content'] = '';
    $configuredRussian = config('static-pages.defaults.privacy.ru');

    ProjectSettings::factory()->create(['static_pages' => $staticPages]);

    $this->withSession(['locale' => 'ru'])
        ->get(route('pages.privacy'))
        ->assertOk()
        ->assertSee($configuredRussian['title'])
        ->assertSee($configuredRussian['content']);
});

it('publishes a legal or contact page after an administrator supplies content', function (string $routeName, string $pageKey) {
    $staticPages = config('static-pages.defaults');
    $staticPages[$pageKey]['en'] = [
        'title' => 'Administrator-managed title',
        'content' => 'Administrator-managed content.',
    ];

    ProjectSettings::factory()->create(['static_pages' => $staticPages]);

    $this->get(route($routeName))
        ->assertOk()
        ->assertSee('Administrator-managed title')
        ->assertSee('Administrator-managed content.');
})->with([
    'privacy' => ['pages.privacy', 'privacy'],
    'terms' => ['pages.terms', 'terms'],
    'contact' => ['pages.contact', 'contact'],
]);

it('renders static page content in the selected locale', function (string $locale) {
    offerEveryInstalledLocale();
    $page = config("static-pages.defaults.about.{$locale}");

    expect($page)->toBeArray("config/static-pages.php has no default about page in {$locale}");

    $this->withSession(['locale' => $locale])
        ->get(route('pages.about'))
        ->assertOk()
        ->assertSee($page['title'])
        ->assertSee($page['content']);
})->with(translatedLocales());

it('renders admin-edited static page content for the current locale', function () {
    ProjectSettings::factory()->create([
        'static_pages' => [
            'about' => [
                'ru' => [
                    'title' => 'О проекте после редактирования',
                    'content' => 'Текст страницы, сохранённый администратором.',
                ],
            ],
        ],
    ]);

    $this->withSession(['locale' => 'ru'])
        ->get(route('pages.about'))
        ->assertOk()
        ->assertSee('О проекте после редактирования')
        ->assertSee('Текст страницы, сохранённый администратором.');
});

it('shows the rewritten English to a language with no text of its own for it', function () {
    [$target] = twoTranslatedLocales();
    aboutPageWithRewrittenEnglishTitle($target, null);

    $this->withSession(['locale' => $target])
        ->get(route('pages.about'))
        ->assertOk()
        ->assertSee('About TitsGuru')
        ->assertDontSee(config("static-pages.defaults.about.{$target}.title"))
        // Field by field: the content's English is unchanged, so its
        // translation still applies.
        ->assertSee(config("static-pages.defaults.about.{$target}.content"));

    expect(aboutTitleMissingReason($target))->toBe(MissingTranslationReason::SourceCustomized);
});

it('shows the rewritten English rather than a stored copy of the old translation', function () {
    [$target] = twoTranslatedLocales();
    aboutPageWithRewrittenEnglishTitle($target, config("static-pages.defaults.about.{$target}"));

    $this->withSession(['locale' => $target])
        ->get(route('pages.about'))
        ->assertOk()
        ->assertSee('About TitsGuru')
        ->assertDontSee(config("static-pages.defaults.about.{$target}.title"))
        ->assertSee(config("static-pages.defaults.about.{$target}.content"));

    expect(aboutTitleMissingReason($target))->toBe(MissingTranslationReason::StaleRepositoryDefault);
});

it('shows a translation written for the rewritten English', function () {
    [$target] = twoTranslatedLocales();
    aboutPageWithRewrittenEnglishTitle($target, [
        'title' => 'A translation of the new title',
        'content' => config("static-pages.defaults.about.{$target}.content"),
    ]);

    $this->withSession(['locale' => $target])
        ->get(route('pages.about'))
        ->assertOk()
        ->assertSee('A translation of the new title')
        ->assertDontSee('About TitsGuru');

    expect(aboutTitleMissingReason($target))->toBeNull();
});

it('merges each blank or absent Russian static page field with configured Russian defaults', function (
    array $russian,
    ?string $expectedTitle,
    ?string $expectedContent,
) {
    $configuredRussian = config('static-pages.defaults.about.ru');
    $expectedTitle ??= $configuredRussian['title'];
    $expectedContent ??= $configuredRussian['content'];

    ProjectSettings::factory()->create([
        'static_pages' => [
            'about' => [
                'ru' => $russian,
            ],
        ],
    ]);

    $this->withSession(['locale' => 'ru'])
        ->get(route('pages.about'))
        ->assertOk()
        ->assertSee($expectedTitle)
        ->assertSee($expectedContent);
})->with([
    'missing content' => [
        ['title' => 'Русский заголовок'],
        'Русский заголовок',
        null,
    ],
    'blank content' => [
        ['title' => 'Другой русский заголовок', 'content' => '  '],
        'Другой русский заголовок',
        null,
    ],
    'missing title' => [
        ['content' => 'Русское содержание'],
        null,
        'Русское содержание',
    ],
    'blank title' => [
        ['title' => '', 'content' => 'Другое русское содержание'],
        null,
        'Другое русское содержание',
    ],
]);

it('links the sidebar footer to every static page', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('href="'.route('pages.about').'"', false)
        ->assertSee('href="'.route('pages.terms').'"', false)
        ->assertSee('href="'.route('pages.privacy').'"', false)
        ->assertSee('href="'.route('pages.contact').'"', false);
});
