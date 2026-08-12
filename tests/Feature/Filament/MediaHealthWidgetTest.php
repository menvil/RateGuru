<?php

use App\Enums\MediaAuditRunStatus;
use App\Filament\Pages\MediaDiagnosticsPage;
use App\Filament\Widgets\MediaHealthWidget;
use App\Models\MediaAuditRun;
use App\Models\User;
use Livewire\Livewire;

it('shows unknown health with no completed audit yet', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(MediaHealthWidget::class)
        ->assertSee('Unknown');
});

it('shows the latest completed run\'s counters', function () {
    $admin = User::factory()->admin()->create();
    MediaAuditRun::factory()->create([
        'status' => MediaAuditRunStatus::Completed,
        'completed_at' => now(),
        'missing_masters' => 3,
        'missing_variant_files' => 2,
        'purgeable_assets' => 1,
        'failed_media_jobs' => 4,
    ]);

    $component = Livewire::actingAs($admin)->test(MediaHealthWidget::class);

    $component->assertSee('Critical'); // missing_masters > 0
    $component->assertSee('3');
    $component->assertSee('2');
    $component->assertSee('4');
});

it('ignores an older completed run in favor of the most recently completed one', function () {
    $admin = User::factory()->admin()->create();
    MediaAuditRun::factory()->create([
        'status' => MediaAuditRunStatus::Completed,
        'completed_at' => now()->subDay(),
        'missing_masters' => 9,
    ]);
    MediaAuditRun::factory()->create([
        'status' => MediaAuditRunStatus::Completed,
        'completed_at' => now(),
        'missing_masters' => 0,
    ]);

    Livewire::actingAs($admin)
        ->test(MediaHealthWidget::class)
        ->assertSee('Healthy')
        ->assertDontSee('Critical');
});

it('links to the media diagnostics page', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(MediaHealthWidget::class)
        ->assertSee(MediaDiagnosticsPage::getUrl(), false);
});

it('is visible on the dashboard for an admin', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get('/admin');

    $response->assertOk();
    $response->assertSee('Media health');
});

it('is hidden from the dashboard for a moderator, since MediaDiagnosticsPage itself is admin-only', function () {
    $moderator = User::factory()->moderator()->create();

    $response = $this->actingAs($moderator)->get('/admin');

    // The Dashboard itself is still reachable (moderators moderate content
    // there) — only this one widget's content must be absent.
    $response->assertOk();
    $response->assertDontSee('Media health');
});
