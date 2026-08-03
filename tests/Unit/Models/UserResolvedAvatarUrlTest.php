<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves avatar url from avatar_path when both avatar_path and avatar_url are set', function () {
    Storage::fake('public');

    $user = User::factory()->create([
        'avatar_path' => 'avatars/test.jpg',
        'avatar_url' => 'https://example.test/avatar.jpg',
    ]);

    expect($user->resolved_avatar_url)
        ->toContain('/storage/avatars/test.jpg')
        ->not->toContain('example.test');
});

it('falls back to avatar_url when avatar_path is null', function () {
    $user = User::factory()->create([
        'avatar_path' => null,
        'avatar_url' => 'https://example.test/avatar.jpg',
    ]);

    expect($user->resolved_avatar_url)->toBe('https://example.test/avatar.jpg');
});

it('returns null when neither avatar_path nor avatar_url is set', function () {
    $user = User::factory()->create([
        'avatar_path' => null,
        'avatar_url' => null,
    ]);

    expect($user->resolved_avatar_url)->toBeNull();
});
