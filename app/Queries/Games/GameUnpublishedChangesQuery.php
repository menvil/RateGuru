<?php

namespace App\Queries\Games;

use App\Data\Games\UnpublishedChanges;
use App\Models\Game;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Models\GameResultProfile;
use App\Models\GameRound;
use App\Models\GameRoundOption;
use App\Models\GameVersion;
use App\Support\Games\Snapshot\LocalizedText;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Whether a game's players play what is saved in its draft. They play the
 * versions in traffic, each frozen when it was published; a content set or a
 * flow one of them was made from, saved after it, or the game's own title,
 * description or cover changed since, is something they don't see until a
 * new version is published. Saved means saved: a translation made in
 * Translation Center counts, as it is saved on the row it translates.
 */
final class GameUnpublishedChangesQuery
{
    public function __construct(private readonly GameTrafficQuery $traffic) {}

    /** A content set saved after the newest version in traffic made from it. */
    public function forSet(Game $game, int $setId): ?UnpublishedChanges
    {
        $versions = $this->inTraffic($game)->filter(fn (GameVersion $version): bool => (int) $version->content_set_id === $setId)->values();
        $newest = $versions->last();

        return $newest !== null && $this->setEditedAfter($setId, $newest->published_at)
            ? $this->changes($versions, $setId, (int) $newest->flow_id, $newest)
            : null;
    }

    /** A flow saved after the newest version in traffic made from it. */
    public function forFlow(Game $game, int $flowId): ?UnpublishedChanges
    {
        $versions = $this->inTraffic($game)->filter(fn (GameVersion $version): bool => (int) $version->flow_id === $flowId)->values();
        $newest = $versions->last();

        return $newest !== null && $this->flowEditedAfter($flowId, $newest->published_at)
            ? $this->changes($versions, (int) $newest->content_set_id, $flowId, $newest)
            : null;
    }

    /** The game's title, description or cover, other than the versions in traffic froze them. */
    public function forGame(Game $game): ?UnpublishedChanges
    {
        $versions = $this->inTraffic($game);
        $title = $this->sorted(LocalizedText::fromColumns($game->title, $game->title_translations)->toArray());
        $description = $this->sorted(LocalizedText::fromColumns($game->description, $game->description_translations)->toArray());
        $behind = $versions->filter(function (GameVersion $version) use ($game, $title, $description): bool {
            $snapshot = $version->read();

            return $this->sorted($snapshot->title->toArray()) !== $title
                || $this->sorted($snapshot->description->toArray()) !== $description
                || $snapshot->coverAssetId !== ($game->cover_asset_id !== null ? (int) $game->cover_asset_id : null);
        })->values();
        $newest = $versions->last();

        return $newest !== null && $behind->isNotEmpty()
            ? $this->changes($behind, (int) $newest->content_set_id, (int) $newest->flow_id, $newest)
            : null;
    }

    /** Whether the set, a round, an option or a result profile of it was saved after a moment. */
    public function setEditedAfter(int $setId, CarbonInterface $moment): bool
    {
        $rounds = GameRound::query()->select('id')->where('content_set_id', $setId);

        return $this->after($moment, [
            GameContentSet::query()->whereKey($setId)->value('updated_at'),
            GameRound::query()->where('content_set_id', $setId)->max('updated_at'),
            GameRoundOption::query()->whereIn('round_id', $rounds)->max('updated_at'),
            GameResultProfile::query()->where('content_set_id', $setId)->max('updated_at'),
        ]);
    }

    /** Whether the flow or a step of it was saved after a moment. */
    public function flowEditedAfter(int $flowId, CarbonInterface $moment): bool
    {
        return $this->after($moment, [
            GameFlow::query()->whereKey($flowId)->value('updated_at'),
            GameFlowStep::query()->where('flow_id', $flowId)->max('updated_at'),
        ]);
    }

    /**
     * The game's versions in traffic now, oldest first.
     *
     * @return Collection<int, GameVersion>
     */
    private function inTraffic(Game $game): Collection
    {
        $ids = $this->traffic->versionsInTraffic([(int) $game->id])[(int) $game->id] ?? [];

        return $ids === [] ? collect() : GameVersion::query()->whereIn('id', $ids)->orderBy('number')->get();
    }

    /** @param  Collection<int, GameVersion>  $versions */
    private function changes(Collection $versions, int $setId, int $flowId, GameVersion $newest): UnpublishedChanges
    {
        return new UnpublishedChanges($versions->map(fn (GameVersion $version): string => $version->label())->values()->all(), $setId, $flowId, (int) $newest->id);
    }

    /** @param  list<mixed>  $saved */
    private function after(CarbonInterface $moment, array $saved): bool
    {
        foreach ($saved as $at) {
            if ($at !== null && CarbonImmutable::parse($at)->greaterThan($moment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }
}
