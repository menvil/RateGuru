<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Maps to Laravel's own `password_reset_tokens` table (created by the
 * framework's default migration, not one of this app's own). No model
 * events, no relations — the two account-security actions that invalidate
 * outstanding reset links are its only writers, and only ever via a plain
 * query-builder-style bulk delete: AnonymizeUserAccountAction when an account
 * becomes a tombstone, and ClaimAccountWithVerifiedEmailAction when a
 * confirmed owner takes an unconfirmed account over. Existing purely so those
 * writes go through Eloquent instead of a raw DB::table() call, which this
 * codebase's architecture rules restrict to approved infrastructure
 * classes.
 */
class PasswordResetToken extends Model
{
    protected $table = 'password_reset_tokens';

    protected $primaryKey = 'email';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
