<?php

use App\Http\Resources\Api\UserResource as ApiUserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('has api user resource', function () {
    $user = User::factory()->make();

    $resource = new ApiUserResource($user);

    expect($resource)->toBeInstanceOf(ApiUserResource::class);
});

it('resolves api user resource to array', function () {
    $user = User::factory()->create();

    $data = (new ApiUserResource($user))->resolve();

    expect($data)->toBeArray();
});
