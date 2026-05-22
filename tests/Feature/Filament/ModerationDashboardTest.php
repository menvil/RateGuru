<?php

use App\Enums\CommentStatus;
use App\Enums\ReportReason;
use App\Enums\UserStatus;
use App\Filament\Pages\ModerationDashboard;
use App\Filament\Support\AdminNavigationGroup;
use App\Filament\Widgets\LatestReportsTable;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Livewire\Livewire;

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

    $this->actingAs($admin)
        ->get(ModerationDashboard::getUrl())
        ->assertOk()
        ->assertSee('Reported comments')
        ->assertSee('1');
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

    $this->actingAs($admin)
        ->get(ModerationDashboard::getUrl())
        ->assertOk()
        ->assertSee('Suspicious users')
        ->assertSee('3');
});

it('shows latest reports on moderation dashboard', function () {
    $admin = User::factory()->admin()->create();
    $reporter = User::factory()->create(['username' => 'reporter_ivan']);

    $oldReport = Report::factory()->create([
        'reporter_id' => $reporter->id,
        'reason' => ReportReason::Spam,
        'created_at' => now()->subDays(2),
    ]);

    $newReport = Report::factory()->create([
        'reporter_id' => $reporter->id,
        'reason' => ReportReason::Offensive,
        'created_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(ModerationDashboard::getUrl())
        ->assertOk()
        ->assertSee('Latest reports');

    Livewire::actingAs($admin)
        ->test(LatestReportsTable::class)
        ->assertCanSeeTableRecords([$newReport, $oldReport], inOrder: true)
        ->assertSee('offensive')
        ->assertSee('spam')
        ->assertSee('reporter_ivan')
        ->assertSee('Post')
        ->assertSee('open');
});
