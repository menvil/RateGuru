<?php

namespace App\Services\Images;

use App\Models\User;
use Illuminate\Http\UploadedFile;

final class CloudinaryImageStorage implements ImageStorage
{
    public function storePostImage(UploadedFile $file, User $user): StoredImage
    {
        throw new \RuntimeException('Cloudinary image storage is not configured yet.');
    }
}
