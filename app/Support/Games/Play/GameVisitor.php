<?php

namespace App\Support\Games\Play;

use App\Enums\GameAudience;

/**
 * Who is playing: a signed-in person, by their account, or an anonymous
 * browser, by the SHA-256 of its first-party cookie token. `identityKey` names
 * them for assignments and rate limits; an anonymous visitor is the same
 * visitor only on the browser that holds the cookie.
 *
 * `browserHash` is that browser's hash whoever is signed in, when it holds a
 * cookie: the traffic tracker knows a visit by the browser, not the account,
 * so a sign-in in the middle of a visit keeps it the same visit. The game
 * tables keep only `visitorHash`, which a signed-in player does not have.
 */
final readonly class GameVisitor
{
    public function __construct(
        public GameAudience $audience,
        public ?int $userId,
        public ?string $visitorHash,
        public ?string $browserHash = null,
    ) {}

    public function identityKey(): string
    {
        return $this->userId !== null ? 'u:'.$this->userId : 'v:'.$this->visitorHash;
    }

    public function isAnonymous(): bool
    {
        return $this->audience === GameAudience::Anonymous;
    }
}
