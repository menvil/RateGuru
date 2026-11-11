<?php

namespace App\Actions\Games;

use App\Actions\Games\Concerns\AppliesTrafficShares;
use App\Actions\Games\Concerns\LocksGameDraft;
use App\Enums\GameAudience;
use App\Enums\GameVersionStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\Game;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\GameVersionPost;
use App\Models\User;
use App\Support\Games\Drafts\ContentSetReport;
use App\Support\Games\Publishing\GamePublishReadiness;
use App\Support\Games\Publishing\VersionSnapshotBuilder;
use App\Support\Games\Snapshot\GameVersionSnapshot;
use App\Support\Observability\DomainLogger;
use Illuminate\Support\Facades\DB;

/**
 * Publishes a content set played through a flow as the game's next version:
 * frozen whole into its snapshot — the crowd's votes as they stand now, every
 * text with its translations, the profiles and the flow — numbered after the
 * last version ever published, never reusing a number.
 *
 * The game, the set and the flow are locked, in that order, so a save of
 * either cannot land halfway through the reading. Publishing is refused unless
 * the publish report finds everything ready. A new version gets no traffic,
 * unless it is asked to take over another version's: then, in each audience,
 * the other version's share becomes the new version's.
 */
final class PublishGameVersionAction
{
    use AppliesTrafficShares;
    use LocksGameDraft;

    public function __construct(private readonly DomainLogger $logger) {}

    /** @throws CannotManageGameException */
    public function handle(User $admin, Game $game, int $contentSetId, int $flowId, ?int $takeOverFromVersionId = null): GameVersion
    {
        if (! $admin->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }

        $version = DB::transaction(function () use ($admin, $game, $contentSetId, $flowId, $takeOverFromVersionId): GameVersion {
            $lockedAdmin = $this->lockAdministrator($admin);
            $locked = $this->lockGame((int) $game->id);
            $set = $this->lockContentSet($locked, $contentSetId);
            $flow = $this->lockFlow($locked, $flowId);

            $set->load(['rounds.options.post.imageAsset.variants', 'rounds.options.asset.variants', 'profiles']);
            $flow->load('steps');

            $report = ContentSetReport::of($set);
            $readiness = GamePublishReadiness::of($locked, $report, $flow, Game::query()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

            if (! $readiness->isReady()) {
                throw CannotManageGameException::because('This version can’t be published yet: '.$readiness->problems()[0]);
            }

            $snapshot = VersionSnapshotBuilder::build($locked, $report, $flow);
            $number = $locked->last_version_number + 1;

            $version = new GameVersion;
            $version->forceFill([
                'game_id' => $locked->id,
                'number' => $number,
                'status' => GameVersionStatus::Active,
                'content_set_id' => $set->id,
                'flow_id' => $flow->id,
                'snapshot' => $snapshot->toArray(),
                'snapshot_schema' => GameVersionSnapshot::SCHEMA,
                'round_count' => $snapshot->roundCount(),
                'scored_round_count' => $snapshot->scoredRoundCount(),
                'max_score' => $snapshot->maxScore(),
                'published_by_user_id' => $lockedAdmin->id,
                'published_at' => now(),
            ])->save();

            $version->assets()->sync($snapshot->uploadedAssetIds());

            foreach ($snapshot->postIds() as $postId) {
                GameVersionPost::query()->create(['version_id' => $version->id, 'post_id' => $postId]);
            }

            $locked->forceFill([
                'last_version_number' => $number,
                'first_published_at' => $locked->first_published_at ?? now(),
            ]);
            $this->touchGame($locked, $lockedAdmin);

            if ($takeOverFromVersionId !== null) {
                $this->takeOver($locked, $takeOverFromVersionId, (int) $version->id, $lockedAdmin);
            }

            $game->setRawAttributes($locked->getAttributes(), true);

            return $version;
        });

        $this->logger->info('game.version.published', ['game_id' => $game->id, 'version_id' => $version->id, 'number' => $version->number, 'admin_id' => $admin->id]);

        return $version;
    }

    /** In each audience, the share the old version has becomes the new version's. */
    private function takeOver(Game $game, int $fromVersionId, int $toVersionId, User $admin): void
    {
        foreach (GameAudience::cases() as $audience) {
            $current = GameTrafficPeriod::query()->where('game_id', $game->id)->where('audience', $audience)->whereNull('ended_at')->with('versions')->latest('id')->first();
            $shares = $current?->shares() ?? [];

            if (! isset($shares[$fromVersionId])) {
                continue;
            }

            $shares[$toVersionId] = ($shares[$toVersionId] ?? 0) + $shares[$fromVersionId];
            unset($shares[$fromVersionId]);
            $this->applyShares($game, $audience, $shares, $admin);
        }
    }
}
