<?php

namespace App\Http\Controllers\Sharing;

use App\Actions\Analytics\RecordVisitPostAction;
use App\Enums\ShareProvider;
use App\Http\Controllers\Controller;
use App\Queries\Posts\PublishedPostDetailsQuery;
use App\Support\Games\Play\GameVisitorResolver;
use App\Support\Sharing\PostShareMetadata;
use App\Support\Sharing\ShareUrlBuilder;
use App\Support\Traffic\Capture\BotSignals;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Sends a visitor from a post's share button on to the provider's share page.
 * See ShareProvider::sharesThroughRedirect() for why the button links here.
 *
 * Only a post its own page would show can be shared, so nothing about a
 * pending or hidden post leaves through the share text. On the way, the
 * share goes on the visit the browser is on (RecordVisitPostAction) — never
 * a crawler's, never a browser's without the visitor cookie — and a failure
 * to record it is reported and never keeps the visitor from the provider.
 */
class SharePostController extends Controller
{
    public function __invoke(
        Request $request,
        string $post,
        ShareProvider $provider,
        PublishedPostDetailsQuery $posts,
        PostShareMetadata $postShareMetadata,
        ShareUrlBuilder $urlBuilder,
        GameVisitorResolver $visitors,
        RecordVisitPostAction $shares,
    ): RedirectResponse {
        abort_unless($provider->sharesThroughRedirect(), 404);
        abort_unless(config("share.providers.{$provider->value}.enabled", true), 404);

        $shared = $posts->findOrFail((int) $post);
        $url = $urlBuilder->build($provider, $postShareMetadata->forPost($shared));

        abort_if($url === null, 404);

        $hash = $visitors->browserHash();

        if ($hash !== null && ! BotSignals::isCrawlerAgent($request->userAgent())) {
            try {
                $shares->shared($hash, (int) $shared->id, CarbonImmutable::now('UTC'));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return redirect()->away($url);
    }
}
