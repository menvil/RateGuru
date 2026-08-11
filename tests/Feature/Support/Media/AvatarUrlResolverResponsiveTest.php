<?php

use App\Enums\MediaVariantName;
use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\User;
use App\Support\Media\AvatarUrlResolver;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('returns null when the user has no avatar asset', function () {
    $user = User::factory()->create(['avatar_asset_id' => null])->load('avatarAsset.variants');

    expect(app(AvatarUrlResolver::class)->responsive($user))->toBeNull();
});

it('returns null for a private avatar asset even when its variants are already loaded, instead of throwing', function () {
    $asset = MediaAsset::factory()->avatar()->dimensions(512, 512)->create([
        'visibility' => MediaVisibility::Private,
    ]);
    MediaVariant::factory()->named(MediaVariantName::Avatar128)->create([
        'media_asset_id' => $asset->id,
        'width' => 128,
        'height' => 128,
    ]);
    $user = User::factory()->create(['avatar_asset_id' => $asset->id])->load('avatarAsset.variants');

    expect($user->avatarAsset->relationLoaded('variants'))->toBeTrue();
    expect(app(AvatarUrlResolver::class)->responsive($user))->toBeNull();
});

it('falls back to the master image when no avatar variants exist yet', function () {
    $asset = MediaAsset::factory()->avatar()->dimensions(512, 512)->create();
    $user = User::factory()->create(['avatar_asset_id' => $asset->id])->load('avatarAsset.variants');

    $image = app(AvatarUrlResolver::class)->responsive($user);

    expect($image)->not->toBeNull()
        ->and($image->srcset)->toBeNull()
        ->and($image->sizes)->toBeNull()
        ->and($image->width)->toBe(512)
        ->and($image->height)->toBe(512);
});

it('prefers avatar_128 as src and includes both sizes in the srcset', function () {
    $asset = MediaAsset::factory()->avatar()->dimensions(512, 512)->create();
    MediaVariant::factory()->named(MediaVariantName::Avatar128)->create([
        'media_asset_id' => $asset->id,
        'width' => 128,
        'height' => 128,
    ]);
    MediaVariant::factory()->named(MediaVariantName::Avatar256)->create([
        'media_asset_id' => $asset->id,
        'width' => 256,
        'height' => 256,
    ]);
    $user = User::factory()->create(['avatar_asset_id' => $asset->id])->load('avatarAsset.variants');

    $image = app(AvatarUrlResolver::class)->responsive($user);

    expect($image->width)->toBe(128)
        ->and($image->height)->toBe(128)
        ->and($image->srcset)->toContain('128w')
        ->and($image->srcset)->toContain('256w')
        ->and($image->sizes)->toBeNull();
});

it('falls back to avatar_256 when avatar_128 does not exist', function () {
    $asset = MediaAsset::factory()->avatar()->dimensions(512, 512)->create();
    MediaVariant::factory()->named(MediaVariantName::Avatar256)->create([
        'media_asset_id' => $asset->id,
        'width' => 256,
        'height' => 256,
    ]);
    $user = User::factory()->create(['avatar_asset_id' => $asset->id])->load('avatarAsset.variants');

    $image = app(AvatarUrlResolver::class)->responsive($user);

    expect($image->width)->toBe(256)
        ->and($image->srcset)->toContain('256w')
        ->and($image->srcset)->not->toContain('128w');
});
