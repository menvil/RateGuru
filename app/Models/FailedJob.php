<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Maps to Laravel's own `failed_jobs` table (created by the framework's
 * default migration, not one of this app's own). No model events, no
 * relations — FailedMediaJobReader is the only reader, and only ever via
 * plain query-builder-style Eloquent calls (query(), orderByDesc(), get()),
 * never anything that would need this class to know its own business
 * meaning. Existing purely so that read goes through Eloquent instead of a
 * raw DB::table() call, which this codebase's architecture rules restrict to
 * approved infrastructure classes.
 */
class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    protected $guarded = [];
}
