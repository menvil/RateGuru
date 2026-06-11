<?php

use App\Data\Posts\CreatePostData;
use App\Livewire\Profile\EditProfileForm;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('logs post created event', function () {
    Log::spy();

    $user = User::factory()->trusted()->create();

    $data = new CreatePostData(
        title: 'Test Post',
    );

    app(\App\Actions\Posts\CreatePostAction::class)->handle($user, $data);

    Log::shouldHaveReceived('info')
        ->with('posts.created', Mockery::on(fn ($ctx) => isset($ctx['post_id'])));
});

it('logs avatar updated event on profile save', function () {
    Log::spy();
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);

    Livewire::actingAs($user)
        ->test(EditProfileForm::class)
        ->set('avatar', $file)
        ->call('save');

    Log::shouldHaveReceived('info')
        ->with('profile.avatar.updated', Mockery::any());
});
