<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Translation AI telemetry: one row per request a translation provider was
 * actually sent — not per item, not per click. Counts, codes and numbers only;
 * no column can hold a text, a prompt or an item id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_ai_provider_calls', function (Blueprint $table) {
            $table->id();

            // UTC. A datetime rather than a timestamp, so MariaDB stores the
            // UTC wall time as written instead of converting it through the
            // session's time zone.
            $table->dateTime('completed_at');

            $table->string('provider', 64);
            $table->string('model', 128);
            $table->string('consumer', 64);
            // The installed language, and the tag the provider was asked for (sr and sr-Cyrl).
            $table->string('target_locale', 35);
            $table->string('provider_target_locale', 35);

            // Plain strings, cast in the model: portable across PostgreSQL,
            // MariaDB and SQLite, and a new error code or consumer needs no
            // schema change.
            $table->string('status', 16);
            $table->string('error_code', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('external_request_id')->nullable();
            $table->unsignedInteger('latency_ms');

            $table->unsignedInteger('item_count');
            $table->unsignedInteger('succeeded_item_count');
            $table->unsignedInteger('failed_item_count');
            // Failed items by error code, {"constraint_violation": 1}; null when none failed.
            $table->json('item_failures')->nullable();

            // As the provider reported them; null when it did not, never 0.
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();

            // An estimate, not an invoice; null when the rates or the tokens are unknown.
            $table->decimal('estimated_cost_usd', 24, 12)->nullable();
            // The rates the estimate used, so it stays what it was when the configured rates change.
            $table->decimal('input_usd_per_million_tokens', 12, 6)->nullable();
            $table->decimal('output_usd_per_million_tokens', 12, 6)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('completed_at');
            $table->index(['provider', 'model', 'completed_at']);
            $table->index(['target_locale', 'completed_at']);
            $table->index(['consumer', 'completed_at']);
            $table->index(['status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_ai_provider_calls');
    }
};
