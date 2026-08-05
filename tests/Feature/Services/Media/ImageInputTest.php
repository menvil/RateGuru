<?php

use App\Enums\ImageInputSource;
use App\Services\Media\Exceptions\ImageDecodeException;
use App\Services\Media\Exceptions\ImageTooLargeException;
use App\Services\Media\ImageInput;
use Illuminate\Http\UploadedFile;

it('builds an ImageInput from an uploaded file within the byte limit', function () {
    $file = UploadedFile::fake()->image('dish.jpg', 800, 600);

    $input = ImageInput::fromUploadedFile($file, 5 * 1024 * 1024);

    expect($input->bytes)->not->toBeEmpty()
        ->and($input->originalFilename)->toBe('dish.jpg')
        ->and($input->source)->toBe(ImageInputSource::Upload);
});

it('rejects an oversized upload via getSize() without ever reading its content', function () {
    $file = Mockery::mock(UploadedFile::fake()->image('dish.jpg', 800, 600))->makePartial();
    $file->shouldReceive('getSize')->andReturn(10_000_000);
    $file->shouldReceive('getContent')->never();

    expect(fn () => ImageInput::fromUploadedFile($file, 5 * 1024 * 1024))
        ->toThrow(ImageTooLargeException::class);
});

it('falls through to reading content when getSize() cannot determine a size', function () {
    $file = Mockery::mock(UploadedFile::fake()->image('dish.jpg', 800, 600))->makePartial();
    $file->shouldReceive('getSize')->andReturn(false);

    $input = ImageInput::fromUploadedFile($file, 5 * 1024 * 1024);

    expect($input->bytes)->not->toBeEmpty();
});

it('maps a getSize() stat failure to a narrow exception instead of letting it escape', function () {
    // SplFileInfo::getSize() throws RuntimeException (not just false) when
    // the underlying temp file can't be stat'd at all.
    $file = Mockery::mock(UploadedFile::fake()->image('dish.jpg', 800, 600))->makePartial();
    $file->shouldReceive('getSize')->andThrow(new RuntimeException('stat failed'));

    expect(fn () => ImageInput::fromUploadedFile($file, 5 * 1024 * 1024))
        ->toThrow(ImageDecodeException::class);
});

it('propagates the given source', function () {
    $file = UploadedFile::fake()->image('dish.jpg', 800, 600);

    $input = ImageInput::fromUploadedFile($file, 5 * 1024 * 1024, ImageInputSource::UrlImport);

    expect($input->source)->toBe(ImageInputSource::UrlImport);
});
