<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A file of daily spend the administrator uploaded.
 *
 * @property int $id
 * @property int $source_id
 * @property string $file_name
 * @property string $status
 * @property int $fact_rows
 * @property string|null $day_from
 * @property string|null $day_to
 * @property string|null $total
 * @property int $skipped_rows
 * @property string|null $skipped_amount
 * @property string|null $error
 * @property int $uploaded_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class TrafficSpendImport extends Model
{
    protected $table = 'traffic_spend_imports';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'fact_rows' => 'integer',
            'total' => 'decimal:8',
            'skipped_rows' => 'integer',
            'skipped_amount' => 'decimal:8',
            'uploaded_by_user_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TrafficSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(TrafficSource::class, 'source_id');
    }

    /** @return HasMany<TrafficSpendDay, $this> */
    public function days(): HasMany
    {
        return $this->hasMany(TrafficSpendDay::class, 'import_id');
    }
}
