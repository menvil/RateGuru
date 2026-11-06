<?php

namespace App\Data\Auth;

use App\Enums\SocialProvider;
use Illuminate\Support\Str;

/**
 * The normalized subset of what an OAuth provider told us about a person:
 * everything social sign-in needs and nothing it does not. Tokens, avatars
 * and the raw profile payload are deliberately not carried — provider data
 * only exists for the duration of the callback that produced this object.
 */
final readonly class SocialIdentity
{
    /** The longest value `users.name` accepts. */
    private const int MAX_NAME_LENGTH = 255;

    public function __construct(
        public SocialProvider $provider,
        public string $providerUserId,
        /** Trimmed and lowercased exactly like a typed registration email; null when the provider shared none. */
        public ?string $email,
        public ?string $name,
        public ?string $nickname,
        /**
         * Whether the provider is authoritative enough for this address that a
         * new account may start out verified. Decided per provider by
         * SocialIdentityNormalizer, and false whenever in doubt.
         */
        public bool $emailVerifiedByProvider,
    ) {}

    /**
     * The name a new account starts with: the provider's full name, then its
     * nickname, then the local part of the email address.
     */
    public function displayName(): string
    {
        foreach ([$this->name, $this->nickname, Str::before((string) $this->email, '@')] as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return Str::limit($candidate, self::MAX_NAME_LENGTH, '');
            }
        }

        return '';
    }
}
