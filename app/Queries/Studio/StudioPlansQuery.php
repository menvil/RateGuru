<?php

namespace App\Queries\Studio;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Contracts\Persistence\StablePaginationBoundary;
use App\Enums\StudioPlanStatus;
use App\Models\StudioPlan;
use App\Support\Database\LikePattern;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Content Studio's publication plans, as Publications & plans reads them:
 * the most recently saved first, narrowed by their status and by whether
 * they have a photo, and searched over their title, their id (`#123` or
 * `123`) and their author's username and names. A deleted author is never
 * found by what its account was called.
 */
final class StudioPlansQuery implements RawSqlPersistenceBoundary, StablePaginationBoundary
{
    public const PER_PAGE = 25;

    /**
     * A page of plans, each with its author, the author's avatar and its
     * photo — everything a row draws, loaded once for the page.
     *
     * @return LengthAwarePaginator<int, StudioPlan>
     */
    public function paginate(string $search, ?StudioPlanStatus $status, ?bool $withImage, int $page): LengthAwarePaginator
    {
        return $this->narrowed($search, $status, $withImage)
            ->with(['author.avatarAsset.variants', 'imageAsset.variants'])
            // Plans saved at the same moment keep their place across pages.
            ->orderByDesc('studio_plans.updated_at')
            ->orderByDesc('studio_plans.id')
            ->paginate(self::PER_PAGE, ['studio_plans.*'], 'page', max(1, $page));
    }

    /**
     * The figures the list's header shows: every plan, the drafts, and how
     * many have a photo and an author so far.
     *
     * @return array{total: int, drafts: int, withImage: int, withAuthor: int}
     */
    public function stats(): array
    {
        return [
            'total' => StudioPlan::query()->count(),
            'drafts' => StudioPlan::query()->where('status', StudioPlanStatus::Draft)->count(),
            'withImage' => StudioPlan::query()->whereNotNull('image_asset_id')->count(),
            'withAuthor' => StudioPlan::query()->whereNotNull('author_user_id')->count(),
        ];
    }

    /** @return Builder<StudioPlan> */
    private function narrowed(string $search, ?StudioPlanStatus $status, ?bool $withImage): Builder
    {
        $query = StudioPlan::query();
        $search = trim($search);

        if ($status !== null) {
            $query->where('studio_plans.status', $status);
        }

        if ($withImage !== null) {
            $withImage ? $query->whereNotNull('studio_plans.image_asset_id') : $query->whereNull('studio_plans.image_asset_id');
        }

        if ($search !== '') {
            $id = self::idIn($search);
            $title = LikePattern::containing($search);
            $name = LikePattern::containing(ltrim($search, '@'));

            $query->where(fn (Builder $matching) => $matching
                ->when($id !== null, fn (Builder $byId) => $byId->orWhere('studio_plans.id', $id))
                ->orWhereRaw("LOWER(studio_plans.title) LIKE LOWER(?) ESCAPE '!'", [$title])
                ->orWhereHas('author', fn (Builder $author) => $author
                    ->withoutTombstoned()
                    ->where(fn (Builder $called) => $called
                        ->whereRaw("LOWER(users.username) LIKE LOWER(?) ESCAPE '!'", [$name])
                        ->orWhereRaw("LOWER(users.display_name) LIKE LOWER(?) ESCAPE '!'", [$name])
                        ->orWhereRaw("LOWER(users.name) LIKE LOWER(?) ESCAPE '!'", [$name]))));
        }

        return $query;
    }

    /** `#123` or `123` names a plan; anything else — or a number too long to be an id — names none. */
    private static function idIn(string $search): ?int
    {
        $digits = ltrim($search, '#');

        return preg_match('/\A[0-9]{1,18}\z/', $digits) === 1 ? (int) $digits : null;
    }
}
