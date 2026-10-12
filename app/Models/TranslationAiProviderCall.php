<?php

namespace App\Models;

use App\Support\TranslationEngine\Enums\TranslationStatus;
use Database\Factories\TranslationAiProviderCallFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One request a translation provider was actually sent, as Translation AI
 * telemetry keeps it: who answered, for which consumer and language, how it
 * went, how many items it carried and how many came back translated, and what
 * it used and is estimated to have cost. Counts, codes and numbers only —
 * never a text, a prompt, an item id or anything a provider said.
 *
 * Written once by TranslationAiCallRecorder and never updated; read by
 * TranslationAiUsageQuery; deleted after translation.telemetry.retention_days by
 * translations:purge-ai-calls.
 *
 * @property TranslationStatus $status
 * @property Carbon $completed_at
 * @property array<string, int>|null $item_failures
 * @property string|null $estimated_cost_usd
 * @property string|null $input_usd_per_million_tokens
 * @property string|null $output_usd_per_million_tokens
 */
class TranslationAiProviderCall extends Model
{
    /** @use HasFactory<TranslationAiProviderCallFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'status' => TranslationStatus::class,
            'http_status' => 'integer',
            'latency_ms' => 'integer',
            'item_count' => 'integer',
            'succeeded_item_count' => 'integer',
            'failed_item_count' => 'integer',
            'item_failures' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'estimated_cost_usd' => 'decimal:12',
            'input_usd_per_million_tokens' => 'decimal:6',
            'output_usd_per_million_tokens' => 'decimal:6',
        ];
    }
}
