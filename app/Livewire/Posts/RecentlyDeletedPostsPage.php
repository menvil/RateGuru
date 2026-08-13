<?php

namespace App\Livewire\Posts;

use App\Actions\Posts\RestoreDeletedPostAction;
use App\Exceptions\Posts\CannotRestoreDeletedPostException;
use App\Models\Post;
use App\Queries\Posts\RecentlyDeletedPostsQuery;
use App\Support\View\AppLayoutData;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Private self-service surface for the retention window
 * (docs/architecture/post-lifecycle.md): the owner's own author-deleted
 * posts with their restore deadline. Deliberately minimal: no public post
 * links (the posts are publicly gone) and no permanent-delete-now button
 * (hard deletion belongs to the retention purge alone).
 */
final class RecentlyDeletedPostsPage extends Component
{
    use WithPagination;

    public ?string $statusMessage = null;

    public function restore(int $postId, RestoreDeletedPostAction $restoreAction): void
    {
        $post = Post::onlyTrashed()
            ->where('user_id', auth()->id())
            ->find($postId);

        if ($post === null) {
            $this->statusMessage = __('ui.recently_deleted.unavailable');

            return;
        }

        try {
            $restoreAction->handle(auth()->user(), $post);
            // Restoring may empty the current page; land back on a page
            // that still exists.
            $this->resetPage();
            $this->statusMessage = __('ui.recently_deleted.restored', ['title' => $post->title]);
        } catch (CannotRestoreDeletedPostException $e) {
            $this->statusMessage = $e->getMessage();
        }
    }

    public function render(RecentlyDeletedPostsQuery $query): View
    {
        $posts = $query->forOwner(auth()->user());

        return view('livewire.posts.recently-deleted-posts-page', [
            'posts' => $posts,
            'retentionDays' => (int) config('posts.author_delete_retention_days'),
        ])->layout('layouts.app', app(AppLayoutData::class)->toArray());
    }
}
