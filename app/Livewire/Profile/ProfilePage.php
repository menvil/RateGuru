<?php

namespace App\Livewire\Profile;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class ProfilePage extends Component
{
    public User $profileUser;

    public function mount(string $username): void
    {
        $this->profileUser = User::query()
            ->where('username', $username)
            ->firstOrFail();
    }

    public function render(): View
    {
        return view('livewire.profile.profile-page');
    }
}
