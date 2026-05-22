<?php

namespace App\Livewire\Profile;

use App\Models\Post;
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

    /**
     * @return array{published_posts: int, total_upvotes: int, comments_received: int}
     */
    public function getStatsProperty(): array
    {
        $posts = Post::query()
            ->published()
            ->where('user_id', $this->profileUser->id);

        return [
            'published_posts' => (clone $posts)->count(),
            'total_upvotes' => (clone $posts)->sum('upvotes_count'),
            'comments_received' => (clone $posts)->sum('comments_count'),
        ];
    }

    public function render(): View
    {
        return view('livewire.profile.profile-page');
    }
}
