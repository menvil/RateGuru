<?php

namespace App\Livewire\Feed;

use App\Livewire\Concerns\ShowsAPageThatExists;
use App\Models\User;
use App\Queries\Feed\FeedLanguageOrder;
use App\Queries\Feed\FeedQuery;
use App\Support\Feed\PostCardViewerState;
use App\Support\Languages\FreshWindow;
use App\Support\Rating\RatingConfigurationManager;
use App\Support\Rating\RatingVotingStateLoader;
use App\Support\Settings\ProjectSettingsManager;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class PostFeed extends Component
{
    use ShowsAPageThatExists;
    use WithPagination;

    public ?string $search = null;

    public ?string $tag = null;

    public mixed $category = [];

    public array $ratings = [];

    public string $sort = 'newest';

    public bool $followingOnly = false;

    public ?int $selectedPostId = null;

    /**
     * The viewer's language first, as this feed was opened with it: the language, since when a post counts as fresh
     * and, in Hot, the score a post has to reach — fixed for as long as the feed is looked through, so its pages
     * never shift under the viewer (docs/architecture/language-ranking.md). Null while the feature is off, and in
     * the Following feed and a search, which the viewer narrowed themselves.
     *
     * @var array{language: string, since: string|null, hot_floor: float|null}|null
     */
    #[Locked]
    public ?array $languageOrder = null;

    public function mount(FeedQuery $feedQuery, ProjectSettingsManager $settings): void
    {
        $ranking = $settings->current()->languageRanking();

        if (! $ranking->enabled || $this->followingOnly || trim((string) $this->search) !== '') {
            return;
        }

        $now = CarbonImmutable::now();
        $user = auth()->user();
        $since = $user instanceof User
            ? FreshWindow::forMember($user->previous_visit_at === null ? null : CarbonImmutable::instance($user->previous_visit_at), $ranking, $now)
            : FreshWindow::forGuest($ranking, $now);

        $this->languageOrder = [
            'language' => app()->getLocale(),
            // Top ranks all time: its groups are by language alone.
            'since' => $this->sort === 'top' ? null : $since->toIso8601String(),
            'hot_floor' => $this->sort === 'hot' && $ranking->hotFloorPercentile > 0
                ? $feedQuery->hotScoreAt($ranking->hotFloorPercentile, $now->subDays(2))
                : null,
        ];
    }

    #[On('post-uploaded')]
    public function refreshAfterUpload(): void {}

    #[On('post-moderated')]
    public function refreshAfterPostModerated(): void {}

    #[On('post-deleted')]
    public function refreshAfterPostDeleted(): void {}

    #[On('comment-created')]
    #[On('comment-deleted')]
    public function refreshAfterCommentChange(): void {}

    public function render(
        FeedQuery $feedQuery,
        RatingConfigurationManager $ratingConfiguration,
        RatingVotingStateLoader $ratingVotingStateLoader,
        PostCardViewerState $viewerState,
    ): View {
        $paginator = $this->pageThatExists(fn () => $feedQuery->paginate(
            search: $this->search !== '' ? $this->search : null,
            tag: $this->tag !== '' ? $this->tag : null,
            sort: $this->sort,
            category: $this->category,
            ratingFilters: $this->ratings,
            followedByUserId: $this->followingOnly ? auth()->id() : null,
            languages: $this->languageOrder === null ? null : new FeedLanguageOrder(
                viewerLanguage: $this->languageOrder['language'],
                viewerId: auth()->id(),
                since: $this->languageOrder['since'] === null ? null : CarbonImmutable::parse($this->languageOrder['since']),
                hotFloor: $this->languageOrder['hot_floor'],
            ),
        ))->onEachSide(1);
        $posts = $paginator->getCollection();
        $user = auth()->user();
        $canModerate = Gate::allows('moderate-content');
        $ratingGroups = $ratingConfiguration->activeGroups();

        return view('livewire.feed.post-feed', [
            'posts' => $posts,
            'paginator' => $paginator,
            'selectedPostId' => $this->selectedPostId,
            'ratingGroups' => $ratingGroups,
            'ratingVotingStates' => $ratingVotingStateLoader->forPosts($posts, $user, $ratingGroups),
            'viewerStates' => $viewerState->forPosts($posts, $user),
            'deletePermissions' => $posts
                ->mapWithKeys(fn ($post): array => [(int) $post->id => $user?->can('deleteFromFeed', $post) ?? false])
                ->all(),
            'reportPermissions' => $posts
                ->mapWithKeys(fn ($post): array => [(int) $post->id => $user?->can('report', $post) ?? false])
                ->all(),
            'moderationPermissions' => $posts
                ->mapWithKeys(fn ($post): array => [(int) $post->id => $canModerate])
                ->all(),
        ]);
    }
}
