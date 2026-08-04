<?php

namespace App\Services\Media;

use App\Enums\ImageInputSource;
use App\Services\Media\Exceptions\ImageDecodeException;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * The ImageIngestor's only input type. Deliberately doesn't know about
 * UploadedFile or any other transport — adapters below build one from
 * whatever the caller actually has.
 */
final readonly class ImageInput
{
    public function __construct(
        public string $bytes,
        public ?string $originalFilename,
        public ImageInputSource $source,
    ) {}

    public static function fromUploadedFile(UploadedFile $file, ImageInputSource $source = ImageInputSource::Upload): self
    {
        try {
            $bytes = $file->getContent();
        } catch (Throwable $exception) {
            throw ImageDecodeException::couldNotReadUploadedFile($file->getClientOriginalName(), $exception);
        }

        return new self($bytes, $file->getClientOriginalName(), $source);
    }
}
