<?php

namespace App\Services\Images;

use App\Models\User;
use Illuminate\Http\UploadedFile;

final class LocalImageStorage implements ImageStorage
{
    public function storePostImage(UploadedFile $file, User $user): StoredImage
    {
        throw new \LogicException('Not implemented yet.');
    }
}
