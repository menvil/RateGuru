<?php

namespace App\Livewire\Feed;

use App\Actions\Posts\DeletePostAction;
use App\Enums\PostStatus;
use App\Exceptions\Posts\CannotDeletePostException;
use App\Models\Post;
use App\Queries\Posts\PublishedPostDetailsQuery;
use App\Support\Rating\RatingConfigurationManager;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class PostDrawer extends Component
{
    public ?int $postId = null;

    public ?string $deleteError = null;

    // Renders its own fixed/animated <aside> chrome (site-wide sliding overlay,
    // mounted once in the layout) instead of just content for a parent-owned wrapper.
    public bool $asOverlay = false;

    // Only meaningful when $asOverlay is true.
    public bool $isOpen = false;

    // Vote events are handled by the nested post-voting / rating-voting
    // components, which self-update in place. The drawer intentionally does
    // not re-render on votes so the card does not reload.

    // Lets a persistent, layout-hosted instance (the global sliding overlay)
    // track the selected post without being re-mounted by a parent component,
    // since it isn't fed :post-id as a reactive prop like the feed's inline drawer.
    #[On('select-post')]
    public function setSelectedPost(int $postId, ?string $focus = null): void
    {
        $this->postId = $postId;
        $this->isOpen = true;

        // Mirrors FeedPage::selectPost's forwarding of the same event/payload for the
        // split-grid path, so the drawer's own scroll-to-target listener (see
        // post-drawer.blade.php) can bring the requested section (e.g. comments) into
        // view once the panel has rendered — same event name/shape, reused rather than
        // inventing a second one.
        $this->dispatch('post-selected', postId: $postId, focus: $focus);
    }

    #[On('clear-selected-post')]
    public function closeOverlay(): void
    {
        $this->isOpen = false;
    }

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

    public function render(
        RatingConfigurationManager $configuration,
        PublishedPostDetailsQuery $publishedPostDetails,
    ): View {
        $post = null;

        if ($this->postId !== null) {
            $post = $publishedPostDetails->find($this->postId);
        }

        return view('livewire.feed.post-drawer', [
            'post' => $post,
            'activeRatingGroups' => $configuration->activeGroups(),
            'canDeletePost' => $post ? (auth()->user()?->can('deleteFromFeed', $post) ?? false) : false,
            'canReportPost' => $post ? (auth()->user()?->can('report', $post) ?? false) : false,
            'canModeratePost' => $post ? (auth()->user()?->can('hide', $post) ?? false) : false,
            'showSharePanel' => $post?->status === PostStatus::Published,
            'canSeeFollowButton' => $post !== null
                && $post->user !== null
                && auth()->check()
                && auth()->id() !== $post->user_id
                && app(ProjectSettingsManager::class)->featureEnabled('show_follow_buttons'),
        ]);
    }
}
