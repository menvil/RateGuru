<?php

use App\Enums\CommentStatus;
use App\Enums\UserStatus;
use App\Filament\Pages\ModerationDashboard;
use App\Filament\Resources\Comments\Pages\ListComments;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Widgets\PendingPostsWidget;
use App\Filament\Widgets\ReportedCommentsWidget;
use App\Filament\Widgets\ReportedPostsWidget;
use App\Filament\Widgets\SuspiciousUsersWidget;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
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

/**
 * The query string of the link a stat widget's only card points at.
 *
 * @param  class-string  $widget
 * @return array<string, mixed>
 */
function moderationWidgetLinkQuery(string $widget): array
{
    /** @var list<Stat> $stats */
    $stats = (fn (): array => $this->getStats())->call(Livewire::test($widget)->instance());

    parse_str((string) parse_url((string) $stats[0]->getUrl(), PHP_URL_QUERY), $query);

    return $query;
}

it('opens each moderation widget\'s list on exactly the records the widget counts', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $pending = Post::factory()->pending()->create();
    $reported = Post::factory()->published()->create(['reports_count' => 2]);
    // Flagged once, and its reports dismissed since: still counted.
    $flagged = Post::factory()->published()->create(['reports_count' => 0, 'needs_review' => true]);
    $clean = Post::factory()->published()->create(['reports_count' => 0]);

    $reportedComment = Comment::factory()->create(['reports_count' => 3, 'status' => CommentStatus::Visible]);
    $cleanComment = Comment::factory()->create(['reports_count' => 0, 'status' => CommentStatus::Visible]);

    $listed = fn (string $widget): array => array_column(Livewire::withQueryParams(moderationWidgetLinkQuery($widget))->test(ListPosts::class)->viewData('rows'), 'id');

    $pendingList = $listed(PendingPostsWidget::class);
    $reportedList = $listed(ReportedPostsWidget::class);

    expect($pendingList)->toContain($pending->id)
        ->and(array_intersect($pendingList, [$reported->id, $flagged->id, $clean->id]))->toBe([])
        ->and($reportedList)->toContain($reported->id, $flagged->id)
        ->and(array_intersect($reportedList, [$pending->id, $clean->id]))->toBe([]);

    $comments = array_column(Livewire::withQueryParams(moderationWidgetLinkQuery(ReportedCommentsWidget::class))->test(ListComments::class)->viewData('rows'), 'id');

    expect($comments)->toContain($reportedComment->id)->not->toContain($cleanComment->id);
});

it('counts on each reported card exactly what the list it opens shows', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Post::factory()->published()->create(['reports_count' => 2]);
    // Deleted by its author after being reported: the list still shows it.
    Post::factory()->authorDeleted()->create(['reports_count' => 1]);
    Post::factory()->published()->create(['reports_count' => 0]);

    Comment::factory()->create(['reports_count' => 3, 'status' => CommentStatus::Visible]);
    // Deleted by its author after being reported: listed.
    Comment::factory()->create(['reports_count' => 1, 'status' => CommentStatus::Visible, 'deleted_at' => now()]);
    // Removed for good by moderation: no longer waiting for anyone.
    Comment::factory()->create(['reports_count' => 2, 'status' => CommentStatus::Hidden, 'moderation_removed_at' => now()]);

    $carded = fn (string $widget): int => (int) (fn (): array => $this->getStats())->call(Livewire::test($widget)->instance())[0]->getValue();

    expect($carded(ReportedPostsWidget::class))->toBe(2)
        ->and(Livewire::withQueryParams(moderationWidgetLinkQuery(ReportedPostsWidget::class))->test(ListPosts::class)->viewData('rows'))->toHaveCount(2)
        ->and($carded(ReportedCommentsWidget::class))->toBe(2)
        ->and(Livewire::withQueryParams(moderationWidgetLinkQuery(ReportedCommentsWidget::class))->test(ListComments::class)->viewData('rows'))->toHaveCount(2);
});

it('keeps a member on the suspicious card and list after they delete what was reported', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $postAuthor = User::factory()->create();
    Post::factory()->for($postAuthor)->authorDeleted()->create(['reports_count' => 2]);
    $commentAuthor = User::factory()->create();
    Comment::factory()->for($commentAuthor)->create(['reports_count' => 1, 'deleted_at' => now()]);
    User::factory()->create();

    $carded = (int) (fn (): array => $this->getStats())->call(Livewire::test(SuspiciousUsersWidget::class)->instance())[0]->getValue();
    $listed = array_column(Livewire::withQueryParams(moderationWidgetLinkQuery(SuspiciousUsersWidget::class))->test(ListUsers::class)->viewData('rows'), 'id');

    expect($carded)->toBe(2)
        ->and($listed)->toEqualCanonicalizing([$postAuthor->id, $commentAuthor->id]);
});
