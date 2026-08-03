<?php

namespace App\Services\Images;

use App\Models\User;
use Illuminate\Http\UploadedFile;

interface ImageStorage
{
    public function storePostImage(UploadedFile $file, User $user): StoredMedia;

    public function storeAvatar(UploadedFile $file, User $user): StoredMedia;
}
