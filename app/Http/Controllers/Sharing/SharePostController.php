<?php

namespace App\Http\Controllers\Sharing;

use App\Enums\ShareProvider;
use App\Http\Controllers\Controller;
use App\Queries\Posts\PublishedPostDetailsQuery;
use App\Support\Sharing\PostShareMetadata;
use App\Support\Sharing\ShareUrlBuilder;
use Illuminate\Http\RedirectResponse;

/**
 * Sends a visitor from a post's share button on to the provider's share page.
 * See ShareProvider::sharesThroughRedirect() for why the button links here.
 *
 * Only a post its own page would show can be shared, so nothing about a
 * pending or hidden post leaves through the share text.
 */
class SharePostController extends Controller
{
    public function __invoke(
        string $post,
        ShareProvider $provider,
        PublishedPostDetailsQuery $posts,
        PostShareMetadata $postShareMetadata,
        ShareUrlBuilder $urlBuilder,
    ): RedirectResponse {
        abort_unless($provider->sharesThroughRedirect(), 404);
        abort_unless(config("share.providers.{$provider->value}.enabled", true), 404);

        $url = $urlBuilder->build($provider, $postShareMetadata->forPost($posts->findOrFail((int) $post)));

        abort_if($url === null, 404);

        return redirect()->away($url);
    }
}
