<?php

use App\Enums\CommentStatus;
use App\Enums\UserStatus;
use App\Filament\Pages\ModerationDashboard;
use App\Filament\Widgets\PendingPostsWidget;
use App\Filament\Widgets\ReportedCommentsWidget;
use App\Filament\Widgets\ReportedPostsWidget;
use App\Filament\Widgets\SuspiciousUsersWidget;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('allows admin to access moderation dashboard', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(ModerationDashboard::getUrl())
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertDontSee('Moderation Dashboard')
        ->assertDontSee('Latest reports')
        ->assertDontSee('data-testid="admin-dashboard"', false);
});

it('allows moderator to access moderation dashboard', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get(ModerationDashboard::getUrl())
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertDontSee('Moderation Dashboard')
        ->assertDontSee('Latest reports');
});

it('does not allow normal user to access moderation dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(ModerationDashboard::getUrl())
        ->assertForbidden();
});

it('registers moderation dashboard navigation metadata', function () {
    expect(ModerationDashboard::shouldRegisterNavigation())->toBeFalse()
        ->and(ModerationDashboard::getNavigationLabel())->toBe('Dashboard')
        ->and(ModerationDashboard::getSlug())->toBe('moderation-dashboard');
});

it('renders moderation dashboard widgets horizontally without latest reports table', function () {
    $dashboard = app(ModerationDashboard::class);

    expect($dashboard->getHeaderWidgetsColumns())->toBe(4)
        ->and($dashboard->getVisibleFooterWidgets())->toBeEmpty();
});

it('shows pending posts count on moderation dashboard', function () {
    $admin = User::factory()->admin()->create();

    Post::factory()->pending()->count(3)->create();
    Post::factory()->published()->count(2)->create();

    Livewire::actingAs($admin)
        ->test(PendingPostsWidget::class)
        ->assertSeeInOrder(['Pending posts', '3']);
});

it('shows reported posts count on moderation dashboard', function () {
    $admin = User::factory()->admin()->create();

    Post::factory()->published()->create(['reports_count' => 2]);
    Post::factory()->published()->create([
        'reports_count' => 0,
        'needs_review' => true,
    ]);
    Post::factory()->published()->create(['reports_count' => 0]);

    Livewire::actingAs($admin)
        ->test(ReportedPostsWidget::class)
        ->assertSeeInOrder(['Reported posts', '2']);
});

it('shows reported comments count on moderation dashboard', function () {
    $admin = User::factory()->admin()->create();

    Comment::factory()->create([
        'reports_count' => 3,
        'status' => CommentStatus::Visible,
    ]);
    Comment::factory()->create([
        'reports_count' => 0,
        'status' => CommentStatus::Visible,
    ]);

    Livewire::actingAs($admin)
        ->test(ReportedCommentsWidget::class)
        ->assertSeeInOrder(['Reported comments', '1']);
});

it('shows suspicious users count on moderation dashboard', function () {
    $admin = User::factory()->admin()->create();

    $reportedPostAuthor = User::factory()->create();
    Post::factory()
        ->for($reportedPostAuthor)
        ->published()
        ->create(['reports_count' => 2]);

    $reportedCommentAuthor = User::factory()->create();
    Comment::factory()
        ->for($reportedCommentAuthor, 'user')
        ->create(['reports_count' => 1]);

    User::factory()->create([
        'status' => UserStatus::Shadowbanned,
    ]);

    User::factory()->create([
        'status' => UserStatus::Active,
    ]);

    Livewire::actingAs($admin)
        ->test(SuspiciousUsersWidget::class)
        ->assertSeeInOrder(['Suspicious users', '3']);
});
