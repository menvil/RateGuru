<?php

namespace App\Support\Observability;

use App\Exceptions\Observability\SecurityLogUnavailableException;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A guest's attempt to vote, written to the security log
 * (storage/logs/security-YYYY-MM-DD.log) with the visitor's IP address,
 * browser and the page they were on, so spam and abuse by visitors who never
 * sign in can be traced. The attempt itself changes nothing: the voting
 * component refuses it as before, whether or not the line could be written.
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

        try {
            $logger->info(self::EVENT, [
                'kind' => $kind,
                ...$target,
                'ip' => $request->ip(),
                'user_agent' => $this->header($request->userAgent()),
                'referer' => $this->header($request->headers->get('referer')),
                ...$this->logContext->base(),
            ]);
        } catch (Throwable $failure) {
            // Best effort: the vote is refused either way, and a security log
            // that cannot be written must not turn that refusal into an error
            // page. What went wrong is still reported, but never the failure
            // itself: Sentry attaches call arguments to the frames it sends,
            // and that trace holds this record, the visitor's address
            // included. Monolog also appends the record below the first line
            // of its message, so only that first line is kept — cut here, so
            // the rest is never an argument either. If even reporting fails,
            // the refusal still goes out.
            $reason = $failure::class.': '.strtok($failure->getMessage(), "\n");

            rescue(fn () => report(SecurityLogUnavailableException::because($reason)), report: false);
        }
    }

    private function header(?string $value): ?string
    {
        return $value === null ? null : Str::limit($value, self::HEADER_LIMIT, '');
    }
}
