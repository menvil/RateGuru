<?php

use App\Http\Requests\StorePostRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

it('requires post title', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'image' => UploadedFile::fake()->image('dish.jpg'),
    ], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('title'))->toBeTrue();
});

it('requires post image', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'title' => 'Homemade pasta',
    ], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('image'))->toBeTrue();
});

it('requires image to be a valid image file', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'title' => 'Homemade pasta',
        'image' => UploadedFile::fake()->create('not-image.txt', 10, 'text/plain'),
    ], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('image'))->toBeTrue();
});

it('allows description to be omitted', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'title' => 'Homemade pasta',
        'image' => UploadedFile::fake()->image('dish.jpg'),
    ], $request->rules());

    expect($validator->errors()->has('description'))->toBeFalse();
});

it('allows source url to be omitted', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'title' => 'Homemade pasta',
        'image' => UploadedFile::fake()->image('dish.jpg'),
    ], $request->rules());

    expect($validator->errors()->has('source_url'))->toBeFalse();
});

it('requires source url to be valid url when provided', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'title'      => 'Homemade pasta',
        'image'      => UploadedFile::fake()->image('dish.jpg'),
        'source_url' => 'not-a-url',
    ], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('source_url'))->toBeTrue();
});

it('validates origin truth enum value', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'title'        => 'Homemade pasta',
        'image'        => UploadedFile::fake()->image('dish.jpg'),
        'origin_truth' => 'invalid',
    ], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('origin_truth'))->toBeTrue();
});

it('validates cuisine truth enum value', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'title'         => 'Homemade pasta',
        'image'         => UploadedFile::fake()->image('dish.jpg'),
        'cuisine_truth' => 'invalid',
    ], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('cuisine_truth'))->toBeTrue();
});

it('validates tag ids exist', function () {
    $request = new StorePostRequest();

    $validator = Validator::make([
        'title'   => 'Homemade pasta',
        'image'   => UploadedFile::fake()->image('dish.jpg'),
        'tag_ids' => [999999],
    ], $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('tag_ids.0'))->toBeTrue();
});
