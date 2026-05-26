<?php

namespace App\Livewire\Posts;

use App\Actions\Posts\TogglePostSaveAction;
use App\Models\Post;
use App\Models\PostSave;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class SavePostButton extends Component
{
    public int $postId;

    public bool $saved = false;

    public ?string $message = null;

    public function mount(int $postId): void
    {
        $this->postId = $postId;
        $this->saved = $this->isSaved();
    }

    public function toggle(TogglePostSaveAction $togglePostSaveAction): void
    {
        $user = auth()->user();

        if ($user === null) {
            $this->message = 'Log in to save posts.';

            return;
        }

        $post = Post::query()->published()->find($this->postId);

        if ($post === null) {
            $this->message = 'This post is unavailable.';

            return;
        }

        $this->saved = $togglePostSaveAction->handle($user, $post);
        $this->message = $this->saved ? 'Saved' : 'Removed';
    }

    public function render(): View
    {
        return view('livewire.posts.save-post-button');
    }

    private function isSaved(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return PostSave::query()
            ->where('user_id', $user->id)
            ->where('post_id', $this->postId)
            ->exists();
    }
}
