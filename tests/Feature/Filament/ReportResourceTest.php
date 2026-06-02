<?php

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Livewire\Livewire;

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

it('renders in the flat admin navigation', function () {
    expect(ReportResource::getNavigationGroup())->toBeNull();
});

it('does not expose create or edit pages in this phase', function () {
    expect(array_keys(ReportResource::getPages()))->toBe(['index']);
});

it('renders Post target type in report resource table', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->published()->create();
    $report = Report::factory()->create([
        'target_type' => Post::class,
        'target_id' => $post->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->assertCanSeeTableRecords([$report])
        ->assertTableColumnExists('target_type')
        ->assertTableColumnExists('target_title')
        ->assertCanRenderTableColumn('target_type')
        ->assertSee('Post')
        ->assertSee(route('posts.show', $post), false);
});

it('does not link reported non-published posts to the public post page', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->pending()->create(['title' => 'Pending reported post']);
    $report = Report::factory()->create([
        'target_type' => Post::class,
        'target_id' => $post->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->assertCanSeeTableRecords([$report])
        ->assertSee('Pending reported post')
        ->assertDontSee(route('posts.show', $post), false);
});

it('renders Comment target type in report resource table', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->published()->create();
    $comment = Comment::factory()->for($post)->create();
    $report = Report::factory()->create([
        'target_type' => Comment::class,
        'target_id' => $comment->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->assertCanSeeTableRecords([$report])
        ->assertSee('Comment')
        ->assertSee(route('posts.show', $comment->post).'#comment-'.$comment->id, false);
});

it('does not link reported comments when their post is not published', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->pending()->create();
    $comment = Comment::factory()->for($post)->create(['body' => 'Reported pending comment']);
    $report = Report::factory()->create([
        'target_type' => Comment::class,
        'target_id' => $comment->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->assertCanSeeTableRecords([$report])
        ->assertSee('Reported pending comment')
        ->assertDontSee(route('posts.show', $post).'#comment-'.$comment->id, false);
});

it('renders report reason in report resource table', function () {
    $admin = User::factory()->admin()->create();
    $report = Report::factory()->create([
        'reason' => ReportReason::Spam,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->assertCanSeeTableRecords([$report])
        ->assertTableColumnExists('reason')
        ->assertCanRenderTableColumn('reason')
        ->assertSee('spam');
});

it('renders report reporter in report resource table', function () {
    $admin = User::factory()->admin()->create();
    $reporter = User::factory()->create(['username' => 'reporter_user']);
    $report = Report::factory()->for($reporter, 'reporter')->create();

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->assertCanSeeTableRecords([$report])
        ->assertTableColumnExists('reporter.username')
        ->assertCanRenderTableColumn('reporter.username')
        ->assertSee('reporter_user');
});

it('renders sortable status badge column in report resource table', function () {
    $admin = User::factory()->admin()->create();
    $report = Report::factory()->create([
        'status' => ReportStatus::Open,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->assertCanSeeTableRecords([$report])
        ->assertTableColumnExists('status')
        ->assertCanRenderTableColumn('status')
        ->assertSee('open');
});

it('renders created_at column in report resource table', function () {
    $admin = User::factory()->admin()->create();
    $report = Report::factory()->create([
        'created_at' => now()->subDay(),
    ]);

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->assertCanSeeTableRecords([$report])
        ->assertTableColumnExists('created_at')
        ->assertCanRenderTableColumn('created_at');
});

it('sorts report resource table by newest report first by default', function () {
    $admin = User::factory()->admin()->create();
    $older = Report::factory()->create(['created_at' => now()->subDays(3)]);
    $newer = Report::factory()->create(['created_at' => now()->subHour()]);

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
});

it('renders Unknown for unrecognised target type without crashing', function () {
    // Use User::class as a target_type the resource does not know about; the
    // morphTo eager-load resolves cleanly while the column still falls
    // through to the 'Unknown' branch.
    $admin = User::factory()->admin()->create();
    $someone = User::factory()->create();
    $report = Report::factory()->create([
        'target_type' => User::class,
        'target_id' => $someone->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->assertCanSeeTableRecords([$report])
        ->assertSee('Unknown');
});
