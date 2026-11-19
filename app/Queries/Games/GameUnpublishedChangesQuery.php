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
use App\Support\Games\Drafts\ContentSetReport;
use App\Support\Games\Publishing\VersionChanges;
use App\Support\Games\Publishing\VersionSnapshotBuilder;
use App\Support\Games\Snapshot\GameVersionSnapshot;
use App\Support\Games\Snapshot\LocalizedText;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Whether a game's players play what is saved in its draft, and if not, what
 * they miss. They play the versions in traffic, each frozen when it was
 * published; a new version would freeze the draft as it stands.
 *
 * Decided by content: the draft is frozen the way publishing freezes it
 * (VersionSnapshotBuilder), stored nowhere, and compared with the version's
 * snapshot (VersionChanges) — so a save that changed nothing players meet is
 * no change, whatever it did to save times. Save times are only the gate:
 * while nothing of the set or the flow was saved at or after the version —
 * at: a save in the second it was published may come after it — nothing is
 * frozen or compared; the game's own title, description and cover are
 * compared as they stand, which needs no freezing. Saved means saved: a translation made in
 * Translation Center counts, as it is saved on the row it translates.
 */
final class GameUnpublishedChangesQuery
{
    public function __construct(
        private readonly GameTrafficQuery $traffic,
        private readonly GameDraftQuery $draftQuery,
    ) {}

    /**
     * A content set saved after the newest version in traffic made from it,
     * other than it froze it — named with the flow's changes too, as the
     * version Publish makes freezes both.
     */
    public function forSet(Game $game, int $setId): ?UnpublishedChanges
    {
        $versions = $this->inTraffic($game)->filter(fn (GameVersion $version): bool => (int) $version->content_set_id === $setId)->values();
        $newest = $versions->last();

        return $newest === null ? null : $this->pair($game, $versions, $setId, (int) $newest->flow_id, own: 'set');
    }

    /**
     * A flow saved after the newest version in traffic made from it, other
     * than it froze it — named with the set's changes too, as the version
     * Publish makes freezes both.
     */
    public function forFlow(Game $game, int $flowId): ?UnpublishedChanges
    {
        $versions = $this->inTraffic($game)->filter(fn (GameVersion $version): bool => (int) $version->flow_id === $flowId)->values();
        $newest = $versions->last();

        return $newest === null ? null : $this->pair($game, $versions, (int) $newest->content_set_id, $flowId, own: 'flow');
    }

    /**
     * The game's title, description or cover, other than the versions in
     * traffic froze them: any of them, not only the newest, as each plays
     * its own. A new version takes over the newest one's traffic.
     */
    public function forGame(Game $game): ?UnpublishedChanges
    {
        $versions = $this->inTraffic($game);
        $newest = $versions->last();

        if ($newest === null) {
            return null;
        }

        // Three of the game's own columns against each snapshot: cheap enough to need no gate, and none can hide a change.
        $title = LocalizedText::fromColumns($game->title, $game->title_translations);
        $description = LocalizedText::fromColumns($game->description, $game->description_translations);
        $cover = $game->cover_asset_id !== null ? (int) $game->cover_asset_id : null;
        $diff = fn (GameVersion $version): array => VersionChanges::gameAgainst($version->read(), $title, $description, $cover);
        $behind = $versions->filter(fn (GameVersion $version): bool => $diff($version) !== [])->values();
        $since = $behind->last();

        return $since === null ? null : new UnpublishedChanges(
            $behind->map(fn (GameVersion $version): string => $version->label())->values()->all(),
            (int) $newest->content_set_id,
            (int) $newest->flow_id,
            (int) $newest->id,
            $since->label(),
            [['title' => 'Game', 'changes' => $diff($since)]],
        );
    }

