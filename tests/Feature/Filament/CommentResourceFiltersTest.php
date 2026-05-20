<?php

use App\Enums\CommentStatus;
use App\Filament\Resources\Comments\Pages\ListComments;
use App\Models\Comment;
use App\Models\User;
use Livewire\Livewire;

it('filters hidden comments in comment resource', function () {
    $admin = User::factory()->admin()->create();
    $hidden = Comment::factory()->create([
        'body' => 'Hidden comment',
        'status' => CommentStatus::Hidden,
    ]);
    $visible = Comment::factory()->create([
        'body' => 'Visible comment',
        'status' => CommentStatus::Visible,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListComments::class)
        ->filterTable('hidden')
        ->assertCanSeeTableRecords([$hidden])
        ->assertCanNotSeeTableRecords([$visible]);
});

it('filters reported comments in comment resource', function () {
    $admin = User::factory()->admin()->create();
    $reported = Comment::factory()->create([
        'body' => 'Reported comment',
        'reports_count' => 2,
    ]);
    $clean = Comment::factory()->create([
        'body' => 'Clean comment',
        'reports_count' => 0,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListComments::class)
        ->filterTable('reported')
        ->assertCanSeeTableRecords([$reported])
        ->assertCanNotSeeTableRecords([$clean]);
});
