<?php

namespace App\Livewire\Concerns;

use App\Actions\Analytics\RecordVisitPostAction;
use App\Support\Games\Play\GameVisitorResolver;
use App\Support\Traffic\Capture\BotSignals;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Lets a component that shows a selected post count it opened on the
 * browser's visit (RecordVisitPostAction): once a request, however many of
 * the request's components show it — the feed and the overlay answer the
 * same click in one request — and never for a crawler or a browser without
 * the visitor cookie. A failure is reported and never keeps the post from
 * showing.
 */
trait CountsPostViews
{
    private function countPostView(int $postId): void
    {
        try {
            $request = request();
            $counted = $request->attributes->get('rg_counted_post_views', []);
            $counted = is_array($counted) ? $counted : [];

            if (in_array($postId, $counted, true) || BotSignals::isCrawlerAgent($request->userAgent())) {
                return;
            }

            $request->attributes->set('rg_counted_post_views', [...$counted, $postId]);
            $browserHash = app(GameVisitorResolver::class)->browserHash();

            if ($browserHash !== null) {
                app(RecordVisitPostAction::class)->viewed($browserHash, $postId, CarbonImmutable::now('UTC'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
