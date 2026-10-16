<?php

use App\Enums\MediaAuditIssueType;
use App\Models\MediaAsset;
use App\Models\MediaAuditIssue;
use App\Models\MediaAuditRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/**
 * Media diagnostics in a real browser: the screen at each width, a figure of
 * the overview narrowing the issues to what it counts, and purging an asset
 * from the inspector, which waits for the acknowledgement.
 */
beforeEach(function () {
    Storage::fake('public');
    actingAs(User::factory()->admin()->create());

    $this->asset = createPurgeableAsset();
    Carbon::setTestNow();

    $this->run = MediaAuditRun::factory()->create(['completed_at' => now(), 'missing_masters' => 1, 'missing_variant_files' => 1, 'purgeable_assets' => 1]);
    $this->missingMaster = MediaAuditIssue::factory()->for($this->run, 'run')->ofType(MediaAuditIssueType::MissingMasterFile)
        ->create(['media_asset_id' => MediaAsset::factory()->postImage()->create()->id, 'disk' => 'public', 'path' => 'posts/2026/09/28/8f3c2a91e1.jpg']);
    $this->missingVariant = MediaAuditIssue::factory()->for($this->run, 'run')->ofType(MediaAuditIssueType::MissingVariantFile)
        ->create(['media_asset_id' => MediaAsset::factory()->postImage()->create()->id, 'disk' => 'public', 'path' => 'posts/2026/10/03/a9f2c4/post_feed_640.webp']);
    $this->purgeable = MediaAuditIssue::factory()->for($this->run, 'run')->ofType(MediaAuditIssueType::PurgeableAsset)
        ->create(['media_asset_id' => $this->asset->id, 'disk' => 'public', 'path' => $this->asset->path]);
});

it('draws the screen in Admin v2 at every width, the issues scrolling inside their card and never the page', function (int $width) {
    $page = visitAdminEditor('/admin/media-diagnostics', $width);

    expect($page->script(<<<'JS'
        (() => {
            const scroll = document.querySelector('.rg-admin-table__scroll')
            const grid = scroll.querySelector('[role="table"]')

            return {
                headings: [...document.querySelectorAll('h1')].map((h) => h.textContent.trim()),
                columns: [...document.querySelectorAll('[role="columnheader"]')].map((cell) => cell.textContent.trim()),
                rows: grid.querySelectorAll('[role="row"]').length - 1,
                topBar: [...document.querySelectorAll('.rg-admin-topbar .rg-admin-button')].map((button) => button.textContent.trim()),
                scrollsWhenNarrower: (scroll.scrollWidth > scroll.clientWidth) === (grid.offsetWidth > scroll.clientWidth),
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            }
        })()
    JS))->toBe([
        'headings' => ['Media diagnostics'],
        'columns' => ['Severity', 'Issue type', 'Asset ID', 'Variant', 'Disk', 'Path', 'Detected', 'Actions'],
        'rows' => 3,
        'topBar' => ['Refresh', 'Run full audit'],
        'scrollsWhenNarrower' => true,
        'overflow' => false,
    ]);
})->with([1440, 1280, 1024, 768, 390]);

it('narrows the issues to what a figure of the overview counts', function () {
    $page = visitAdminEditor('/admin/media-diagnostics');

    $page->click('.rg-admin-media__tile--action:nth-child(6)');
    waitForScript($page, "document.querySelectorAll('.rg-admin-media__row').length", 1);

    expect($page->script(<<<'JS'
        (() => ({
            type: document.querySelector('.rg-admin-media__row .rg-admin-media__type').textContent.trim(),
            trigger: document.querySelector('#rg-admin-media-type').textContent.replace(/\s+/g, ' ').trim(),
            address: new URL(location.href).searchParams.get('type'),
        }))()
    JS))->toBe([
        'type' => 'Missing variant file',
        'trigger' => 'Issue type: Missing variant file',
        'address' => 'missing_variant_file',
    ]);
});

it('purges an asset from the inspector once the acknowledgement is ticked', function () {
    $page = visitAdminEditor('/admin/media-diagnostics');

    $page->click("#rg-admin-media-issue-{$this->purgeable->id} .rg-admin-button:last-child");
    waitForScript($page, "document.querySelector('#rg-admin-media-inspector-title')?.textContent.trim()", "Asset #{$this->asset->id}");

    expect($page->script("[...document.querySelectorAll('.rg-admin-media__file .rg-admin-media__mono')].map((name) => name.textContent.trim())"))
        ->toBe(['master', 'post_detail_1920', 'post_feed_640']);

    $page->click('#rg-admin-media-inspector .rg-admin-button--danger');
    waitForScript($page, "document.querySelector('.rg-admin-dialog__title')?.textContent.trim()", "Purge asset #{$this->asset->id} permanently?");

    expect($page->script("document.querySelector('.rg-admin-dialog .rg-admin-button--danger').disabled"))->toBeTrue();

    $page->click('.rg-admin-dialog .rg-admin-checkbox');
    waitForScript($page, "! document.querySelector('.rg-admin-dialog .rg-admin-button--danger').disabled");
    confirmAdminDialog($page);

    waitForScript($page, "document.querySelector('.rg-admin-toast')?.textContent.includes('Asset #{$this->asset->id} purged')");

    expect(MediaAsset::withTrashed()->find($this->asset->id))->toBeNull()
        ->and(Storage::disk('public')->exists($this->asset->path))->toBeFalse();
});
