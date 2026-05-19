<?php

use App\Livewire\Reports\ReportModal;
use App\Models\Post;
use Livewire\Livewire;

it('has alpine report modal open close behavior', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])
        ->assertSee('x-data', false)
        ->assertSee('reportOpen', false)
        ->assertSee('x-show', false)
        ->assertSee('x-cloak', false)
        ->assertSee('@keydown.escape.window', false)
        ->assertSee('data-testid="open-report-modal"', false)
        ->assertSee('data-testid="close-report-modal"', false);
});

it('renders report reasons', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])
        ->assertSee('Spam')
        ->assertSee('Offensive')
        ->assertSee('Fake')
        ->assertSee('Copyright')
        ->assertSee('Not food')
        ->assertSee('Other');
});

it('can render report modal component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])->assertStatus(200)
        ->assertSee('data-testid="report-modal"', false);
});
