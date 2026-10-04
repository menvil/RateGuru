<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Maps to Laravel's own `sessions` table (created by the framework's
 * default migration, not one of this app's own). No model events, no
 * relations — AnonymizeUserAccountAction is the only writer, and only ever
 * via a plain query-builder-style bulk delete. Existing purely so that
 * write goes through Eloquent instead of a raw DB::table() call, which this
 * codebase's architecture rules restrict to approved infrastructure classes.
 */
class Session extends Model
{
    protected $table = 'sessions';

    /** Session ids are opaque strings, not auto-increment integers. */
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
