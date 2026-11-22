<?php

namespace App\Support\Observability;

/**
 * A guest's attempt to vote, written to the security log so spam and abuse by
 * visitors who never sign in can be traced. The attempt itself changes
 * nothing: the voting component refuses it as before, whether or not the
 * line could be written.
 */
final class GuestVoteAttemptLog
{
    public const EVENT = 'security.guest_vote_attempted';

    public const THROTTLED_EVENT = 'security.guest_vote_attempts_throttled';

    public const LINES_PER_MINUTE = 30;

    public function __construct(private readonly SecurityLog $log) {}

    /**
     * @param  'post'|'comment'|'rating'  $kind
     * @param  array<string, int|string|null>  $target  what the vote was for and how
     */
    public function record(string $kind, array $target): void
    {
        $this->log->write('guest-vote-attempt-log', self::EVENT, self::THROTTLED_EVENT, self::LINES_PER_MINUTE, [
            'kind' => $kind,
            ...$target,
        ]);
    }
}
