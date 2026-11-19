<?php

namespace App\Queries\Games;

use App\Models\Post;
use App\Models\Tag;
use App\Queries\Categories\ActiveCategorySlugsQuery;
use App\Support\Games\Flow\FlowFacts;
use App\Support\Games\Flow\FlowStepDraft;
use App\Support\Games\Flow\InternalPath;
use App\Support\Rating\RatingConfigurationManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * What the flow rules need to know of the site about a flow's steps: the
 * posts they link to, the rating groups, categories and tags that are active,
 * and whether the page addresses they name are pages of this site.
 */
final class GameFlowFactsQuery
{
    public function __construct(
        private readonly RatingConfigurationManager $ratings,
        private readonly ActiveCategorySlugsQuery $categories,
    ) {}

    /** @param  list<FlowStepDraft>  $steps */
    public function for(array $steps): FlowFacts
    {
        $postIds = [];
        $paths = [];

        foreach ($steps as $step) {
            $postId = $step->setting('post_id');

            if (is_int($postId)) {
                $postIds[] = $postId;
            }

            $path = (string) $step->setting('path');

            if ($path !== '') {
                $paths[$path] = $this->isPage($path);
            }
        }

        $posts = [];

        if ($postIds !== []) {
            $postIds = array_values(array_unique($postIds));
            // Which of them are published, read at once; a deleted one never is.
            $published = Post::query()->published()->whereIn('id', $postIds)->pluck('id')->map(fn (mixed $id): int => (int) $id)->flip()->all();
            $posts = Post::withTrashed()->whereIn('id', $postIds)->get(['id', 'title', 'deleted_at'])
                ->mapWithKeys(fn (Post $post): array => [(int) $post->id => ['title' => (string) $post->title, 'published' => ! $post->trashed() && isset($published[(int) $post->id])]])
                ->all();
        }

        return new FlowFacts(
            posts: $posts,
            ratingGroups: $this->ratings->activeGroups()->pluck('key')->map(fn ($key): string => (string) $key)->values()->all(),
            categories: $this->categories->get(),
            tags: Tag::query()->pluck('slug')->map(fn ($slug): string => (string) $slug)->values()->all(),
            paths: $paths,
        );
    }

    /** Whether a local path is one of the site's pages a flow may send a visitor to. */
    public function isPage(string $path): bool
    {
        if (! InternalPath::isLocal($path)) {
            return false;
        }

        try {
            return InternalPath::allows(Route::getRoutes()->match(Request::create((string) parse_url($path, PHP_URL_PATH)))->getName());
        } catch (Throwable) {
            return false;
        }
    }
}
