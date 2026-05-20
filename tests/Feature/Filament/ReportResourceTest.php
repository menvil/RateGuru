<?php

use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Report;
use App\Models\User;

it('allows admin to access report resource index', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(ReportResource::getUrl('index'))
        ->assertOk();
});

it('allows moderator to access report resource index', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get(ReportResource::getUrl('index'))
        ->assertOk();
});

it('does not allow normal user to access report resource index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(ReportResource::getUrl('index'))
        ->assertForbidden();
});

it('uses the Report model', function () {
    expect(ReportResource::getModel())->toBe(Report::class);
});

it('lives under the Moderation navigation group', function () {
    expect(ReportResource::getNavigationGroup())->toBe(AdminNavigationGroup::MODERATION);
});

it('does not expose create or edit pages in this phase', function () {
    expect(array_keys(ReportResource::getPages()))->toBe(['index']);
});
