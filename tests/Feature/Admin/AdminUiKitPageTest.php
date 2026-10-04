<?php

use App\Filament\Pages\AdminUiKit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The Admin v2 developer UI kit at /admin/dev/ui-kit: a developer tool that
 * exists only in local and testing, opens to exactly the people who may open
 * the admin panel, is never offered in its navigation, and draws the admin's
 * components under the reference IDs of the Dev UI kit reference.
 */
const ADMIN_UI_KIT_URL = '/admin/dev/ui-kit';

/** The raw reference file; its IDs sit in the bundle as plain JavaScript strings. */
function devUiKitReference(): string
{
    return (string) file_get_contents(base_path('docs/design/admin/reference/original/RateGuru-Dev-UI-Kit.html'));
}

/** The rendered card of one element, from its section to the next. */
function adminUiKitSpecimen(string $html, string $id): string
{
    $start = strpos($html, 'id="'.$id.'"');

    expect($start)->not->toBeFalse("the kit has no card for {$id}");

    $end = strpos($html, '</section>', $start);

    return substr($html, $start, $end - $start);
}

it('opens to an administrator', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(ADMIN_UI_KIT_URL)
        ->assertOk();
});

it('opens to a moderator, who may open the panel', function () {
    $this->actingAs(User::factory()->moderator()->create())
        ->get(ADMIN_UI_KIT_URL)
        ->assertOk();
});

it('stays closed to a signed-in user who may not open the panel', function () {
    $this->actingAs(User::factory()->create())
        ->get(ADMIN_UI_KIT_URL)
        ->assertForbidden();
});

it('stays closed to a banned administrator', function () {
    $this->actingAs(User::factory()->admin()->banned()->create())
        ->get(ADMIN_UI_KIT_URL)
        ->assertForbidden();
});

it('sends a guest to the panel login', function () {
    $this->get(ADMIN_UI_KIT_URL)->assertRedirect(route('filament.admin.auth.login'));
});

it('does not exist outside local and testing, even for an administrator', function (string $environment) {
    $admin = User::factory()->admin()->create();
    $this->app->detectEnvironment(fn () => $environment);

    expect(AdminUiKit::canAccess())->toBeFalse();

    $this->actingAs($admin)->get(ADMIN_UI_KIT_URL)->assertNotFound();
})->with(['production', 'staging']);

it('exists in local as well as testing', function () {
    $admin = User::factory()->admin()->create();
    $this->app->detectEnvironment(fn () => 'local');

    $this->actingAs($admin)->get(ADMIN_UI_KIT_URL)->assertOk();
});

it('is not offered in the admin navigation', function () {
    expect(AdminUiKit::shouldRegisterNavigation())->toBeFalse();

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertOk()
        ->assertDontSee(ADMIN_UI_KIT_URL);
});

it('draws static specimens without touching the database', function () {
    $this->actingAs(User::factory()->admin()->create());

    DB::enableQueryLog();

    $this->get(ADMIN_UI_KIT_URL)->assertOk();

    expect(DB::getQueryLog())->toBe([]);
});

it('presents itself as the Admin v2 reference for developers', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(ADMIN_UI_KIT_URL)
        ->assertOk()
        ->assertSee('RateGuru Admin v2 · reference for developers')
        ->assertSee('Dev UI kit')
        ->assertSeeInOrder(['Foundations', 'Actions', 'Status', 'Forms', 'Navigation', 'Layout', 'Tables', 'Feedback', 'Localization domain']);
});

it('uses only reference IDs that the Dev UI kit reference defines', function () {
    $reference = devUiKitReference();
    $ids = collect(AdminUiKit::groups())->pluck('ids')->flatten();

    expect($ids)->not->toBeEmpty()
        ->and($ids->duplicates())->toBeEmpty()
        ->and($ids->sort()->values()->all())->toBe(collect(array_keys(AdminUiKit::specs()))->sort()->values()->all());

    foreach ($ids as $id) {
        expect($reference)->toContain("'{$id}'");
    }
});

it('shows every element it lists under its reference ID', function () {
    $html = $this->actingAs(User::factory()->admin()->create())
        ->get(ADMIN_UI_KIT_URL)
        ->assertOk()
        ->getContent();

    foreach (AdminUiKit::specs() as $id => $spec) {
        expect(adminUiKitSpecimen($html, $id))->toContain(e($spec['name']));
    }

    expect($html)
        ->toContain('id="FND-01"')
        ->toContain('id="FND-04"')
        ->toContain('id="DOM-01"');
});

it('shows the four translation field states, each drawn differently', function () {
    $html = $this->actingAs(User::factory()->admin()->create())
        ->get(ADMIN_UI_KIT_URL)
        ->getContent();

    $states = adminUiKitSpecimen($html, 'DOM-01');

    expect($states)
        ->toContain('Translation field states')
        ->toContain('Saved')
        ->toContain('Missing')
        ->toContain('AI suggestion · not saved')
        ->toContain('Edited · not saved')
        // Stored, missing, AI draft and manual edit each get their own badge tone…
        ->toContain('rg-admin-badge--success')
        ->toContain('rg-admin-badge--warning')
        ->toContain('rg-admin-badge--info')
        ->toContain('rg-admin-badge--outline')
        // …and the two unsaved drafts their own field.
        ->toContain('rg-admin-textarea--info')
        ->toContain('rg-admin-textarea--changed')
        ->toContain('Visitors see the English text')
        ->toContain('Regenerate')
        ->toContain('Discard')
        ->toContain('AI suggestions are drafts until saved.');
});

it('shows the errors that keep a translation from being saved', function () {
    $html = $this->actingAs(User::factory()->admin()->create())
        ->get(ADMIN_UI_KIT_URL)
        ->getContent();

    $states = adminUiKitSpecimen($html, 'DOM-01');

    expect($states)
        ->toContain('Keep {contact_email}')
        ->toContain('4 over the limit')
        ->toContain('28 / 24')
        ->toContain('rg-admin-textarea--invalid')
        ->toContain('aria-invalid="true"');
});
