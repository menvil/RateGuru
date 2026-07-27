<?php

use App\Livewire\Feed\FeedPage;
use App\Livewire\Feed\PostDrawer;
use App\Models\Post;
use App\Models\ProjectSettings;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('keeps the desktop split column but mounts a full-width mobile overlay when split mode is enabled', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => ['post_detail_overlay_mode' => false],
    ]);
    $post = Post::factory()->published()->create();

    $component = Livewire::test(FeedPage::class)
        ->assertSee('data-testid="mobile-post-detail-overlay"', false);

    $component
        ->call('selectPost', $post->id)
        ->assertSee('data-testid="post-detail-column"', false)
        ->assertSee('hidden min-w-0 lg:block', false);
});

it('keeps selected post detail queries bounded in split mode', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => ['post_detail_overlay_mode' => false],
    ]);
    $post = Post::factory()->published()->create();
    $detailQueries = 0;

    DB::listen(function (QueryExecuted $query) use (&$detailQueries): void {
        if (str_contains($query->sql, '"posts"."id" = ?')
            || str_contains($query->sql, '`posts`.`id` = ?')) {
            $detailQueries++;
        }
    });

    Livewire::test(FeedPage::class, ['search' => 'no matching feed result'])
        ->dispatch('select-post', postId: $post->id)
        ->assertSet('selectedPostId', $post->id);

    $feedView = file_get_contents(resource_path('views/livewire/feed/feed-page.blade.php'));

    expect($detailQueries)->toBeLessThanOrEqual(2)
        ->and(substr_count($feedView, 'wire:lazy'))->toBe(2);
});

it('renders the mobile-only post drawer full width through the landscape breakpoint', function () {
    $post = Post::factory()->published()->create();

    $html = Livewire::test(PostDrawer::class, [
        'asOverlay' => true,
        'mobileOnly' => true,
    ])
        ->dispatch('select-post', postId: $post->id)
        ->assertSet('isOpen', true)
        ->html();

    expect($html)
        ->toContain('data-testid="mobile-post-detail-overlay"')
        ->toContain('lg:hidden')
        ->toContain('w-full')
        ->not->toContain('md:w-[min(70vw,1008px)]');
});

it('keeps the configured global overlay full width until desktop', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => ['post_detail_overlay_mode' => true],
    ]);

    $html = $this->get(route('feed'))->assertOk()->getContent();

    expect($html)
        ->toContain('data-testid="post-detail-overlay-host"')
        ->toContain('w-full')
        ->toContain('lg:w-[min(70vw,1008px)]')
        ->not->toContain('md:w-[min(70vw,1008px)]');
});

it('does not scroll the page to inline details on mobile', function () {
    $html = Livewire::test(FeedPage::class)->html();

    expect($html)
        ->toContain('if (window.innerWidth < 1024) return')
        ->toMatch('/scrollToSelectedPost\\(postId\\).*?if \\(window\\.innerWidth < 1024\\) return;.*?this\\.\\$nextTick/s');
});

it('closes the overlay immediately and clears its selected post', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['asOverlay' => true])
        ->dispatch('select-post', postId: $post->id)
        ->assertSet('isOpen', true)
        ->dispatch('clear-selected-post')
        ->assertSet('isOpen', false)
        ->assertSet('postId', null)
        ->assertSee("classList.add('translate-x-full'", false);
});
