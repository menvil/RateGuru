<?php

use App\Enums\UserRole;
use App\Filament\Resources\StudioPlans\Pages\CreateStudioPlan;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Filament\Resources\StudioPlans\Pages\ListStudioPlans;
use App\Filament\Resources\StudioPlans\StudioPlanResource;
use App\Filament\Support\AdminNavigationGroup;
use App\Filament\Support\AdminShellNavigation;
use App\Models\StudioPlan;
use App\Models\User;
use Livewire\Livewire;

/**
 * Content Studio's Publications & plans: where it sits, and who may open it,
 * save on it or delete from it — an active administrator, and nobody else,
 * whether by the navigation, a link or a Livewire request made by hand.
 */
it('sits first in Content Studio, before Personas, with a list, a new publication and a publication\'s editor', function () {
    expect(StudioPlanResource::getNavigationGroup())->toBe(AdminNavigationGroup::CONTENT_STUDIO)
        ->and(StudioPlanResource::getNavigationLabel())->toBe('Publications & plans')
        ->and(AdminShellNavigation::ICONS[StudioPlanResource::class])->toBe('newspaper')
        ->and(array_keys(StudioPlanResource::getPages()))->toBe(['index', 'create', 'edit'])
        ->and(StudioPlanResource::getUrl('index'))->toEndWith('/admin/studio-plans')
        ->and(StudioPlanResource::getUrl('create'))->toEndWith('/admin/studio-plans/create');

    $this->actingAs(User::factory()->admin()->create());
    $html = $this->get('/admin')->assertOk()->getContent();

    // The section holds the two screens that work, in this order, each a real page.
    $section = adminShellNavigation($html)['Content Studio'];

    expect(array_keys($section))->toBe(['Publications & plans', 'Personas'])
        ->and($html)->not->toContain('Reply rules')
        ->not->toContain('Reply log')
        ->not->toContain('Action queue');

    $this->get($section['Publications & plans'])->assertOk()->assertSee('Publications &amp; plans', false);
});

it('opens every page for an active administrator, and for nobody else', function (Closure $user, int $status) {
    $plan = StudioPlan::factory()->create();
    $this->actingAs($user());

    $this->get(StudioPlanResource::getUrl('index'))->assertStatus($status);
    $this->get(StudioPlanResource::getUrl('create'))->assertStatus($status);
    $this->get(StudioPlanResource::getUrl('edit', ['record' => $plan]))->assertStatus($status);
})->with([
    'an administrator' => [fn () => User::factory()->admin()->create(), 200],
    'a moderator' => [fn () => User::factory()->moderator()->create(), 403],
    'a member' => [fn () => User::factory()->create(), 403],
    'a persona' => [fn () => User::factory()->persona()->create(), 403],
    'a banned administrator' => [fn () => User::factory()->admin()->banned()->create(), 403],
    'a limited administrator' => [fn () => User::factory()->admin()->limited()->create(), 403],
]);

it('shows the section to an active administrator only', function (Closure $user) {
    $this->actingAs($user());

    $response = $this->get('/admin');

    if ($response->status() === 200) {
        expect(collect(adminShellNavigation($response->getContent()))->flatMap(fn (array $items): array => $items))
            ->not->toHaveKey('Publications & plans');
    } else {
        $response->assertForbidden();
    }
})->with([
    'a moderator' => [fn () => User::factory()->moderator()->create()],
    'a member' => [fn () => User::factory()->create()],
]);

it('opens no editor for a plan that does not exist', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(StudioPlanResource::getUrl('edit', ['record' => 2_000_000_000]))->assertNotFound();
    $this->get('/admin/studio-plans/not-a-plan/edit')->assertNotFound();
});

it('saves and deletes nothing through Livewire for a session demoted since the page was drawn', function () {
    $admin = User::factory()->admin()->create();
    $plan = StudioPlan::factory()->create(['title' => 'Kept']);
    $this->actingAs($admin);

    // Each drawn before the demotion; a refused request leaves a page with nothing to send again.
    $saving = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->set('postTitle', 'Taken over');
    $deleting = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->call('askToDelete');
    $creating = Livewire::test(CreateStudioPlan::class)->set('postTitle', 'Not mine');
    $listing = Livewire::test(ListStudioPlans::class)->call('askToDelete', $plan->id, $plan->lock_version);

    $admin->forceFill(['role' => UserRole::Moderator])->save();

    $saving->call('save')->assertForbidden();
    $deleting->call('delete')->assertForbidden();
    $creating->call('save')->assertForbidden();
    $listing->call('delete')->assertForbidden();

    expect($plan->fresh())->not->toBeNull()
        ->and($plan->fresh()->title)->toBe('Kept')
        ->and(StudioPlan::query()->count())->toBe(1);
});

it('lets nobody but an active administrator mount a page through Livewire', function (string $page) {
    $plan = StudioPlan::factory()->create();
    $this->actingAs(User::factory()->moderator()->create());

    $parameters = $page === EditStudioPlan::class ? ['record' => $plan->id] : [];

    Livewire::test($page, $parameters)->assertForbidden();
})->with([ListStudioPlans::class, CreateStudioPlan::class, EditStudioPlan::class]);