    /**
     * What a version published now from this set and flow would change
     * against the newest of the versions: the set's changes and the flow's,
     * since Publish freezes both. The versions missing them are those whose
     * own part — the tab's set or flow — differs from the draft.
     *
     * @param  Collection<int, GameVersion>  $versions  oldest first, made from the tab's set or flow
     * @param  'set'|'flow'  $own
     */
    private function pair(Game $game, Collection $versions, int $setId, int $flowId, string $own): ?UnpublishedChanges
    {
        $newest = $versions->last();

        if ($newest === null || (! $this->setEditedAfter($setId, $newest->published_at) && ! $this->flowEditedAfter($flowId, $newest->published_at))) {
            return null;
        }

        $draft = $this->draft($game, $setId, $flowId);

        if ($draft === null) {
            return null;
        }

        $groups = fn (GameVersion $version): array => array_values(array_filter([
            ['title' => 'Content set “'.$draft['set'].'”', 'changes' => VersionChanges::content($version->read(), $draft['snapshot'])],
            ['title' => 'Flow “'.$draft['flow'].'”', 'changes' => VersionChanges::flow($version->read(), $draft['snapshot'], $version->flowGoal())],
        ], fn (array $group): bool => $group['changes'] !== []));
        $listed = $groups($newest);

        if ($listed === []) {
            return null;
        }

        $ownDiff = fn (GameVersion $version): array => $own === 'set'
            ? VersionChanges::content($version->read(), $draft['snapshot'])
            : VersionChanges::flow($version->read(), $draft['snapshot'], $version->flowGoal());
        $behind = $versions->filter(fn (GameVersion $version): bool => $version->is($newest) || $ownDiff($version) !== [])->values();

        return new UnpublishedChanges(
            $behind->map(fn (GameVersion $version): string => $version->label())->values()->all(),
            $setId,
            $flowId,
            (int) $newest->id,
            $newest->label(),
            $listed,
        );
    }

    /**
     * What publishing this set and flow now would change against a version —
     * the newest made from them, on Versions — or null while it would change
     * nothing players meet. The game's own texts are left out: every new
     * version takes them, from whichever set and flow.
     */
    public function sinceVersion(Game $game, GameVersion $version, int $setId, int $flowId): ?UnpublishedChanges
    {
        if (! $this->setEditedAfter($setId, $version->published_at) && ! $this->flowEditedAfter($flowId, $version->published_at)) {
            return null;
        }

        $draft = $this->draft($game, $setId, $flowId);

        if ($draft === null) {
            return null;
        }

        $groups = array_values(array_filter([
            ['title' => 'Content set “'.$draft['set'].'”', 'changes' => VersionChanges::content($version->read(), $draft['snapshot'])],
            ['title' => 'Flow “'.$draft['flow'].'”', 'changes' => VersionChanges::flow($version->read(), $draft['snapshot'], $version->flowGoal())],
        ], fn (array $group): bool => $group['changes'] !== []));

        return $groups === [] ? null : new UnpublishedChanges([$version->label()], $setId, $flowId, (int) $version->id, $version->label(), $groups);
    }

    /** Whether the set, a round, an option or a result profile of it was saved at or after a moment. */
    private function setEditedAfter(int $setId, CarbonInterface $moment): bool
    {
        $rounds = GameRound::query()->select('id')->where('content_set_id', $setId);

        return $this->after($moment, [
            GameContentSet::query()->whereKey($setId)->value('updated_at'),
            GameRound::query()->where('content_set_id', $setId)->max('updated_at'),
            GameRoundOption::query()->whereIn('round_id', $rounds)->max('updated_at'),
            GameResultProfile::query()->where('content_set_id', $setId)->max('updated_at'),
        ]);
    }

    /** Whether the flow or a step of it was saved at or after a moment. */
    private function flowEditedAfter(int $flowId, CarbonInterface $moment): bool
    {
        return $this->after($moment, [
            GameFlow::query()->whereKey($flowId)->value('updated_at'),
            GameFlowStep::query()->where('flow_id', $flowId)->max('updated_at'),
        ]);
    }

    /**
     * What a version published now from this set and flow would freeze —
     * built as PublishGameVersionAction builds it, stored nowhere — with the
     * set's and the flow's names; null once either is gone.
     *
     * @return array{snapshot: GameVersionSnapshot, set: string, flow: string}|null
     */
    private function draft(Game $game, int $setId, int $flowId): ?array
    {
        $set = $this->draftQuery->contentSet((int) $game->id, $setId);
        $flow = $this->draftQuery->flow((int) $game->id, $flowId);

        return $set === null || $flow === null ? null : [
            'snapshot' => VersionSnapshotBuilder::buildForPreview($game, ContentSetReport::of($set), $flow),
            'set' => $set->name,
            'flow' => $flow->name,
        ];
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

    /**
     * Whether any of the save times is at or after the moment: save times
     * keep whole seconds, so one equal to it may be a save made after it.
     *
     * @param  list<mixed>  $saved
     */
    private function after(CarbonInterface $moment, array $saved): bool
    {
        foreach ($saved as $at) {
            if ($at !== null && ($at instanceof CarbonInterface ? $at : CarbonImmutable::parse($at))->greaterThanOrEqualTo($moment)) {
                return true;
            }
        }

        return false;
    }
}
