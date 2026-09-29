<?php

namespace App\Data\Auth;

use App\Enums\SocialLoginOutcome;
use App\Models\User;

final readonly class SocialLoginResult
{
    private function __construct(
        public SocialLoginOutcome $outcome,
        /** The account involved; null only while a link is pending. */
        public ?User $user,
        /** A claimed account lost a password its unconfirmed creator had set. */
        public bool $passwordRemoved = false,
    ) {}

    public static function loggedIn(User $user): self
    {
        return new self(SocialLoginOutcome::LoggedIn, $user);
    }

    public static function claimed(User $user, bool $passwordRemoved): self
    {
        return new self(SocialLoginOutcome::Claimed, $user, $passwordRemoved);
    }

    public static function registered(User $user): self
    {
        return new self(SocialLoginOutcome::Registered, $user);
    }

    public static function linked(User $user): self
    {
        return new self(SocialLoginOutcome::Linked, $user);
    }

    public static function pendingLink(): self
    {
        return new self(SocialLoginOutcome::PendingLink, null);
    }
}
