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
 * Deliberately nothing but the identity itself: no token, no provider email,
 * no name, no avatar. `provider + provider_user_id` is the key the person is
 * recognised by on every later sign-in; the email is only ever used once, to
 * decide which account a brand-new identity belongs with.
 *
 * @property SocialProvider $provider
 */
#[Fillable(['user_id', 'provider', 'provider_user_id'])]
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
