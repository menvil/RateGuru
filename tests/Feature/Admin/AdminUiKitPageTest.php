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

it('draws its specimens without touching the database', function () {
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
        ->assertSeeInOrder(['Foundations', 'Actions', 'Status', 'Forms', 'Navigation', 'Layout', 'Tables', 'Overlays and feedback', 'Localization domain']);
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
        // …and the two unsaved drafts their own field, single line or not, an AI draft its own cell too.
        ->toContain('rg-admin-textarea--info')
        ->toContain('rg-admin-textarea--changed')
        ->toContain('rg-admin-input--info')
        ->toContain('rg-admin-input--changed')
        ->toContain('rg-admin-translation-row__target--generated')
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

it('opens live confirmation dialogs at each level, built from the confirmation dialog component', function () {
    $html = $this->actingAs(User::factory()->admin()->create())
        ->get(ADMIN_UI_KIT_URL)
        ->getContent();

    $dialogs = adminUiKitSpecimen($html, 'OVL-01');

    expect($dialogs)
        ->toContain('Blade component · x-admin.ui.confirm-dialog')
        ->toContain('Light')
        ->toContain('Warning')
        ->toContain('Blocked')
        ->toContain('Danger')
        // Each level opens a real dialog, not a picture of one.
        ->toContain("x-if=\"dialog === 'light'\"")
        ->toContain("x-if=\"dialog === 'warning'\"")
        ->toContain("x-if=\"dialog === 'blocked'\"")
        ->toContain("x-if=\"dialog === 'danger'\"")
        ->toContain('rg-admin-dialog__icon--danger')
        ->toContain('rg-admin-dialog-subject')
        ->toContain('rg-admin-button--danger')
        ->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('rg-admin-dialog__icon--warning')
        ->toContain('Enable anyway')
        // The blocked dialog explains and offers a way out, without a confirm.
        ->toContain('Dogs can’t be deleted')
        ->toContain('Deactivate it instead');

    expect(substr_count($dialogs, 'role="dialog"'))->toBe(4);
});

it('opens a live drawer built from the drawer component', function () {
    $html = $this->actingAs(User::factory()->admin()->create())
        ->get(ADMIN_UI_KIT_URL)
        ->getContent();

    expect(adminUiKitSpecimen($html, 'OVL-02'))
        ->toContain('Blade component · x-admin.ui.drawer')
        ->toContain('Open live drawer')
        ->toContain('class="rg-admin-drawer"')
        ->toContain('role="dialog"')
        ->toContain('aria-labelledby="kit-drawer-title"')
        ->toContain('Edit category')
        ->toContain('rg-admin-drawer__footer');
});

it('raises real toasts on the kit\'s one toast stack', function () {
    $html = $this->actingAs(User::factory()->admin()->create())
        ->get(ADMIN_UI_KIT_URL)
        ->getContent();

    expect(adminUiKitSpecimen($html, 'FBK-01'))
        ->toContain('Blade component · x-admin.ui.toast-stack')
        ->toContain('Show success toast')
        ->toContain("\$dispatch('rg-admin-toast', { message: '3 posts approved and published', tone: 'success' })")
        // Undo is live too: its event answers with a toast and changes nothing.
        ->toContain("action: { label: 'Undo', event: 'rg-admin-kit-undo', params: {} }")
        ->toContain('x-on:rg-admin-kit-undo.window')
        ->and(substr_count($html, 'class="rg-admin rg-admin-toast-stack"'))->toBe(1);
});

it('shows the segmented control, the filter dropdown and the combobox live, from their components', function () {
    $html = $this->actingAs(User::factory()->admin()->create())
        ->get(ADMIN_UI_KIT_URL)
        ->getContent();

    expect(adminUiKitSpecimen($html, 'FRM-08'))
        ->toContain('x-admin.ui.segmented')
        ->toContain('role="radiogroup"')
        ->toContain('Missing only')
        ->toContain('rg-admin-segmented--compact')
        ->and(adminUiKitSpecimen($html, 'FRM-10'))
        ->toContain('x-admin.ui.filter-dropdown')
        ->toContain('aria-haspopup="menu"')
        ->toContain('role="menuitemradio"')
        ->toContain('rg-admin-filter-chip')
        ->and(adminUiKitSpecimen($html, 'FRM-11'))
        ->toContain('x-admin.ui.combobox')
        ->toContain('aria-haspopup="listbox"')
        ->toContain('role="combobox"')
        ->toContain('placeholder="Search 32 target languages"')
        ->toContain('No installed language matches.');

    // Built for thirty languages and more.
    expect(substr_count(adminUiKitSpecimen($html, 'FRM-11'), 'id="kit-combobox-option-'))->toBe(32)
        // And for a list of things, each leading with an icon, an identifier in mono.
        ->and(adminUiKitSpecimen($html, 'FRM-11'))
        ->toContain('id="kit-combobox-models-trigger"')
        ->toContain('rg-admin-combobox__icon')
        ->toContain('rg-admin-combobox__option-icon')
        ->toContain('rg-admin-combobox__option-label rg-admin-combobox__option-label--mono');
});

it('shows pagination live from its component, at the first, a middle, the last and a single page', function () {
    // Without Livewire's block markers, so a range reads as one text.
    $specimen = (string) preg_replace('/<!--.*?-->/s', '', adminUiKitSpecimen($this->actingAs(User::factory()->admin()->create())->get(ADMIN_UI_KIT_URL)->getContent(), 'NAV-04'));

    expect($specimen)
        ->toContain('x-admin.ui.pagination')
        ->toContain('1–25 of 1,231 posts')
        ->toContain('601–625 of 1,231 posts · newest first')
        ->toContain('1,226–1,231 of 1,231 posts')
        ->toContain('1–12 of 12 posts')
        ->and(substr_count($specimen, 'aria-current="page"'))->toBe(4)
        ->and(substr_count($specimen, 'class="rg-admin-pagination__gap"'))->toBe(4);
});

it('shows the locked identifier, the number stepper and the switch live, from their components', function () {
    $html = $this->actingAs(User::factory()->admin()->create())
        ->get(ADMIN_UI_KIT_URL)
        ->getContent();

    expect(adminUiKitSpecimen($html, 'FRM-04'))
        ->toContain('Blade component · x-admin.ui.locked-identifier')
        ->toContain('Generated from name')
        ->toContain('rg-admin-identifier__frame--locked')
        ->toContain('Change slug')
        ->toContain('Changing the slug breaks existing links to this category.');

    expect(adminUiKitSpecimen($html, 'FRM-05'))
        ->toContain('Blade component · x-admin.ui.number-stepper')
        ->toContain('aria-label="Decrease sort order"')
        ->toContain('x-model="sort"');

    expect(adminUiKitSpecimen($html, 'FRM-06'))
        ->toContain('Blade component · x-admin.ui.switch')
        ->toContain('role="switch"')
        ->toContain('x-model="active"')
        ->toMatch('/id="kit-switch-disabled"[^>]*role="switch"[^>]*aria-checked="false"[^>]*disabled/s');
});

it('shows the row menu live, an unavailable item kept with its reason', function () {
    $html = $this->actingAs(User::factory()->admin()->create())
        ->get(ADMIN_UI_KIT_URL)
        ->getContent();

    expect(adminUiKitSpecimen($html, 'TBL-04'))
        ->toContain('Blade components · x-admin.ui.row-menu, x-admin.ui.menu-item')
        ->toContain('id="kit-row-menu-menu"')
        ->toContain('aria-label="More actions for Rabbits &amp; rodents"')
        ->toMatch('/aria-disabled="true"[^>]*>.*Merge into….*Not available yet/s')
        ->toContain('rg-admin-menu__item--danger');
});
