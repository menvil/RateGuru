<?php

use App\Enums\MediaKind;
use App\Enums\MediaVisibility;
use App\Services\Media\MediaPathGenerator;
use App\Services\Media\MediaStoreRequest;
use Tests\TestCase;

uses(TestCase::class);

it('generates a post image path nested under the configured directory by year and month', function () {
    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: 42,
    );

    $path = (new MediaPathGenerator)->generate($request, 'jpg');

    expect($path)->toMatch('#^media/post-images/\d{4}/\d{2}/[0-9a-f-]{36}\.jpg$#');
});

it('generates an avatar path nested under the owner user id', function () {
    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/avatars',
        kind: MediaKind::Avatar,
        visibility: MediaVisibility::Public,
        ownerUserId: 7,
    );

    $path = (new MediaPathGenerator)->generate($request, 'png');

    expect($path)->toMatch('#^media/avatars/7/[0-9a-f-]{36}\.png$#');
});

it('omits the extension when none is given', function () {
    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/avatars',
        kind: MediaKind::Avatar,
        visibility: MediaVisibility::Public,
        ownerUserId: 7,
    );

    $path = (new MediaPathGenerator)->generate($request, null);

    expect($path)->toMatch('#^media/avatars/7/[0-9a-f-]{36}$#');
});

it('strips an unsafe extension instead of embedding it in the path', function () {
    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/avatars',
        kind: MediaKind::Avatar,
        visibility: MediaVisibility::Public,
        ownerUserId: 7,
    );

    $path = (new MediaPathGenerator)->generate($request, '../evil');

    expect($path)->not->toContain('..')
        ->and($path)->not->toContain('/evil')
        ->and($path)->toMatch('#^media/avatars/7/[0-9a-f-]{36}$#');
});

it('is collision-resistant across repeated calls for the same request', function () {
    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: 1,
    );

    $generator = new MediaPathGenerator;
    $paths = array_map(fn () => $generator->generate($request, 'jpg'), range(1, 20));

    expect(array_unique($paths))->toHaveCount(20);
});

it('never derives the path from the client-supplied original filename', function () {
    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: 1,
    );

    $path = (new MediaPathGenerator)->generate($request, 'jpg');

    expect($path)->not->toContain('my dish photo');
});

it('returns the explicit deterministic path when the request provides one', function () {
    $request = new MediaStoreRequest(
        disk: 'public',
        directory: 'media/post-images',
        kind: MediaKind::PostImage,
        visibility: MediaVisibility::Public,
        ownerUserId: null,
        explicitPath: 'demo/posts/sample-01.svg',
    );

    $path = (new MediaPathGenerator)->generate($request, 'jpg');

    expect($path)->toBe('demo/posts/sample-01.svg');
});
