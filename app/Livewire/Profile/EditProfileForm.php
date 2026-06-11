<?php

namespace App\Livewire\Profile;

use App\Models\User;
use App\Support\Profile\ProfileValidationRules;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class EditProfileForm extends Component
{
    public mixed $display_name = null;

    public mixed $bio = null;

    public mixed $profile_website_url = null;

    public string $rating_activity_visibility = 'private';

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
        $validated = $this->validate(
            app(ProfileValidationRules::class)->rules()
        );

        /** @var User $user */
        $user = auth()->user();
        $user->update($validated);
    }

    public function render(): View
    {
        return view('livewire.profile.edit-profile-form');
    }
}
