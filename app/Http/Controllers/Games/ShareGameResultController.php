<?php

namespace App\Http\Controllers\Games;

use App\Actions\Games\RecordGameInteractionAction;
use App\Enums\GameEventType;
use App\Enums\ShareProvider;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameVersion;
use App\Models\MediaAsset;
use App\Queries\Games\GameResultQuery;
use App\Support\Games\Play\GameVisitorResolver;
use App\Support\Games\Snapshot\SnapshotProfile;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Sharing\ShareMetadata;
use App\Support\Sharing\ShareUrlBuilder;
use Illuminate\Http\RedirectResponse;

/**
 * A result shared to a provider through the site's own address — so an ad
 * blocker leaves the link alone — counted on its way, then sent on to the
 * provider's share page with the result's address and text: the profile's
 * page, or the player's own result while it names them.
 */
class ShareGameResultController extends Controller
{
    public function __invoke(string $slug, string $version, string $profile, ShareProvider $provider, GameResultQuery $results, ShareUrlBuilder $urls, ProjectSettingsManager $settings, GameVisitorResolver $visitors, RecordGameInteractionAction $record): RedirectResponse
    {
        $found = $results->find($slug, (int) $version, $profile);
        abort_if($found === null, 404);

        return $this->share($found, route('games.result', ['slug' => $slug, 'version' => (int) $version, 'profile' => $profile]), $provider, $urls, $settings, $visitors, $record);
    }

    public function player(string $slug, string $attempt, ShareProvider $provider, GameResultQuery $results, ShareUrlBuilder $urls, ProjectSettingsManager $settings, GameVisitorResolver $visitors, RecordGameInteractionAction $record): RedirectResponse
    {
        $found = $results->findPlayed($slug, $attempt);
        abort_if($found === null, 404);

        $url = $found['player'] !== null
            ? route('games.player-result', ['slug' => $slug, 'attempt' => $attempt])
            : route('games.result', ['slug' => $slug, 'version' => (int) $found['version']->number, 'profile' => $found['profile']->key]);

        return $this->share($found, $url, $provider, $urls, $settings, $visitors, $record);
    }

    /** @param  array{game: Game, version: GameVersion, profile: SnapshotProfile, image: ?MediaAsset}  $found */
    private function share(array $found, string $url, ShareProvider $provider, ShareUrlBuilder $urls, ProjectSettingsManager $settings, GameVisitorResolver $visitors, RecordGameInteractionAction $record): RedirectResponse
    {
        abort_unless($provider->sharesThroughRedirect() && config("share.providers.{$provider->value}.enabled", true), 404);

        $locale = app()->getLocale();
        $title = $found['version']->read()->title->in($locale);
        $name = $found['profile']->name->in($locale);
        $text = strtr($found['profile']->shareText->in($locale) !== '' ? $found['profile']->shareText->in($locale) : __('games.play.share_default'), ['{profile}' => $name, '{game}' => $title]);

        $record->handle(GameEventType::ShareClicked, $found['game'], $visitors->resolve(), null, (int) $found['version']->id, $provider->value);

        $target = $urls->build($provider, new ShareMetadata(title: $title, shareText: $text, description: $text, url: $url, imageUrl: null, siteName: $settings->current()->siteName()));
        abort_if($target === null, 404);

        return redirect()->away($target);
    }
}
