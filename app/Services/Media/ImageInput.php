<?php

namespace App\Services\Media;

use App\Enums\ImageInputSource;
use App\Services\Media\Exceptions\ImageDecodeException;
use App\Services\Media\Exceptions\ImageTooLargeException;
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

    public static function fromUploadedFile(UploadedFile $file, int $maxBytes, ImageInputSource $source = ImageInputSource::Upload): self
    {
        try {
            // Cheap size stat before the full read below — avoids buffering
            // an oversized file into memory just to have GdImageIngestor's
            // own byte-length check reject it a moment later. This matters
            // most for callers that don't already sit behind a Livewire/form
            // `max:` rule (e.g. an action invoked directly). SplFileInfo's
            // getSize() throws (not just returns false) if the underlying
            // temp file can't be stat'd at all, so it has to sit inside this
            // same try/catch to still map to the read-failure exception.
            $size = $file->getSize();

            if ($size !== false && $size > $maxBytes) {
                throw ImageTooLargeException::exceedsMaxBytes($size, $maxBytes);
            }

            $bytes = $file->getContent();
        } catch (ImageTooLargeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ImageDecodeException::couldNotReadUploadedFile($file->getClientOriginalName(), $exception);
        }

        return new self($bytes, $file->getClientOriginalName(), $source);
    }
}
