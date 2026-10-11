<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\PendingPostsWidget;
use App\Filament\Widgets\ReportedCommentsWidget;
use App\Filament\Widgets\ReportedPostsWidget;
use App\Filament\Widgets\SuspiciousUsersWidget;
use App\Models\User;
use Filament\Support\Enums\Width;

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

it('renders the moderation dashboard with RateGuru branding for admin', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('RateGuru')
        ->assertDontSee('Moderation Dashboard')
        ->assertDontSee('Latest reports');
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
        expect(app($widget)->getColumnSpan())->toBe(1);
    }
});
