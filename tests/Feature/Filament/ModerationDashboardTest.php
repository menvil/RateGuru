<?php

use App\Filament\Pages\ModerationDashboard;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Post;
use App\Models\User;

it('allows admin to access moderation dashboard', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(ModerationDashboard::getUrl())
        ->assertOk()
        ->assertSee('data-testid="moderation-dashboard"', false);
});

it('allows moderator to access moderation dashboard', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get(ModerationDashboard::getUrl())
        ->assertOk()
        ->assertSee('Moderation Dashboard');
});

it('does not allow normal user to access moderation dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/moderation-dashboard')
        ->assertForbidden();
});

it('registers moderation dashboard navigation metadata', function () {
    expect(ModerationDashboard::getNavigationGroup())->toBe(AdminNavigationGroup::MODERATION)
        ->and(ModerationDashboard::getNavigationLabel())->toBe('Moderation Dashboard')
        ->and(ModerationDashboard::getSlug())->toBe('moderation-dashboard');
});

it('shows pending posts count on moderation dashboard', function () {
    $admin = User::factory()->admin()->create();

    Post::factory()->pending()->count(3)->create();
    Post::factory()->published()->count(2)->create();

    $this->actingAs($admin)
        ->get(ModerationDashboard::getUrl())
        ->assertOk()
        ->assertSee('Pending posts')
        ->assertSee('3');
});

it('shows reported posts count on moderation dashboard', function () {
    $admin = User::factory()->admin()->create();

    Post::factory()->published()->create(['reports_count' => 2]);
    Post::factory()->published()->create([
        'reports_count' => 0,
        'needs_review' => true,
    ]);
    Post::factory()->published()->create(['reports_count' => 0]);

    $this->actingAs($admin)
        ->get(ModerationDashboard::getUrl())
        ->assertOk()
        ->assertSee('Reported posts')
        ->assertSee('2');
});
