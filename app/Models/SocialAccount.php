<?php

namespace App\Models;

use App\Enums\SocialProvider;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property SocialProvider $provider
 * @property string|null $provider_email
 */
#[Fillable(['user_id', 'provider', 'provider_user_id', 'provider_email'])]
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
        ];
    }

    /** Keeps the shown address current; the provider may have changed it. */
    public function refreshProviderEmail(?string $email): void
    {
        $this->provider_email = $email;

        if ($this->isDirty('provider_email')) {
            $this->save();
        }
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
