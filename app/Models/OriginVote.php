<?php

namespace App\Models;

use App\Enums\OriginType;
use Illuminate\Database\Eloquent\Model;

class OriginVote extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'origin' => OriginType::class,
        ];
    }
}
