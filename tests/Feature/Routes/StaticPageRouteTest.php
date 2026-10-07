<?php

use App\Models\ProjectSettings;
use App\Support\Settings\ProjectSettingsManager;

/**
 * A static page shows what the project stores: the visitor's language, field
 * by field, otherwise the stored English. config/static-pages.php seeded the
 * pages once and is never read for what they say.
 *
 * Roles, not languages: the language besides English comes from config.
 */

/** The configured pages, as a new project stores them, changed by the callback. */
function storedPages(?Closure $change = null): array
{
    $pages = config('static-pages.defaults');

    if ($change !== null) {
        $change($pages);
    }

    ProjectSettings::factory()->create(['static_pages' => $pages]);
    app(ProjectSettingsManager::class)->flush();

    return $pages;
}

it('serves the public about page with its stored content', function () {
    $page = storedPages()['about']['en'];

    $this->get(route('pages.about'))
        ->assertOk()
        ->assertSee('data-testid="static-page"', false)
        ->assertSee($page['title'])
        ->assertSee($page['content']);
});

it('serves legal and contact pages with their stored content', function (string $routeName, string $pageKey) {
    $page = storedPages()[$pageKey]['en'];

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

it('publishes a legal or contact page after an administrator supplies content', function (string $routeName, string $pageKey) {
    storedPages(function (array &$pages) use ($pageKey): void {
        $pages[$pageKey]['en'] = ['title' => 'Administrator-managed title', 'content' => 'Administrator-managed content.'];
    });

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
    $page = storedPages(function (array &$pages): void {
        $pages = staticPagesTranslatedInto(translatedLocales());
    })['about'][$locale];
    offerEveryInstalledLocale();

    $this->withSession(['locale' => $locale])
        ->get(route('pages.about'))
        ->assertOk()
        ->assertSee($page['title'])
        ->assertSee($page['content']);
})->with(translatedLocales());

it('renders admin-edited static page content for the current locale', function () {
    [$target] = twoTranslatedLocales();
    storedPages(function (array &$pages) use ($target): void {
        $pages['about'][$target] = ['title' => 'Title an administrator wrote', 'content' => 'Content an administrator wrote.'];
    });

    $this->withSession(['locale' => $target])
        ->get(route('pages.about'))
        ->assertOk()
        ->assertSee('Title an administrator wrote')
        ->assertSee('Content an administrator wrote.');
});

it('shows the stored English, field by field, where the language has no text of its own', function (array $localized) {
    [$target] = twoTranslatedLocales();
    $pages = storedPages(function (array &$pages) use ($target, $localized): void {
        $pages['about'][$target] = $localized;
    });

    $title = $localized['title'] ?? null;
    $content = $localized['content'] ?? null;

    $this->withSession(['locale' => $target])
        ->get(route('pages.about'))
        ->assertOk()
        ->assertSee(is_string($title) && trim($title) !== '' ? $title : $pages['about']['en']['title'])
        ->assertSee(is_string($content) && trim($content) !== '' ? $content : $pages['about']['en']['content']);
})->with([
    'missing content' => [['title' => 'A title of its own']],
    'blank content' => [['title' => 'Another title of its own', 'content' => '  ']],
    'missing title' => [['content' => 'Content of its own']],
    'blank title' => [['title' => '', 'content' => 'Other content of its own']],
    'nothing at all' => [[]],
]);

it('shows the stored English rather than the repository translation a language is missing', function () {
    // The repository still ships this language; the project does not have it.
    [$target] = twoTranslatedLocales();
    $pages = storedPages(function (array &$pages) use ($target): void {
        unset($pages['about'][$target]);
    });

    $this->withSession(['locale' => $target])
        ->get(route('pages.about'))
        ->assertOk()
        ->assertSee($pages['about']['en']['title'])
        ->assertDontSee(config("static-pages.defaults.about.{$target}.title"));
});

it('keeps showing the stored text after the repository text changes', function () {
    [$target] = twoTranslatedLocales();
    $pages = storedPages(function (array &$pages) use ($target): void {
        $pages = staticPagesTranslatedInto([$target]);
    });

    config([
        'static-pages.defaults.about.en.title' => 'A new English title in a later release',
        "static-pages.defaults.about.{$target}.title" => 'A new translation in a later release',
    ]);

    $this->get(route('pages.about'))->assertOk()
        ->assertSee($pages['about']['en']['title'])
        ->assertDontSee('A new English title in a later release');

    $this->withSession(['locale' => $target])->get(route('pages.about'))->assertOk()
        ->assertSee($pages['about'][$target]['title'])
        ->assertDontSee('A new translation in a later release');
});

it('shows a built-in page the project stores nothing for as empty, not from config', function () {
    storedPages(function (array &$pages): void {
        unset($pages['about']);
    });

    $this->get(route('pages.about'))
        ->assertOk()
        ->assertDontSee(config('static-pages.defaults.about.en.title'));
});

it('refuses a page the application does not have', function () {
    storedPages();

    expect(fn () => app(ProjectSettingsManager::class)->current()->staticPage('imprint'))
        ->toThrow(InvalidArgumentException::class, 'imprint');
});

it('links the sidebar footer to every static page', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('href="'.route('pages.about').'"', false)
        ->assertSee('href="'.route('pages.terms').'"', false)
        ->assertSee('href="'.route('pages.privacy').'"', false)
        ->assertSee('href="'.route('pages.contact').'"', false);
});
