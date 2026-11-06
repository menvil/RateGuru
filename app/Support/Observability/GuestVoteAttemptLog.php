<?php

namespace App\Support\Observability;

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A guest's attempt to vote, written to the security log
 * (storage/logs/security-YYYY-MM-DD.log) with the visitor's IP address,
 * browser and the page they were on, so spam and abuse by visitors who never
 * sign in can be traced. The attempt itself changes nothing: the voting
 * component refuses it as before.
 *
 * The line goes to the channel's Monolog logger directly, past Laravel's
 * MessageLogged event. That event is what Sentry turns into breadcrumbs, and
 * an IP address stays on the server: Sentry is sent no personal data
 * (send_default_pii is off), and this log must not become the way round it.
 */
final class GuestVoteAttemptLog
{
    public const EVENT = 'security.guest_vote_attempted';

    /** The longest header value kept; a real browser's is far shorter. */
    private const HEADER_LIMIT = 512;

    public function __construct(private readonly LogContext $logContext) {}

    /**
     * @param  'post'|'comment'|'rating'  $kind
     * @param  array<string, int|string|null>  $target  what the vote was for and how
     */
    public function record(string $kind, array $target): void
    {
        $request = request();
        $channel = Log::channel('security');
        $logger = $channel instanceof Logger ? $channel->getLogger() : $channel;

        $logger->info(self::EVENT, [
            'kind' => $kind,
            ...$target,
            'ip' => $request->ip(),
            'user_agent' => $this->header($request->userAgent()),
            'referer' => $this->header($request->headers->get('referer')),
            ...$this->logContext->base(),
        ]);
    }

    private function header(?string $value): ?string
    {
        return $value === null ? null : Str::limit($value, self::HEADER_LIMIT, '');
    }
}
