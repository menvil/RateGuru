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
    ) {}

    public static function loggedIn(User $user): self
    {
        return new self(SocialLoginOutcome::LoggedIn, $user);
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
