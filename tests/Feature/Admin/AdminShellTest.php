<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\LanguagesPage;
use App\Filament\Pages\MediaDiagnosticsPage;
use App\Filament\Pages\ProjectSettingsPage;
use App\Filament\Pages\TranslationCenterPage;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Comments\CommentResource;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\RatingGroups\RatingGroupResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\AdminNavigationGroup;
use App\Filament\Support\AdminShellNavigation;
use App\Livewire\Admin\Sidebar;
use App\Livewire\Admin\Topbar;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Livewire\Sidebar as FilamentSidebar;
use Filament\Livewire\Topbar as FilamentTopbar;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The Admin v2 shell: the sidebar and top bar every admin page now sits in.
 * Navigation comes from Filament's own registered navigation, so these tests
 * read the rendered sidebar to prove what a user actually sees and can open.
 */

/** Every production destination before the shell, in the Admin v2 order. */
const ADMIN_SHELL_DESTINATIONS = [
    'Overview' => ['Dashboard'],
    'Moderation' => ['Posts', 'Comments', 'Reports', 'Users'],
    'Content' => ['Categories', 'Tags', 'Rating groups'],
    'Localization' => ['Languages', 'Translation Center'],
    'Configuration' => ['Project settings'],
    'System' => ['Media diagnostics'],
];

/**
 * The sidebar as rendered: section label => [destination label => href].
 *
 * @return array<string, array<string, string>>
 */
function adminShellNavigation(string $html): array
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $sections = [];

    foreach ($xpath->query('//nav[@aria-label="Admin navigation"]//div[contains(@class, "rg-admin-nav-section")]') as $section) {
        $label = trim((string) $xpath->query('.//p[contains(@class, "rg-admin-nav-section__label")]', $section)->item(0)?->textContent);

        foreach ($xpath->query('.//a[contains(@class, "rg-admin-nav-item")]', $section) as $link) {
            $name = trim((string) $xpath->query('.//span[contains(@class, "rg-admin-nav-item__label")]', $link)->item(0)?->textContent);
            $sections[$label][$name] = (string) $link->getAttribute('href');
        }
    }

    return $sections;
}

/** The label of the destination the sidebar marks as the current page. */
function adminShellCurrent(string $html): ?string
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $current = $xpath->query('//nav[@aria-label="Admin navigation"]//a[@aria-current="page"]');

    expect($current->length)->toBeLessThanOrEqual(1);

    return $current->length === 1
        ? trim((string) $xpath->query('.//span[contains(@class, "rg-admin-nav-item__label")]', $current->item(0))->item(0)?->textContent)
        : null;
}

beforeEach(function () {
    ProjectSettings::factory()->create();
});

it('replaces Filament\'s sidebar and top bar with the Admin v2 shell', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->getSidebarLivewireComponent())->toBe(Sidebar::class)
        ->not->toBe(FilamentSidebar::class)
        ->and($panel->getTopbarLivewireComponent())->toBe(Topbar::class)
        ->not->toBe(FilamentTopbar::class)
        ->and($panel->getNavigationGroups())->toBe(AdminNavigationGroup::all())
        ->and($panel->hasDarkMode())->toBeFalse();
});

it('draws every admin page inside the Admin v2 shell', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSee('rg-admin-shell-sidebar', false)
        ->assertSee('rg-admin-shell-topbar', false)
        ->assertSee('<nav class="rg-admin-sidebar__nav" aria-label="Admin navigation"', false)
        ->assertDontSee('fi-sidebar-nav-groups', false)
        ->assertDontSee('class="fi-topbar"', false);
});

it('gives every admin page, sign-in included, the site\'s own icon', function () {
    // Without a declared icon the browser falls back to /favicon.ico, which is
    // empty, and asks for it again on every address change the admin makes —
    // each keystroke in a search that keeps its query in the URL.
    $icon = '<link rel="icon" href="'.asset('favicon.svg').'" />';

    expect(filesize(public_path('favicon.svg')))->toBeGreaterThan(0);

    $this->get('/admin/login')->assertOk()->assertSee($icon, false);

    $this->actingAs(User::factory()->admin()->create())
        ->get(LanguagesPage::getUrl())
        ->assertOk()
        ->assertSee($icon, false);
});

