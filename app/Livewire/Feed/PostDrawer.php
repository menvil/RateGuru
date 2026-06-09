<?php

namespace App\Livewire\Feed;

use App\Actions\Posts\DeletePostAction;
use App\Enums\PostStatus;
use App\Exceptions\Posts\CannotDeletePostException;
use App\Models\Post;
use App\Support\Rating\RatingConfigurationManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class PostDrawer extends Component
{
    public ?int $postId = null;

    public ?string $deleteError = null;

    // Vote events are handled by the nested post-voting / rating-voting
    // components, which self-update in place. The drawer intentionally does
    // not re-render on votes so the card does not reload.

    public function deleteSelectedPost(DeletePostAction $deletePostAction): void
    {
        $this->deleteError = null;

        if (! auth()->check() || $this->postId === null) {
            return;
        }

        $post = Post::query()->find($this->postId);

        if ($post === null) {
            $this->deleteError = null;
            $this->dispatch('clear-selected-post');

            return;
        }

        try {
            $deletePostAction->handle(auth()->user(), $post);
        } catch (CannotDeletePostException $e) {
            $this->deleteError = $e->getMessage();

            return;
        }

        $this->postId = null;
        $this->deleteError = null;
        $this->dispatch('clear-selected-post');
    }

    public function render(RatingConfigurationManager $configuration): View
    {
        $post = null;

        if ($this->postId !== null) {
            $post = Post::query()
                ->published()
                ->with(['user', 'tags'])
                ->find($this->postId);
        }

        return view('livewire.feed.post-drawer', [
            'post' => $post,
            'activeRatingGroups' => $configuration->activeGroups(),
            'canDeletePost' => $post ? (auth()->user()?->can('deleteFromFeed', $post) ?? false) : false,
            'canReportPost' => $post ? (auth()->user()?->can('report', $post) ?? false) : false,
            'canModeratePost' => $post ? (auth()->user()?->can('hide', $post) ?? false) : false,
            'showSharePanel' => $post?->status === PostStatus::Published,
        ]);
    }
}
