<?php

namespace App\Support\Observability;

use App\Exceptions\Observability\SecurityLogUnavailableException;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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
 *
 * One address writes at most LINES_PER_MINUTE lines a minute, then a single
 * line saying it was cut off, then nothing until the minute is over. A
 * script repeating the call is still plain to see, and it cannot fill the
 * disk the log is kept on for a month.
 */
final class GuestVoteAttemptLog
{
    public const EVENT = 'security.guest_vote_attempted';

    public const THROTTLED_EVENT = 'security.guest_vote_attempts_throttled';

    public const LINES_PER_MINUTE = 30;

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

        // Counted before anything is written, and fail-open: an unreachable
        // cache leaves the log unlimited rather than empty.
        $lines = rescue(
            // The client's address hashed: cache keys travel, into Sentry's
            // cache breadcrumbs and spans among others.
            fn (): int => RateLimiter::hit('guest-vote-attempt-log:'.hash('sha256', (string) $request->ip()), 60),
            0,
            report: false,
        );

        if ($lines > self::LINES_PER_MINUTE + 1) {
            return;
        }

        try {
            $logger->info($lines > self::LINES_PER_MINUTE ? self::THROTTLED_EVENT : self::EVENT, [
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
