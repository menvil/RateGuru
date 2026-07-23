<?php

namespace App\Livewire\Posts;

use App\Models\Post;
use App\Queries\Posts\PublishedPostDetailsQuery;
use App\Services\Feed\FeedPostDeletionService;
use App\Support\Rating\RatingConfigurationManager;
use App\Support\Seo\PostOpenGraph;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\View\AppLayoutData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/** @property-read Post $post */
final class PostShow extends Component
{
    private PublishedPostDetailsQuery $publishedPostDetails;

    public int $postId;

    public ?string $deleteError = null;

    public function boot(PublishedPostDetailsQuery $publishedPostDetails): void
    {
        $this->publishedPostDetails = $publishedPostDetails;
    }

    public function mount(Post $post): void
    {
        $this->postId = $post->id;
    }

    public function getPostProperty(): Post
    {
        return $this->publishedPostDetails->findOrFail($this->postId);
    }

    #[On('post-voted')]
    public function refreshAfterVote(): void
    {
        // Triggers a re-render so the score summary panel reflects fresh counters.
        // Rating vote results update in place via the nested rating-voting
        // components, so no page re-render is needed for them.
    }

    public function deletePost(FeedPostDeletionService $feedPostDeletionService): void
    {
        $this->deleteError = null;

        $result = $feedPostDeletionService->deleteForUser(auth()->user(), $this->postId);

        if ($result->error !== null) {
            $this->deleteError = $result->error;

            return;
        }

        if (! $result->deleted) {
            return;
        }

        $this->redirect(route('feed'), navigate: true);
    }

    public function getCanSeeFollowButtonProperty(): bool
    {
        $post = $this->post;

        return auth()->check()
            && $post->user !== null
            && auth()->id() !== $post->user_id
            && app(ProjectSettingsManager::class)->featureEnabled('show_follow_buttons');
    }

    public function render(RatingConfigurationManager $configuration, ProjectSettingsManager $projectSettings): View
    {
        $post = $this->post;
        $openGraph = app(PostOpenGraph::class);
        $user = auth()->user();

        return view('livewire.posts.post-show', [
            'ogDescription' => $openGraph->description($post),
            'ogImage' => $openGraph->image($post),
            'ogTitle' => $openGraph->title($post),
            'ogHasImage' => trim((string) $post->public_image_url) !== '',
            'post' => $post,
            'activeRatingGroups' => $configuration->activeGroups(),
            'projectSettings' => $projectSettings,
            'canReportPost' => $user?->can('report', $post) ?? false,
            'canDeletePost' => $user?->can('deleteFromFeed', $post) ?? false,
            'canModeratePost' => $user?->can('hide', $post) ?? false,
            'deleteError' => $this->deleteError,
        ])->layout('layouts.app', app(AppLayoutData::class)->toArray());
    }
}
