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
 * session remembers the day it last looked — whether a new account came back
 * on another day (RecordAccountBonusAction). Never in the member's way.
 */
final class RecordAccountReturn
{
    private const string SESSION_KEY = 'traffic.return_checked_on';

    public function __construct(private readonly RecordAccountBonusAction $bonuses) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $user = $request->user();

            if ($user instanceof User && $request->isMethod('GET') && $request->hasSession()) {
                $today = CarbonImmutable::now()->utc()->toDateString();

                if ($request->session()->get(self::SESSION_KEY) !== $today) {
                    $request->session()->put(self::SESSION_KEY, $today);
                    $this->bonuses->returned((int) $user->id, CarbonImmutable::now());
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $response;
    }
}
