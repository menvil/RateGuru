<?php

namespace App\Queries\Games;

use App\Enums\GameStatus;
use App\Enums\GameVersionStatus;
use App\Models\GameVersion;
use App\Support\Games\Play\VersionTestLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The version a game page's test link plays (VersionTestLink): one still
 * active of the game the page is for, while the game is live or paused. A
 * draft or archived game, an archived version, one of another game, or a
 * request without a valid link finds none, and the page is the game's
 * ordinary page. Read-only.
 */
final class TestLinkVersionQuery
{
    public function find(Request $request, string $slug): ?GameVersion
    {
        $versionId = VersionTestLink::versionId($request);

        if ($versionId === null) {
            return null;
        }

        return GameVersion::query()
            ->whereKey($versionId)
            ->where('status', GameVersionStatus::Active)
            ->whereHas('game', fn (Builder $game) => $game->where('slug', $slug)->whereIn('status', [GameStatus::Live, GameStatus::Paused]))
            ->first();
    }
}