it('groups the navigation exactly as the Admin v2 contract does', function () {
    $html = $this->actingAs(User::factory()->admin()->create())->get('/admin')->getContent();

    $navigation = adminShellNavigation($html);

    expect(array_keys($navigation))->toBe(array_keys(ADMIN_SHELL_DESTINATIONS));

    foreach (ADMIN_SHELL_DESTINATIONS as $section => $labels) {
        expect(array_keys($navigation[$section]))->toBe($labels);
    }
});

it('offers no destination that does not exist yet, nor developer and hidden pages', function () {
    $html = $this->actingAs(User::factory()->admin()->create())->get('/admin')->getContent();
    $links = collect(adminShellNavigation($html))->flatMap(fn (array $items): array => $items);

    expect($links->values()->filter(fn (string $url): bool => str_contains($url, 'dev/ui-kit')))->toBeEmpty()
        ->and($links->values()->filter(fn (string $url): bool => str_contains($url, 'moderation-dashboard')))->toBeEmpty()
        ->and($links->values()->filter(fn (string $url): bool => in_array($url, ['', '#'], true) || str_starts_with($url, 'javascript:')))->toBeEmpty();
});

it('still reaches every screen the admin had before the shell', function () {
    $admin = User::factory()->admin()->create();
    $links = collect(adminShellNavigation($this->actingAs($admin)->get('/admin')->getContent()))
        ->flatMap(fn (array $items): array => $items);

    expect($links->keys()->sort()->values()->all())
        ->toBe(collect(ADMIN_SHELL_DESTINATIONS)->flatten()->sort()->values()->all());

    foreach ($links as $label => $url) {
        $response = $this->get($url);

        expect($response->getStatusCode())->toBe(200, "{$label} ({$url}) answered {$response->getStatusCode()}");
    }
});

it('marks the destination of the current page', function (string $path, string $active) {
    $html = $this->actingAs(User::factory()->admin()->create())->get($path)->assertOk()->getContent();

    expect(adminShellCurrent($html))->toBe($active);
})->with([
    'dashboard' => ['/admin', 'Dashboard'],
    'posts' => ['/admin/posts', 'Posts'],
    'categories' => ['/admin/categories', 'Categories'],
    'languages' => ['/admin/languages', 'Languages'],
    'translation center' => ['/admin/translation-center', 'Translation Center'],
    'project settings' => ['/admin/project-settings', 'Project settings'],
    'media diagnostics' => ['/admin/media-diagnostics', 'Media diagnostics'],
]);

it('keeps a resource marked on its create and edit pages, with a breadcrumb back to its list', function (Closure $url, string $active) {
    // One request per case: Filament reads the active state from the request
    // the page was first rendered for.
    $this->actingAs(User::factory()->admin()->create());

    $html = $this->get($url())->assertOk()->getContent();

    expect(adminShellCurrent($html))->toBe($active);

    $listUrl = collect(adminShellNavigation($html))->flatMap(fn (array $items): array => $items)[$active];

    // The last breadcrumb step is the resource, linked back to its list, not the current page.
    expect($html)->toContain('<a class="rg-admin-breadcrumb__link" href="'.e($listUrl).'">'.e($active).'</a>')
        ->not->toContain('rg-admin-breadcrumb__current');
})->with([
    'category create' => [fn (): string => CategoryResource::getUrl('create'), 'Categories'],
    'category edit' => [fn (): string => CategoryResource::getUrl('edit', ['record' => Category::factory()->create()]), 'Categories'],
    'rating group edit' => [fn (): string => RatingGroupResource::getUrl('edit', ['record' => RatingGroup::factory()->create()]), 'Rating groups'],
    'user edit' => [fn (): string => UserResource::getUrl('edit', ['record' => User::factory()->create()]), 'Users'],
]);

it('names the section and page in the breadcrumb without loading any record', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/posts')
        ->assertSeeInOrder(['rg-admin-breadcrumb__section', 'Moderation', 'rg-admin-breadcrumb__separator', 'aria-current="page">Posts'], false);
});

