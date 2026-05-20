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
