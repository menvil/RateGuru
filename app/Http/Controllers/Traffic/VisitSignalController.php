<?php

namespace App\Http\Controllers\Traffic;

use App\Actions\Traffic\RecordVisitSignalAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Traffic\VisitSignalRequest;
use App\Support\Games\Play\GameVisitorResolver;
use App\Support\Traffic\Capture\RequestSignals;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

/**
 * Takes what a page's script tells the traffic tracker and answers 204,
 * always: a browser without the tracker's cookie, a bad token or a switched-off
 * tracker are all nothing to record.
 */
final class VisitSignalController extends Controller
{
    public function __invoke(VisitSignalRequest $request, RecordVisitSignalAction $record, GameVisitorResolver $visitors, RequestSignals $signals): Response
    {
        $hash = $visitors->browserHash();
        $validated = $request->validated();

        if ($hash !== null) {
            $record->handle(
                (string) $validated['signal'],
                isset($validated['arrival']) ? (string) $validated['arrival'] : null,
                $hash,
                $request->user()?->getAuthIdentifier() === null ? null : (int) $request->user()->getAuthIdentifier(),
                $signals->read(),
                CarbonImmutable::now('UTC'),
                isset($validated['post']) ? (int) $validated['post'] : null,
            );
        }

        return response()->noContent();
    }
}