it('shows a moderator only the destinations Filament lets a moderator open', function () {
    $moderator = User::factory()->moderator()->create();
    $this->actingAs($moderator);

    $links = collect(adminShellNavigation($this->get('/admin')->assertOk()->getContent()))
        ->flatMap(fn (array $items): array => $items);

    $allowed = collect([
        'Dashboard' => Dashboard::canAccess(),
        'Posts' => PostResource::canAccess(),
        'Comments' => CommentResource::canAccess(),
        'Reports' => ReportResource::canAccess(),
        'Users' => UserResource::canAccess(),
        'Categories' => CategoryResource::canAccess(),
        'Tags' => TagResource::canAccess(),
        'Rating groups' => RatingGroupResource::canAccess(),
        'Languages' => LanguagesPage::canAccess(),
        'Project settings' => ProjectSettingsPage::canAccess(),
        'Media diagnostics' => MediaDiagnosticsPage::canAccess(),
    ]);

    expect($links->keys()->sort()->values()->all())
        ->toBe($allowed->filter()->keys()->sort()->values()->all())
        // Under the current policies: the moderation screens and Tags, nothing admin-only.
        ->and($links->keys()->sort()->values()->all())->toBe(['Comments', 'Dashboard', 'Posts', 'Reports', 'Tags', 'Users']);

    foreach ($links as $label => $url) {
        expect($this->get($url)->getStatusCode())->toBe(200, "{$label} is offered to a moderator but refused");
    }

    $this->get(LanguagesPage::getUrl())->assertForbidden();
    $this->get(ProjectSettingsPage::getUrl())->assertForbidden();
});

it('lets nobody past the panel\'s own access check', function () {
    $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));

    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    $this->actingAs(User::factory()->admin()->banned()->create())->get('/admin')->assertForbidden();
});

it('never draws an item Filament\'s navigation considers invisible', function () {
    Filament::getPanel('admin')->navigationItems([
        NavigationItem::make('Visible extra')->url('/admin/visible-extra')->group(AdminNavigationGroup::SYSTEM)->sort(99),
        NavigationItem::make('Hidden extra')->url('/admin/hidden-extra')->group(AdminNavigationGroup::SYSTEM)->visible(false),
    ]);

    $html = $this->actingAs(User::factory()->admin()->create())->get('/admin')->getContent();
    $system = adminShellNavigation($html)['System'];

    expect($system)->toHaveKey('Visible extra')
        ->not->toHaveKey('Hidden extra')
        ->and($html)->not->toContain('hidden-extra');
});

it('has an Admin v2 icon for every production destination', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk();

    $keys = collect(AdminShellNavigation::sections())->flatMap(fn (array $section): array => $section['items'])->pluck('key');

    expect($keys)->toHaveCount(12);

    foreach ($keys as $key) {
        expect(array_key_exists($key, AdminShellNavigation::ICONS))->toBeTrue("{$key} has no Admin v2 icon");
    }

    expect(AdminShellNavigation::ICONS)->toBe([
        Dashboard::class => 'layout-grid',
        PostResource::class => 'image',
        CommentResource::class => 'message-square',
        ReportResource::class => 'flag',
        UserResource::class => 'users',
        CategoryResource::class => 'folder',
        TagResource::class => 'tag',
        RatingGroupResource::class => 'star',
        LanguagesPage::class => 'globe',
        TranslationCenterPage::class => 'languages',
        ProjectSettingsPage::class => 'settings-2',
        MediaDiagnosticsPage::class => 'hard-drive',
    ]);
});

it('maps Filament badge colours to badge tones', function () {
    expect(AdminShellNavigation::badgeTone('danger'))->toBe('danger')
        ->and(AdminShellNavigation::badgeTone('warning'))->toBe('warning')
        ->and(AdminShellNavigation::badgeTone('success'))->toBe('success')
        ->and(AdminShellNavigation::badgeTone('info'))->toBe('info')
        ->and(AdminShellNavigation::badgeTone('primary'))->toBe('neutral')
        ->and(AdminShellNavigation::badgeTone(null))->toBe('neutral');
});

