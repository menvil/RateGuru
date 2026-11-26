<?php

namespace App\Filament\Resources\Games\Concerns;

use App\Filament\Support\ListPageNumber;
use App\Models\Post;
use App\Queries\Games\GamePostPickerQuery;
use App\Support\Media\PostImagePresenter;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The post picker a game editor opens (the Content tab for a round's
 * option, the Flow tab for the post a step sends visitors to): published
 * posts with a ready picture, the most voted first, searched by title, #id
 * or author, a page at a time — each with its picture, author, votes and
 * share of upvotes.
 */
trait PicksPosts
{
    public string $pickerSearch = '';

    public int $pickerPage = 1;

    public function updatedPickerSearch(): void
    {
        $this->pickerPage = 1;
    }

    public function pickerGoToPage(mixed $page): void
    {
        $this->pickerPage = ListPageNumber::from($page);
    }

    /** The page is the browser's to set as well, so it is held to a page number either way. */
    public function updatedPickerPage(): void
    {
        $this->pickerPage = ListPageNumber::from($this->pickerPage);
    }

    /**
     * A page of the posts to pick from, leaving out those given — only photos
     * for a round's option; each says whether it has the votes a crowd pick
     * needs, when there is a bar.
     *
     * @param  list<int>  $except
     * @return array{paginator: LengthAwarePaginator<int, Post>, posts: list<array<string, mixed>>}
     */
    protected function pickablePosts(array $except = [], ?int $minVotes = null, bool $photosOnly = false): array
    {
        $paginator = app(GamePostPickerQuery::class)->paginate($this->pickerSearch, $except, $this->pickerPage, $photosOnly);
        $presenter = app(PostImagePresenter::class);

        return [
            'paginator' => $paginator,
            'posts' => array_values(array_map(function (Post $post) use ($presenter, $minVotes): array {
                $votes = (int) $post->upvotes_count + (int) $post->downvotes_count;

                return [
                    'id' => (int) $post->id,
                    'title' => (string) $post->title,
                    'author' => $post->user?->username,
                    'thumbnail' => $presenter->assetThumbnailUrl($post->imageAsset),
                    'share' => $votes > 0 ? number_format($post->upvotes_count * 100 / $votes, 1) : null,
                    'votes' => $votes,
                    'enough' => $minVotes === null || $votes >= $minVotes,
                ];
            }, $paginator->items())),
        ];
    }
}
