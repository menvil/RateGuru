<?php

use App\Filament\Resources\Reports\Pages\ListReports;
use App\Enums\ReportReason;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Support\AdminNavigationGroup;
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

it('lives under the Moderation navigation group', function () {
    expect(ReportResource::getNavigationGroup())->toBe(AdminNavigationGroup::MODERATION);
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
        ->assertCanRenderTableColumn('target_type')
        ->assertSee('Post');
});

it('renders Comment target type in report resource table', function () {
    $admin = User::factory()->admin()->create();
    $comment = Comment::factory()->create();
    $report = Report::factory()->create([
        'target_type' => Comment::class,
        'target_id' => $comment->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->assertCanSeeTableRecords([$report])
        ->assertSee('Comment');
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

it('renders Unknown for unrecognised target type without crashing', function () {
    $admin = User::factory()->admin()->create();
    $report = Report::factory()->create([
        'target_type' => 'App\\Models\\Missing',
        'target_id' => 9999,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->assertCanSeeTableRecords([$report])
        ->assertSee('Unknown');
});
