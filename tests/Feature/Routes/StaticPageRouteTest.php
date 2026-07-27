<?php

use App\Models\ProjectSettings;

it('serves every public static page with its default placeholder', function (string $routeName, string $pageKey) {
    $page = config("static-pages.defaults.{$pageKey}.en");

    $this->get(route($routeName))
        ->assertOk()
        ->assertSee('data-testid="static-page"', false)
        ->assertSee($page['title'])
        ->assertSee($page['content']);
})->with([
    'about' => ['pages.about', 'about'],
    'privacy' => ['pages.privacy', 'privacy'],
    'terms' => ['pages.terms', 'terms'],
    'contact' => ['pages.contact', 'contact'],
]);

it('renders static page content in the selected locale', function (string $locale) {
    $page = config("static-pages.defaults.about.{$locale}");

    $this->withSession(['locale' => $locale])
        ->get(route('pages.about'))
        ->assertOk()
        ->assertSee($page['title'])
        ->assertSee($page['content']);
})->with(['ru', 'bg']);

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

it('links the sidebar footer to every static page', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('href="'.route('pages.about').'"', false)
        ->assertSee('href="'.route('pages.terms').'"', false)
        ->assertSee('href="'.route('pages.privacy').'"', false)
        ->assertSee('href="'.route('pages.contact').'"', false);
});
