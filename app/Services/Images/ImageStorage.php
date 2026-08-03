<?php

namespace App\Services\Images;

use App\Models\User;
use Illuminate\Http\UploadedFile;

interface ImageStorage
{
    public function storePostImage(UploadedFile $file, User $user): StoredMedia;

    public function storeAvatar(UploadedFile $file, User $user): StoredMedia;

    /**
     * Deletes a previously stored file, using its own disk — never a
     * hardcoded one. Used for best-effort compensation when a database
     * operation fails after the file was already written.
     */
    public function delete(StoredMedia $media): void;
}
