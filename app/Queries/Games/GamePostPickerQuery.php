<?php

namespace App\Queries\Games;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Contracts\Persistence\StablePaginationBoundary;
use App\Enums\MediaStatus;
use App\Models\Post;
use App\Support\Database\LikePattern;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The posts a round may show: published, not deleted, with a stored, ready
 * picture — what the site already shows its visitors. The most voted first,
 * since a crowd round needs votes; searched literally over the title, the id
 * (`#123` or `123`) and the author's username.
 */
final class GamePostPickerQuery implements RawSqlPersistenceBoundary, StablePaginationBoundary
{
    public const PER_PAGE = 25;

    /**
     * @param  list<int>  $except  posts not to offer, such as the round's other option
     * @return LengthAwarePaginator<int, Post>
     */
    public function paginate(string $search, array $except, int $page): LengthAwarePaginator
    {
        $query = Post::query()
            ->published()
            ->whereNotNull('posts.image_asset_id')
            ->whereHas('imageAsset', fn (Builder $asset) => $asset->where('status', MediaStatus::Ready))
            ->when($except !== [], fn (Builder $query) => $query->whereNotIn('posts.id', $except))
            ->with(['user', 'imageAsset.variants']);

        $search = trim($search);

        if ($search !== '') {
            $id = self::idIn($search);
            $title = LikePattern::containing($search);
            $name = LikePattern::containing(ltrim($search, '@'));

            $query->where(fn (Builder $matching) => $matching
                ->when($id !== null, fn (Builder $byId) => $byId->orWhere('posts.id', $id))
                ->orWhereRaw("LOWER(posts.title) LIKE LOWER(?) ESCAPE '!'", [$title])
                ->orWhereHas('user', fn (Builder $author) => $author->withoutTombstoned()
                    ->whereRaw("LOWER(users.username) LIKE LOWER(?) ESCAPE '!'", [$name])));
        }

        return $query
            ->orderByDesc('posts.upvotes_count')
            ->orderByDesc('posts.id')
            ->paginate(self::PER_PAGE, ['posts.*'], 'page', max(1, $page));
    }

    private static function idIn(string $search): ?int
    {
        $digits = ltrim($search, '#');

        return preg_match('/\A[0-9]{1,18}\z/', $digits) === 1 ? (int) $digits : null;
    }
}
