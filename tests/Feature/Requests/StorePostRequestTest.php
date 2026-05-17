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