it('draws a badge a navigation item provides, without the shell counting anything itself', function () {
    Filament::getPanel('admin')->navigationItems([
        NavigationItem::make('Queue')->url('/admin/queue')->group(AdminNavigationGroup::SYSTEM)->sort(99)->badge('7', 'warning'),
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertSee('rg-admin-badge--warning rg-admin-badge--pill rg-admin-nav-item__badge', false)
        ->assertSee('rg-admin-nav-item__dot rg-admin-nav-item__dot--warning', false);
});

it('runs no queries of its own to draw the shell', function () {
    $this->actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    DB::enableQueryLog();

    Livewire::test(Sidebar::class)->assertOk();
    Livewire::test(Topbar::class)->assertOk();

    $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");

    foreach (['posts', 'comments', 'reports', 'media', 'translations', 'count('] as $counted) {
        expect($queries)->not->toContain($counted);
    }
});

it('carries every state of the responsive sidebar in one markup', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertOk()
        // The overlay drawer and its scrim, shared by the rail and narrow screens.
        ->assertSee('x-bind:class="{ \'rg-admin-sidebar--open\': open }"', false)
        ->assertSee('class="rg-admin-shell-scrim"', false)
        // Controls, each with an accessible name.
        ->assertSee('aria-label="Expand navigation"', false)
        ->assertSee('aria-label="Close navigation"', false)
        ->assertSee('aria-label="Open navigation"', false)
        ->assertSee('aria-controls="rg-admin-sidebar"', false)
        // Rail labels and tooltips.
        ->assertSee('data-tooltip="Posts"', false)
        ->assertSee('<span class="rg-admin-nav-item__label">Posts</span>', false)
        ->assertSee('class="rg-admin-shell-tooltip"', false);
});

it('identifies the signed-in user and signs out through Filament\'s own logout', function () {
    $admin = User::factory()->admin()->create(['name' => 'Daria Kovaleva', 'email' => 'daria@example.com']);

    $this->actingAs($admin)
        ->get('/admin')
        ->assertSee('<span class="rg-admin-account__name">Daria Kovaleva</span>', false)
        ->assertSee('<span class="rg-admin-account__role">Administrator</span>', false)
        ->assertSee('daria@example.com')
        ->assertSee('>DK</span>', false)
        ->assertSee('action="'.e(filament()->getLogoutUrl()).'"', false)
        ->assertSee('Sign out');

    $this->post(filament()->getLogoutUrl())->assertRedirect();

    $this->assertGuest();
});

it('offers no workspace switcher or profile page', function () {
    $html = $this->actingAs(User::factory()->admin()->create())->get('/admin')->getContent();

    expect($html)
        ->toContain('<span class="rg-admin-workspace__name">RateGuru</span>')
        ->not->toContain('Profile')
        ->not->toContain('Switch workspace');
});

it('puts the admin search under the workspace in the sidebar, not in the top bar', function () {
    $html = $this->actingAs(User::factory()->admin()->create())->get('/admin')->getContent();

    $sidebar = substr($html, (int) strpos($html, 'class="rg-admin rg-admin-shell-sidebar"'));
    $topbar = substr($html, (int) strpos($html, 'class="rg-admin rg-admin-shell-topbar"'), 4000);

    expect($sidebar)->toContain('class="rg-admin-sidebar__search"')
        ->and(strpos($sidebar, 'rg-admin-sidebar__header'))->toBeLessThan(strpos($sidebar, 'rg-admin-sidebar__search'))
        ->and(strpos($sidebar, 'rg-admin-sidebar__search'))->toBeLessThan(strpos($sidebar, 'rg-admin-sidebar__nav'))
        ->and($sidebar)->toContain('id="rg-admin-search"')
        ->toContain('placeholder="Search posts, users"')
        ->toContain('aria-keyshortcuts="Meta+K Control+K"')
        ->toContain('class="rg-admin-kbd"')
        // The shortcut lives on the sidebar, which can open itself first.
        ->toContain('x-on:keydown.meta.k.window="focusSearch($event)"')
        ->toContain('x-on:keydown.ctrl.k.window="focusSearch($event)"')
        ->and($topbar)->not->toContain('rg-admin-search')
        ->not->toContain('fi-global-search');
});
