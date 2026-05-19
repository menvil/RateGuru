<?php

use App\Enums\ReportReason;
use App\Livewire\Reports\ReportModal;
use App\Models\Post;
use Livewire\Livewire;

it('renders report message textarea', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])
        ->assertSee('name="message"', false)
        ->assertSee('maxlength="1000"', false)
        ->assertSee('Optional details')
        ->set('message', 'This content is spam.')
        ->assertSet('message', 'This content is spam.');
});

it('renders report reason selector and updates reason', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(ReportModal::class, [
        'reportableType' => 'post',
        'reportableId' => $post->id,
    ])
        ->assertSee('data-testid="report-reason-selector"', false)
        ->assertSee('name="reason"', false)
        ->assertSet('reason', '')
        ->set('reason', ReportReason::Spam->value)
        ->assertSet('reason', ReportReason::Spam->value);
});

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
