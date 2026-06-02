<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\PendingPostsWidget;
use App\Filament\Widgets\ReportedCommentsWidget;
use App\Filament\Widgets\ReportedPostsWidget;
use App\Filament\Widgets\SuspiciousUsersWidget;
use App\Models\User;
use Filament\Support\Enums\Width;
use Livewire\Livewire;

it('renders the moderation dashboard for moderator', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get('/admin')
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertDontSee('Moderation Dashboard')
        ->assertDontSee('Latest reports')
        ->assertDontSee('data-testid="admin-dashboard"', false);
});

it('renders the moderation dashboard for admin', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertDontSee('Moderation Dashboard')
        ->assertDontSee('Latest reports');
});

it('renders RateGuru branding in the admin panel', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertSee('RateGuru');
});

it('uses the same content width as other admin pages', function () {
    expect(app(Dashboard::class)->getMaxContentWidth())->toBe(Width::SevenExtraLarge);
});

it('renders moderation summary widgets horizontally', function () {
    $dashboard = app(Dashboard::class);

    expect($dashboard->getColumns())->toBe(1)
        ->and($dashboard->getHeaderWidgetsColumns())->toBe(4)
        ->and($dashboard->getVisibleFooterWidgets())->toBeEmpty();

    foreach ([
        PendingPostsWidget::class,
        ReportedPostsWidget::class,
        ReportedCommentsWidget::class,
        SuspiciousUsersWidget::class,
    ] as $widget) {
        expect(Livewire::test($widget)->instance()->getColumnSpan())->toBe(1);
    }
});

it('does not render the filament dashboard placeholder for a normal user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/admin');

    expect($response->getStatusCode())->toBe(403);
});
