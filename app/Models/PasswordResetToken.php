<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Maps to Laravel's own `password_reset_tokens` table (created by the
 * framework's default migration, not one of this app's own). No model events
 * and no relations.
 *
 * It is NOT the only thing that writes this table, and the distinction matters:
 * Laravel's password broker owns the ordinary lifecycle, inserting a row for
 * every reset link sent and consuming it on reset (config/auth.php maps the
 * `users` broker to this table). This model exists for the two
 * account-security actions that invalidate outstanding links wholesale, and is
 * their ONLY write path — AnonymizeUserAccountAction when an account becomes a
 * tombstone, and ClaimAccountWithVerifiedEmailAction when a confirmed owner
 * takes an unconfirmed account over — each via a plain query-builder-style bulk
 * delete. It exists so those deletes go through Eloquent instead of a raw
 * DB::table() call, which this codebase's architecture rules restrict to
 * approved infrastructure classes.
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
