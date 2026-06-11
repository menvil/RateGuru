<?php

namespace App\Livewire\Profile;

use App\Models\User;
use App\Support\Profile\ProfileValidationRules;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Livewire\Component;
use Livewire\Contracts\View;
use Livewire\WithFileUploads;

class EditProfileForm extends Component
{
    use WithFileUploads;

    public mixed $display_name = null;

    public mixed $bio = null;

    public mixed $profile_website_url = null;

    public string $rating_activity_visibility = 'private';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $avatar = null;

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $this->display_name = $user->display_name;
        $this->bio = $user->bio;
        $this->profile_website_url = $user->profile_website_url;
        $this->rating_activity_visibility = $user->rating_activity_visibility ?? 'private';
    }

    public function save(): void
    {
        $rules = array_merge(
            app(ProfileValidationRules::class)->rules(),
            ['avatar' => $this->avatarRules()],
        );

        $validated = $this->validate($rules);

        /** @var User $user */
        $user = auth()->user();

        $update = array_filter([
            'display_name' => $validated['display_name'],
            'bio' => $validated['bio'],
            'profile_website_url' => $validated['profile_website_url'],
            'rating_activity_visibility' => $validated['rating_activity_visibility'] ?? $this->rating_activity_visibility,
        ], fn ($v) => $v !== null || in_array($v, [null], true));

        if ($this->avatar !== null) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $update['avatar_path'] = $this->avatar->store('avatars', 'public');
        }

        $user->update($update);
    }

    /** @return list<mixed> */
    private function avatarRules(): array
    {
        $maxKb = (int) config('uploads.images.max_kilobytes', 5120);

        return [
            'nullable',
            File::image()->max($maxKb),
        ];
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.profile.edit-profile-form');
    }
}
