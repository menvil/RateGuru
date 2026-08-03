<?php

namespace App\Services\Images;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class LocalImageStorage implements ImageStorage
{
    /**
     * Matches media_assets.original_filename's column length, so an unusually
     * long client-supplied filename can't fail the asset insert after the
     * physical upload has already succeeded.
     */
    private const MAX_ORIGINAL_FILENAME_LENGTH = 255;

    public function storePostImage(UploadedFile $file, User $user): StoredMedia
    {
        return $this->store($file, "posts/{$user->id}");
    }

    public function storeAvatar(UploadedFile $file, User $user): StoredMedia
    {
        return $this->store($file, 'avatars');
    }

    public function delete(StoredMedia $media): void
    {
        if (! Storage::disk($media->disk)->delete($media->path)) {
            throw new RuntimeException("Could not delete stored media at [{$media->disk}:{$media->path}].");
        }
    }

    private function store(UploadedFile $file, string $directory): StoredMedia
    {
        $disk = config('rateguru.images.disk', 'public');
        $dimensions = @getimagesize($file->getRealPath());
        $path = $file->storePublicly($directory, $disk);

        if ($path === false) {
            throw new RuntimeException("Could not store the uploaded file on disk [{$disk}].");
        }

        $originalFilename = $file->getClientOriginalName();

        return new StoredMedia(
            disk: $disk,
            path: $path,
            originalFilename: mb_strlen($originalFilename) > self::MAX_ORIGINAL_FILENAME_LENGTH
                ? mb_substr($originalFilename, 0, self::MAX_ORIGINAL_FILENAME_LENGTH)
                : $originalFilename,
            mimeType: $file->getMimeType() ?? 'application/octet-stream',
            extension: $file->extension(),
            byteSize: $file->getSize() ?: 0,
            width: $dimensions[0] ?? null,
            height: $dimensions[1] ?? null,
        );
    }
}
