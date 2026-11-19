<?php

namespace App\Http\Middleware;

use App\Actions\Traffic\RecordAccountBonusAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A signed-in member coming back: looked at once a day per session — the
 * session remembers the day it last looked, or, for an account too young to
 * have come back, the moment it first can — whether a new account came back
 * on another day (RecordAccountBonusAction). Never in the member's way.
 */
final class RecordAccountReturn
{
    private const string SESSION_KEY = 'traffic.return_checked_on';

    private const string NOT_BEFORE_KEY = 'traffic.return_not_before';

    public function __construct(private readonly RecordAccountBonusAction $bonuses) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $user = $request->user();

            if ($user instanceof User && $request->isMethod('GET') && $request->hasSession()) {
                $now = CarbonImmutable::now();
                $today = $now->utc()->toDateString();
                $notBefore = $request->session()->get(self::NOT_BEFORE_KEY);
                $waiting = is_int($notBefore) && $now->getTimestamp() < $notBefore;

                if (! $waiting && ($request->session()->get(self::SESSION_KEY) !== $today || is_int($notBefore))) {
                    // Remembered only once it has looked: a look that failed is tried again on the next page.
                    $retryAt = $this->bonuses->returned((int) $user->id, $now);
                    $request->session()->put(self::SESSION_KEY, $today);
                    $retryAt === null ? $request->session()->forget(self::NOT_BEFORE_KEY) : $request->session()->put(self::NOT_BEFORE_KEY, $retryAt->getTimestamp());
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $response;
    }
}
