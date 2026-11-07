<?php

namespace App\Livewire\Posts;

use App\Actions\Posts\SavePostAction;
use App\Actions\Posts\UnsavePostAction;
use App\Exceptions\SavedPosts\CannotSavePostException;
use App\Exceptions\SavedPosts\SavedPostsDisabledException;
use App\Models\Post;
use App\Support\SavedPosts\SavedPostState;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class SavePostButton extends Component
{
    public int $postId;

    public bool $saved = false;

    public ?string $message = null;

    /**
     * @param  bool|null  $preloadedSaved  the saved state the list already read for the whole page; null reads it here
     */
    public function mount(int $postId, ?bool $preloadedSaved = null): void
    {
        $this->postId = $postId;

        if (! app(ProjectSettingsManager::class)->featureEnabled('show_saved_posts')) {
            return;
        }

        if ($preloadedSaved !== null) {
            $this->saved = $preloadedSaved;

            return;
        }

        $post = Post::find($postId);

        if ($post === null) {
            return;
        }

        $this->saved = app(SavedPostState::class)->forUserAndPost(auth()->user(), $post);
    }

    /**
     * Does what the button says — Save when it shows Save, Unsave when it
     * shows Saved — rather than flipping whatever is stored: another button
     * for the same post may have changed it since this one was drawn, and
     * flipping would undo what the person meant.
     */
    public function toggle(SavePostAction $save, UnsavePostAction $unsave): void
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
            $this->saved ? $unsave->handle($user, $post) : $save->handle($user, $post);
            $this->saved = ! $this->saved;
            $this->message = null;
            $this->dispatch('saved-post-changed', postId: $this->postId, saved: $this->saved);
        } catch (SavedPostsDisabledException) {
            $this->message = __('saved_posts.feature_disabled');
        } catch (CannotSavePostException) {
            $this->message = __('saved_posts.post_unavailable');
        }
    }

    /** Another button for the same post — the card and the open drawer — saved or unsaved it. */
    #[On('saved-post-changed')]
    public function syncSavedState(int $postId, bool $saved): void
    {
        if ($postId === $this->postId) {
            $this->saved = $saved;
            $this->message = null;
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
