<?php

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('belongs to an avatar asset', function () {
    $asset = MediaAsset::factory()->avatar()->create();
    $user = User::factory()->create(['avatar_asset_id' => $asset->id]);

    expect($user->avatarAsset)->toBeInstanceOf(MediaAsset::class)
        ->and($user->avatarAsset->id)->toBe($asset->id);
});

it('has no avatar asset by default', function () {
    $user = User::factory()->create(['avatar_asset_id' => null]);

    expect($user->avatarAsset)->toBeNull();
});

it('nulls the avatar asset id when the referenced asset is deleted', function () {
    $asset = MediaAsset::factory()->avatar()->create();
    $user = User::factory()->create(['avatar_asset_id' => $asset->id]);

    $asset->forceDelete();

    expect($user->fresh()->avatar_asset_id)->toBeNull();
});
