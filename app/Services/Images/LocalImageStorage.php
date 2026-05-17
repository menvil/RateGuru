<?php

namespace App\Services\Images;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class LocalImageStorage implements ImageStorage
{
    public function storePostImage(UploadedFile $file, User $user): StoredImage
    {
        $path = $file->storePublicly("posts/{$user->id}", 'public');

        return new StoredImage(
            path: $path,
            url: Storage::disk('public')->url($path),
            thumbnailUrl: null,
            disk: 'public',
        );
    }
}
