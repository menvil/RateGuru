<?php

namespace App\Http\Controllers\Traffic;

use App\Actions\Traffic\RecordVisitPresenceAction;
use App\Actions\Traffic\RecordVisitSignalAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Traffic\VisitSignalRequest;
use App\Services\Traffic\RequestSignals;
use App\Support\Games\Play\GameVisitorResolver;
use App\Support\Traffic\Capture\PresenceFacts;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

/**
 * Takes what a page's script tells the traffic tracker and answers 204,
 * always: a browser without the tracker's cookie, a bad token or a switched-off
 * tracker are all nothing to record. A landing page's measure of its visitor
 * (presence) goes to RecordVisitPresenceAction, every other signal to
 * RecordVisitSignalAction.
 */
final class VisitSignalController extends Controller
{
    public function __invoke(VisitSignalRequest $request, RecordVisitSignalAction $record, RecordVisitPresenceAction $presence, GameVisitorResolver $visitors, RequestSignals $signals): Response
    {
        $hash = $visitors->browserHash();
        $validated = $request->validated();

        if ($hash !== null && $validated['signal'] === 'presence') {
            $presence->handle(
                isset($validated['arrival']) ? (string) $validated['arrival'] : null,
                $hash,
                PresenceFacts::from(array_intersect_key($validated, array_flip(VisitSignalRequest::PRESENCE_FIELDS))),
                $signals->read(),
                $request->userAgent(),
                CarbonImmutable::now('UTC'),
            );
        } elseif ($hash !== null) {
            $record->handle(
                (string) $validated['signal'],
                isset($validated['arrival']) ? (string) $validated['arrival'] : null,
                $hash,
                $request->user()?->getAuthIdentifier() === null ? null : (int) $request->user()->getAuthIdentifier(),
                $signals->read(),
                CarbonImmutable::now('UTC'),
                isset($validated['post']) ? (int) $validated['post'] : null,
                isset($validated['mode']) ? (string) $validated['mode'] : null,
                isset($validated['trigger']) ? (string) $validated['trigger'] : null,
            );
        }

        return response()->noContent();
    }
}
