<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves the avatar url through the avatar asset disk and path', function () {
    Storage::fake('public');

    $user = User::factory()->withAvatar(path: 'avatars/test.jpg', disk: 'public')->create();

    expect($user->resolved_avatar_url)->toContain('/storage/avatars/test.jpg');
});

it('returns null when there is no avatar asset', function () {
    $user = User::factory()->create(['avatar_asset_id' => null]);

    expect($user->resolved_avatar_url)->toBeNull();
});

it('resolves through the asset\'s own disk rather than a hardcoded default disk', function () {
    config()->set('filesystems.disks.cdn_test', [
        'driver' => 'local',
        'root' => storage_path('app/cdn_test'),
        'url' => 'https://cdn.example.test',
        'visibility' => 'public',
    ]);

    $user = User::factory()->withAvatar(path: 'avatars/test.jpg', disk: 'cdn_test')->create();

    expect($user->resolved_avatar_url)->toBe('https://cdn.example.test/avatars/test.jpg');
});

it('does not reference the filesystem directly from the model', function () {
    $source = file_get_contents(app_path('Models/User.php'));

    expect($source)
        ->not->toContain('Storage::')
        ->not->toContain(Storage::class);
});
