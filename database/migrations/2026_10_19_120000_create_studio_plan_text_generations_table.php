<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post text suggestions: one row per batch a publication plan asked for —
 * ten titles and ten descriptions written from its photo's analysis, or why
 * none were. Every batch is kept, so a new one never takes the earlier ones
 * away. The texts and call metadata only; never the prompt, the analysis or
 * what the provider sent back beyond the validated texts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_plan_text_generations', function (Blueprint $table) {
            $table->id();

            // Gone with the plan.
            $table->foreignId('studio_plan_id')->constrained('studio_plans')->cascadeOnDelete();

            // What the batch was written from, to tell whether it still fits
            // the plan: the photo's id — no foreign key, so the history never
            // holds a released photo back from its purge — and the analysis
            // it was written from, as StudioImageDescription::hash().
            $table->unsignedBigInteger('source_image_asset_id');
            $table->char('source_analysis_hash', 64);

            $table->string('tone', 32);

            // The batch's state, its attempt id — so a job delivered twice
            // claims it once — and why it failed when it did. Codes are plain
            // strings, cast in the model, so a new one needs no schema change.
            $table->enum('status', ['queued', 'running', 'completed', 'failed']);
            $table->uuid('attempt_id')->unique();
            $table->string('error_code', 64)->nullable();

            // The texts, once written: exactly as many of each as were asked.
            $table->json('titles')->nullable();
            $table->json('descriptions')->nullable();

            $table->string('model', 128)->nullable();
            $table->string('request_id')->nullable();
            // As the provider reported them; null when it did not, never 0.
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            // UTC. Datetimes rather than timestamps, so MariaDB stores the UTC
            // wall time as written.
            $table->dateTime('requested_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();

            $table->timestamps();

            // A plan's batches newest first, and the one on its way.
            $table->index(['studio_plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_plan_text_generations');
    }
};
