<?php

namespace App\Http\Controllers\Games;

use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Queries\Games\GameResultQuery;
use App\Support\Media\PostImagePresenter;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Contracts\View\View;

/**
 * The page a shared result opens: the profile, as the version it was played
 * in froze it, and the game to play. Its preview on other sites shows the
 * profile's share image, or the game's cover — pictures an administrator
 * chose, never a post's.
 */
class GameResultController extends Controller
{
    public function __invoke(string $slug, string $version, string $profile, GameResultQuery $results, PostImagePresenter $presenter, ProjectSettingsManager $settings): View
    {
        $found = $results->find($slug, (int) $version, $profile);
        abort_if($found === null, 404);

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
            'url' => url()->current(),
        ]);
    }
}
