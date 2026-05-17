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
