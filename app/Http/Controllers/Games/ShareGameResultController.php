<?php

namespace App\Http\Controllers\Games;

use App\Actions\Games\RecordGameInteractionAction;
use App\Enums\GameEventType;
use App\Enums\ShareProvider;
use App\Http\Controllers\Controller;
use App\Queries\Games\GameResultQuery;
use App\Support\Games\Play\GameVisitorResolver;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Sharing\ShareMetadata;
use App\Support\Sharing\ShareUrlBuilder;
use Illuminate\Http\RedirectResponse;

/**
 * A result shared to a provider through the site's own address — so an ad
 * blocker leaves the link alone — counted on its way, then sent on to the
 * provider's share page with the result's address and text.
 */
class ShareGameResultController extends Controller
{
    public function __invoke(string $slug, string $version, string $profile, ShareProvider $provider, GameResultQuery $results, ShareUrlBuilder $urls, ProjectSettingsManager $settings, GameVisitorResolver $visitors, RecordGameInteractionAction $record): RedirectResponse
    {
        abort_unless($provider->sharesThroughRedirect() && config("share.providers.{$provider->value}.enabled", true), 404);

        $found = $results->find($slug, (int) $version, $profile);
        abort_if($found === null, 404);

        $locale = app()->getLocale();
        $snapshot = $found['version']->read();
        $title = $snapshot->title->in($locale);
        $name = $found['profile']->name->in($locale);
        $text = strtr($found['profile']->shareText->in($locale) !== '' ? $found['profile']->shareText->in($locale) : __('games.play.share_default'), ['{profile}' => $name, '{game}' => $title]);
        $url = route('games.result', ['slug' => $slug, 'version' => (int) $version, 'profile' => $profile]);

        $record->handle(GameEventType::ShareClicked, $found['game'], $visitors->resolve(), null, (int) $found['version']->id, $provider->value);

        $target = $urls->build($provider, new ShareMetadata(title: $title, shareText: $text, description: $text, url: $url, imageUrl: null, siteName: $settings->current()->siteName()));
        abort_if($target === null, 404);

        return redirect()->away($target);
    }
}
