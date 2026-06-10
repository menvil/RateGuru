<?php

namespace App\Livewire\Posts;

use App\Actions\Posts\ToggleSavedPostAction;
use App\Exceptions\SavedPosts\CannotSavePostException;
use App\Exceptions\SavedPosts\SavedPostsDisabledException;
use App\Models\Post;
use App\Support\SavedPosts\SavedPostState;
use App\Support\Settings\ProjectSettingsManager;
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

        if (! app(ProjectSettingsManager::class)->featureEnabled('show_saved_posts')) {
            return;
        }

        $post = Post::find($postId);

        if ($post === null) {
            return;
        }

        $this->saved = app(SavedPostState::class)->forUserAndPost(auth()->user(), $post);
    }

    public function toggle(ToggleSavedPostAction $action): void
    {
        $post = Post::find($this->postId);

        if ($post === null) {
            $this->message = __('saved_posts.post_unavailable');

            return;
        }

        $user = auth()->user();

        if ($user === null) {
            $this->message = __('saved_posts.login_required');

            return;
        }

        try {
            $result = $action->handle($user, $post);
            $this->saved = $result->isSaved;
            $this->message = null;
        } catch (SavedPostsDisabledException) {
            $this->message = __('saved_posts.feature_disabled');
        } catch (CannotSavePostException) {
            $this->message = __('saved_posts.post_unavailable');
        }
    }

    public function getDisplayMessageProperty(): ?string
    {
        return $this->message;
    }

    public function render(): View
    {
        return view('livewire.posts.save-post-button');
    }
}
