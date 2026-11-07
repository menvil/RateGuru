<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photo analysis: one row per publication plan, describing its photo — the
 * latest attempt to analyse it, and the last result one gave. A structured
 * description and call metadata; never the image, the prompt or what the
 * provider sent back beyond the validated description.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_plan_image_analyses', function (Blueprint $table) {
            $table->id();

            // One current analysis per plan, gone with the plan.
            $table->foreignId('studio_plan_id')->unique()->constrained('studio_plans')->cascadeOnDelete();

            // The photo this row is about, to tell whether it is still the
            // plan's — not a reference that holds the asset: no foreign key,
            // and the media lifecycle does not count it, so a replaced photo
            // is released and purged as if this row did not exist.
            $table->unsignedBigInteger('source_image_asset_id');

            // The latest attempt: its state, its id — new for every attempt,
            // so a job of an earlier one finds nothing to claim — and why it
            // failed when it did. Codes are plain strings, cast in the model,
            // so a new one needs no schema change.
            $table->enum('status', ['queued', 'running', 'completed', 'failed']);
            $table->uuid('attempt_id')->unique();
            $table->string('error_code', 64)->nullable();

            // The last result for this photo and the call that gave it — kept
            // through a failed attempt after it, cleared when the photo changes.
            $table->json('result')->nullable();
            $table->string('model', 128)->nullable();
            $table->string('request_id')->nullable();
            // As the provider reported them; null when it did not, never 0.
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            // UTC. Datetimes rather than timestamps, so MariaDB stores the UTC
            // wall time as written. requested_at and started_at are the latest
            // attempt's; finished_at is the result's.
            $table->dateTime('requested_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();

            $table->timestamps();

            $table->index('source_image_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_plan_image_analyses');
    }
};
