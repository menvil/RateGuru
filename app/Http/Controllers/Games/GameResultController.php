<?php

namespace App\Http\Controllers\Games;

use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameVersion;
use App\Models\MediaAsset;
use App\Queries\Games\GameResultQuery;
use App\Support\Games\Snapshot\SnapshotProfile;
use App\Support\Media\PostImagePresenter;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Urls\DisplayHost;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The page a shared result opens: the profile, as the version it was played
 * in froze it, and the game to play. Its preview on other sites shows the
 * profile's share image, or the game's cover — pictures an administrator
 * chose, never a post's. A player's own result names them on the card; once
 * their account is no longer in good standing, its address opens the
 * profile's page instead.
 */
class GameResultController extends Controller
{
    public function __invoke(string $slug, string $version, string $profile, GameResultQuery $results, PostImagePresenter $presenter, ProjectSettingsManager $settings): View
    {
        $found = $results->find($slug, (int) $version, $profile);
        abort_if($found === null, 404);

        return $this->show($found, null, $presenter, $settings);
    }

    public function player(string $slug, string $attempt, GameResultQuery $results, PostImagePresenter $presenter, ProjectSettingsManager $settings): View|RedirectResponse
    {
        $found = $results->findPlayed($slug, $attempt);
        abort_if($found === null, 404);

        if ($found['player'] === null) {
            return redirect()->route('games.result', ['slug' => $slug, 'version' => (int) $found['version']->number, 'profile' => $found['profile']->key]);
        }

        return $this->show($found, $found['player'], $presenter, $settings);
    }

    /** @param  array{game: Game, version: GameVersion, profile: SnapshotProfile, image: ?MediaAsset}  $found */
    private function show(array $found, ?string $player, PostImagePresenter $presenter, ProjectSettingsManager $settings): View
    {
        $locale = app()->getLocale();
        $snapshot = $found['version']->read();
        $result = $found['profile'];
        $image = $found['image'];
        $title = $snapshot->title->in($locale);

        return view('games.result', [
            'gameTitle' => $title,
            'profile' => [
                'name' => $result->name->in($locale),
                'description' => $result->description->in($locale),
                'icon' => $result->icon,
            ],
            'image' => $presenter->assetPreviewUrl($image),
            'ogImage' => $presenter->openGraphAsset($image),
            'shareTitle' => __('games.result.og_title', ['profile' => $result->name->in($locale), 'game' => $title]),
            'playUrl' => route('games.play', ['slug' => $found['game']->slug]),
            'over' => $found['game']->status === GameStatus::Archived,
            'site' => $settings->current()->siteName(),
            // The card names the player whose result it is, or the site.
            'byline' => $player ?? DisplayHost::of(url()->current()),
            'url' => url()->current(),
        ]);
    }
}
