<?php

namespace App\Support\Games\Play;

use App\Enums\GameAudience;

/**
 * Who is playing: a signed-in person, by their account, or an anonymous
 * browser, by the SHA-256 of its first-party cookie token. `identityKey` names
 * them for assignments and rate limits; an anonymous visitor is the same
 * visitor only on the browser that holds the cookie.
 */
final readonly class GameVisitor
{
    public function __construct(
        public GameAudience $audience,
        public ?int $userId,
        public ?string $visitorHash,
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
