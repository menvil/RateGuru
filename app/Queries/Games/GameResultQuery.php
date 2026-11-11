<?php

namespace App\Queries\Games;

use App\Enums\GameAttemptStatus;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameVersion;
use App\Models\MediaAsset;
use App\Support\Games\Play\PlayerHandle;
use App\Support\Games\Snapshot\SnapshotProfile;
use Illuminate\Database\Eloquent\Builder;

/**
 * A shared result: the game, the version it was played in, and the profile
 * it names, as that version froze it. A game still a draft has nothing to
 * share; an archived one still shows what was shared, as over. A player's
 * own result is found by their completed attempt, and names them while their
 * account is in good standing.
 */
final class GameResultQuery
{
    /**
     * The result, with the picture its preview shows — the profile's share
     * image, or the game's cover — and that picture's sizes.
     *
     * @return array{game: Game, version: GameVersion, profile: SnapshotProfile, image: ?MediaAsset}|null
     */
    public function find(string $slug, int $versionNumber, string $profileKey): ?array
    {
        $game = Game::query()->where('slug', $slug)->where('status', '!=', GameStatus::Draft)->first();
        $version = $game?->versions()->where('number', $versionNumber)->first();
        $profile = $version?->read()->profile($profileKey);

        if ($game === null || $version === null || $profile === null) {
            return null;
        }

        $imageId = $profile->shareAssetId ?? $version->read()->coverAssetId;

        return [
            'game' => $game,
            'version' => $version,
            'profile' => $profile,
            'image' => $imageId !== null ? MediaAsset::query()->with('variants')->find($imageId) : null,
        ];
    }

    /**
     * A player's own result, by the public id of the completed attempt that
     * earned it: the result as find() reads it, the attempt, and the @handle
     * of its player — null once the account is no longer in good standing,
     * or for a guest's attempt nobody claimed.
     *
     * @return array{game: Game, version: GameVersion, profile: SnapshotProfile, image: ?MediaAsset, attempt: GameAttempt, player: ?string}|null
     */
    public function findPlayed(string $slug, string $attemptId): ?array
    {
        $attempt = GameAttempt::query()
            ->where('public_id', $attemptId)
            ->where('status', GameAttemptStatus::Completed)
            ->whereNotNull('profile_key')
            ->whereHas('game', fn (Builder $game) => $game->where('slug', $slug))
            ->with(['version', 'user'])
            ->first();

        if ($attempt === null) {
            return null;
        }

        $found = $this->find($slug, (int) $attempt->version->number, (string) $attempt->profile_key);

        return $found === null ? null : [...$found, 'attempt' => $attempt, 'player' => PlayerHandle::of($attempt->user)];
    }
}
