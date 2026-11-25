<?php

namespace App\Http\Middleware;

use App\Actions\Users\TouchMemberVisitAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Keeps when a signed-in member was last seen, and so where their previous visit ended (TouchMemberVisitAction):
 * before the page is drawn, so the feed of the first page of a visit already counts what came out since the last
 * one. Any request — a Livewire update included — but at most once every few minutes a session, and never in the
 * member's way.
 */
final class TrackMemberVisit
{
    private const string SESSION_KEY = 'visit.touched_at';

    private const int EVERY_SECONDS = 300;

    public function __construct(private readonly TouchMemberVisitAction $visits) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = $request->user();

            if ($user instanceof User && $request->hasSession()) {
                $now = CarbonImmutable::now();
                $touched = $request->session()->get(self::SESSION_KEY);

                if (! is_int($touched) || $now->getTimestamp() - $touched >= self::EVERY_SECONDS) {
                    $this->visits->handle($user, $now, $request->ip());
                    $request->session()->put(self::SESSION_KEY, $now->getTimestamp());
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $next($request);
    }
}
