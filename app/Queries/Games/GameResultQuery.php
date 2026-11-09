<?php

namespace App\Queries\Games;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameVersion;
use App\Models\MediaAsset;
use App\Support\Games\Snapshot\SnapshotProfile;

/**
 * A shared result: the game, the version it was played in, and the profile
 * it names, as that version froze it. A game still a draft has nothing to
 * share; an archived one still shows what was shared, as over.
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
}
