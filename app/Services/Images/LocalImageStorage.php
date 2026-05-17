<?php

namespace App\Services\Images;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class LocalImageStorage implements ImageStorage
{
    public function storePostImage(UploadedFile $file, User $user): StoredImage
    {
        $disk = config('rateguru.images.disk', 'public');
        $path = $file->storePublicly("posts/{$user->id}", $disk);

        return new StoredImage(
            path: $path,
            url: Storage::disk($disk)->url($path),
            thumbnailUrl: null,
            disk: $disk,
        );
    }
}
