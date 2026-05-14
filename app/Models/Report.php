<?php

namespace App\Models;

use App\Enums\ReportReason;
use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $fillable = ['reporter_id', 'target_type', 'target_id', 'reason', 'message'];

    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'resolved_at' => 'datetime',
        ];
    }
}
