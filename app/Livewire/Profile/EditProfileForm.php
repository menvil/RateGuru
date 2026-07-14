<?php

namespace App\Livewire\Profile;

use App\Actions\Profile\UpdateUserProfileAction;
use App\Models\User;
use App\Support\Profile\ProfileValidationRules;
use Illuminate\Validation\Rules\File;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class EditProfileForm extends Component
{
    use WithFileUploads;

    public mixed $display_name = null;

    public mixed $bio = null;

    public mixed $profile_website_url = null;

    public string $rating_activity_visibility = 'private';

    /** @var TemporaryUploadedFile|null */
    public $avatar = null;

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $this->display_name = $user->display_name;
        $this->bio = $user->bio;
        $this->profile_website_url = $user->profile_website_url;
        $this->rating_activity_visibility = $user->rating_activity_visibility?->value ?? 'private';
    }

    public function save(UpdateUserProfileAction $action): void
    {
        $rules = array_merge(
            app(ProfileValidationRules::class)->rules(),
            ['avatar' => $this->avatarRules()],
        );

        $validated = $this->validate($rules);
        $validated['rating_activity_visibility'] ??= $this->rating_activity_visibility;

        /** @var User $user */
        $user = auth()->user();

        $action->execute($user, $validated, $this->avatar);
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
