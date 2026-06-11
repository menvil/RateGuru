<?php

namespace App\Actions\Profile;

use App\Models\User;
use App\Support\Observability\DomainLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class UpdateUserProfileAction
{
    public function __construct(private readonly DomainLogger $logger) {}

    public function execute(User $user, array $validatedData, ?UploadedFile $avatar): void
    {
        $update = [
            'display_name' => $validatedData['display_name'] ?? null,
            'bio' => $validatedData['bio'] ?? null,
            'profile_website_url' => $validatedData['profile_website_url'] ?? null,
            'rating_activity_visibility' => $validatedData['rating_activity_visibility'] ?? null,
        ];

        $oldAvatarPath = null;

        if ($avatar !== null) {
            $oldAvatarPath = $user->avatar_path;
            $update['avatar_path'] = $avatar->store('avatars', 'public');
        }

        $user->update($update);

        if ($oldAvatarPath) {
            Storage::disk('public')->delete($oldAvatarPath);
        }

        $this->logger->info(
            $avatar !== null ? 'profile.avatar.updated' : 'profile.updated',
            ['user_id' => $user->id],
        );
    }
}
