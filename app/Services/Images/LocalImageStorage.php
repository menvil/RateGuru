<?php

namespace App\Services\Images;

use App\Models\User;
use Illuminate\Http\UploadedFile;

final class LocalImageStorage implements ImageStorage
{
    public function storePostImage(UploadedFile $file, User $user): StoredMedia
    {
        return $this->store($file, "posts/{$user->id}");
    }

    public function storeAvatar(UploadedFile $file, User $user): StoredMedia
    {
        return $this->store($file, 'avatars');
    }

    private function store(UploadedFile $file, string $directory): StoredMedia
    {
        $disk = config('rateguru.images.disk', 'public');
        $dimensions = @getimagesize($file->getRealPath());
        $path = $file->storePublicly($directory, $disk);

        return new StoredMedia(
            disk: $disk,
            path: $path,
            originalFilename: $file->getClientOriginalName(),
            mimeType: $file->getMimeType() ?? 'application/octet-stream',
            extension: $file->extension(),
            byteSize: $file->getSize() ?: 0,
            width: $dimensions[0] ?? null,
            height: $dimensions[1] ?? null,
        );
    }
}
