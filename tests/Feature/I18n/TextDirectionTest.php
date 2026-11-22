<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;

/*
 * A page in a language read right to left is laid out right to left; a page in
 * any other language is drawn exactly as before.
 *
 * The root element of every full page a visitor or a reader is served carries
 * the language's direction beside its code — the site's two layouts, the
 * not-found page drawn in one of them, and the HTML of an email. Everything
 * else follows `dir` by itself, because the public templates name sides only
 * logically (PhysicalDirectionGuardTest): so `dir` is what has to be right.
 */

/** The `dir` of the page's root element, or null when it has none. */
function rootDirection(string $html): ?string
{
    return preg_match('~<html\b[^>]*\bdir="([^"]*)"~i', $html, $match) === 1 ? $match[1] : null;
}

/** The `lang` of the page's root element. */
function rootLanguage(string $html): ?string
{
    return preg_match('~<html\b[^>]*\blang="([^"]*)"~i', $html, $match) === 1 ? $match[1] : null;
}

beforeEach(function () {
    offerLocales(rightToLeftLocales());
});

dataset('public pages', [
    'the feed' => [fn (): string => route('feed'), 200],
    'a post' => [fn (): string => route('posts.show', Post::factory()->published()->create()), 200],
    'a profile' => [fn (): string => route('profile.show', User::factory()->create()->username), 200],
    'a static page' => [fn (): string => route('pages.about'), 200],
    'the contact page' => [fn (): string => route('pages.contact'), 200],
    'sign-in' => [fn (): string => route('login'), 200],
    'sign-up' => [fn (): string => route('register'), 200],
    'a game' => [function (): string {
        $draft = publishableGameDraft(2);
        liveGame($draft);

        return route('games.play', $draft['game']->slug);
    }, 200],
    // Not found once the language is known. A post that is not there is found
    // missing before it is (route model binding comes first), and gets the
    // not-found page in the default language, laid out the way that is read.
    'a profile that is not there' => [fn (): string => route('profile.show', 'nobody-goes-by-this-name'), 404],
]);

it('lays a page out right to left in a language read right to left', function (Closure $url, int $status) {
    $address = $url();

    foreach (rightToLeftLocales() as $locale) {
        $html = $this->withSession(['locale' => $locale])->get($address)->assertStatus($status)->getContent();

        expect(rootDirection($html))->toBe('rtl', "{$locale} page served without dir=\"rtl\"")
            ->and(rootLanguage($html))->toBe($locale);
    }
})->with('public pages');

it('keeps a page left to right in a language read left to right', function (Closure $url, int $status) {
    $html = $this->withSession(['locale' => config('locales.default')])->get($url())->assertStatus($status)->getContent();

    expect(rootDirection($html))->toBe('ltr')
        ->and(rootLanguage($html))->toBe(config('locales.default'));
})->with('public pages');

it('serves a signed-in reader their own language direction', function () {
    [$locale] = rightToLeftLocales();

    $html = $this->actingAs(User::factory()->create(['locale' => $locale]))->get(route('profile.edit'))->assertOk()->getContent();

    expect(rootDirection($html))->toBe('rtl');
});

// Email -----------------------------------------------------------------------

it('lays an email out right to left in a language read right to left', function () {
    foreach (rightToLeftLocales() as $locale) {
        app()->setLocale($locale);

        $mail = renderedMail(new ResetPassword('a-token'), User::factory()->create(['locale' => $locale]));

        expect(rootDirection($mail['body']))->toBe('rtl', "{$locale} email")
            ->and($mail['body'])->toMatch('~<body\b[^>]*\bdir="rtl"~')
            // The mail theme aligns paragraphs to the left by name; the
            // right-to-left email turns them, with the inlined style that
            // every mail client reads.
            ->and($mail['body'])->toMatch('~<p\b[^>]*style="[^"]*text-align:\s*right~')
            ->and($mail['body'])->not->toMatch('~<p\b[^>]*style="[^"]*text-align:\s*left~');
    }
});

it('keeps an email left to right in a language read left to right', function () {
    app()->setLocale(config('locales.default'));

    $mail = renderedMail(new VerifyEmail, User::factory()->unverified()->create());

    expect(rootDirection($mail['body']))->toBe('ltr')
        ->and($mail['body'])->toMatch('~<body\b[^>]*\bdir="ltr"~')
        // Exactly the theme's own alignment, as before.
        ->and($mail['body'])->toMatch('~<p\b[^>]*style="[^"]*text-align:\s*left~')
        ->and($mail['body'])->not->toMatch('~text-align:\s*right~');
});

it('gives the contact message the direction of the language it is written in', function () {
    [$locale] = rightToLeftLocales();
    $view = fn (): string => view('mail.contact-message', [
        'senderName' => 'Reader',
        'senderEmail' => 'reader@example.test',
        'messageSubject' => 'Hello',
        'messageBody' => 'A message',
    ])->render();

    app()->setLocale($locale);
    expect(rootDirection($view()))->toBe('rtl');

    app()->setLocale(config('locales.default'));
    expect(rootDirection($view()))->toBe('ltr');
});

// Icons -----------------------------------------------------------------------

it('turns an icon that points the way the page reads round in a right-to-left page, and leaves every other icon alone', function () {
    $icon = fn (string $name): string => Blade::render('<x-ui.icon :name="$name" />', ['name' => $name]);

    // On, back, reply, undo, a trend over time: each points along the line.
    foreach (['arrow-right', 'reply', 'restore', 'trending'] as $name) {
        expect(str_contains($icon($name), 'rg-mirror-rtl'))->toBeTrue("{$name} stays as it is");
    }

    // Up, down, a search glass, a heart of a thing: no side to them.
    foreach (['arrow-up', 'arrow-down', 'chevron-down', 'search', 'check', 'x', 'upload', 'bell', 'share'] as $name) {
        expect(str_contains($icon($name), 'rg-mirror-rtl'))->toBeFalse("{$name} turns round");
    }
});

it('turns the pagination arrows round in a right-to-left page', function (string $view) {
    $paginator = new LengthAwarePaginator(range(1, 3), 30, 3, 2);

    $html = view($view, ['paginator' => $paginator])->render();

    // Previous and next: the only drawings on a pager, and both point along the line.
    expect(substr_count($html, '<svg'))->toBe(2)
        ->and(preg_match_all('~<svg\b[^>]*class="[^"]*\brg-mirror-rtl\b~', $html))->toBe(2);
})->with([
    'links on a page' => ['vendor.pagination.tailwind'],
    'links in a Livewire component' => ['vendor.livewire.tailwind'],
]);
