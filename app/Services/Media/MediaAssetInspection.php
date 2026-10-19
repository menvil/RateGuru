<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Post;
use App\Models\StudioPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything MediaDiagnosticsPage's asset inspector modal shows for one
 * MediaAsset — assembled by MediaAssetInspector, never by Filament itself.
 */
final readonly class MediaAssetInspection
{
    /**
     * @param  Collection<int, array{variant: MediaVariant, exists: bool}>  $variants
     * @param  Collection<int, Post>  $referencingPosts
     * @param  Collection<int, User>  $referencingUsers
     * @param  Collection<int, StudioPlan>  $referencingPlans
     */
    public function __construct(
        public MediaAsset $asset,
        public bool $masterExists,
        public bool $referenced,
        public Collection $variants,
        public Collection $referencingPosts,
        public Collection $referencingUsers,
        public Collection $referencingPlans,
        public bool $graceExpired,
        public bool $purgeable,
        public ?Carbon $purgeableAt,
    ) {}
}
