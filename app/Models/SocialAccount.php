<?php

namespace App\Models;

use App\Enums\SocialProvider;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One external identity (a Google or Facebook subject) attached to one user.
 *
 * Deliberately almost nothing but the identity itself: no token, no name, no
 * avatar. `provider + provider_user_id` is the key the person is recognised
 * by on every later sign-in. The provider's email is kept only so the
 * profile can show which Google or Facebook account is connected: it is
 * refreshed on every sign-in through the identity, never used to find an
 * account, and goes with the row.
 *
 * `provider_email_verified` is the one thing here that is a SECURITY fact rather
 * than a display one: whether the provider had confirmed the address currently in
 * `provider_email`. Account claiming needs it, because the question "may this
 * existing sign-in method survive somebody else proving they own this address" has
 * no honest answer without it. null means the row predates the column and carries
 * no proof, which is treated as untrusted.
 *
 * @property SocialProvider $provider
 * @property string|null $provider_email
 * @property bool|null $provider_email_verified
 */
#[Fillable(['user_id', 'provider', 'provider_user_id', 'provider_email', 'provider_email_verified'])]
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'provider_email_verified' => 'boolean',
        ];
    }

    /**
     * Keeps the recorded address current — the provider may have changed it —
     * together with whether the provider confirms THAT address.
     *
     * The two move as one on purpose. They were separable once, and a stale
     * `verified = true` left beside a new address is a proof about an address
     * nobody proved: exactly the thing account claiming reads to decide whether
     * this sign-in method may survive someone else taking the account over. An
     * address that is absent cannot be confirmed either, so null email forces
     * null verification rather than keeping the previous answer.
     */
    public function recordProviderEmail(?string $email, bool $verifiedByProvider): void
    {
        $this->provider_email = $email;
        $this->provider_email_verified = $email === null ? null : $verifiedByProvider;

        if ($this->isDirty(['provider_email', 'provider_email_verified'])) {
            $this->save();
        }
    }

    /**
     * Does this link carry proof that its holder owns the given address?
     *
     * Both halves are required: the provider must have confirmed the address, and
     * it must be the SAME address. A verified link to a different mailbox proves
     * ownership of that other mailbox and nothing about this one.
     */
    public function provesOwnershipOf(?string $normalizedEmail): bool
    {
        if ($normalizedEmail === null || $this->provider_email === null) {
            return false;
        }

        return $this->provider_email_verified === true
            && Str::lower(trim($this->provider_email)) === $normalizedEmail;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
