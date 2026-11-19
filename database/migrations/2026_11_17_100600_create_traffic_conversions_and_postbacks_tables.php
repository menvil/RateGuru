<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A moment a paid visit reached, once per visit, with what it was
        // worth then — the value its postbacks carry — or why no postback
        // went for it.
        Schema::create('traffic_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained('traffic_visits')->cascadeOnDelete();
            // Growing sets: TrafficEvent, TrafficValueBasis, TrafficPostbackSkip.
            $table->string('event', 24);
            $table->dateTime('occurred_at');
            $table->foreignId('source_id')->constrained('traffic_sources')->restrictOnDelete();
            $table->unsignedBigInteger('game_id')->nullable();
            $table->unsignedBigInteger('version_id')->nullable();
            $table->foreignId('attempt_id')->nullable()->constrained('game_attempts')->restrictOnDelete();
            $table->string('step', 24)->nullable();
            $table->string('goal', 24)->nullable();
            $table->decimal('value', 14, 8)->default(0);
            $table->string('value_basis', 12);
            $table->string('postback_skip', 24)->nullable();

            $table->unique(['visit_id', 'event'], 'traffic_conversions_visit_event_unique');
            $table->index(['source_id', 'id'], 'traffic_conversions_source_index');
            $table->index('attempt_id', 'traffic_conversions_attempt_index');
        });

        // The outbox: one postback per conversion and rule, its address
        // rendered when it was queued, sent by traffic:send-postbacks and
        // tried again until it is delivered or out of time. Kept 90 days.
        Schema::create('traffic_postbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversion_id')->constrained('traffic_conversions')->cascadeOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('traffic_postback_rules')->nullOnDelete();
            $table->foreignId('source_id')->constrained('traffic_sources')->restrictOnDelete();
            $table->string('event', 24);
            $table->text('url');
            // Growing sets: TrafficPostbackStatus, TrafficPostbackOutcome.
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('run_after');
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('outcome', 24)->nullable();
            $table->string('response_excerpt', 255)->nullable();
            $table->dateTime('resent_at')->nullable();
            $table->dateTime('created_at');

            $table->unique(['conversion_id', 'rule_id'], 'traffic_postbacks_conversion_rule_unique');
            $table->index(['status', 'run_after'], 'traffic_postbacks_due_index');
            $table->index(['source_id', 'id'], 'traffic_postbacks_source_index');
            $table->index(['rule_id', 'status', 'created_at'], 'traffic_postbacks_rule_index');
            $table->index('created_at', 'traffic_postbacks_created_index');
        });

        // Every failed try of a postback; the last try, failed or not, is on
        // the postback itself.
        Schema::create('traffic_postback_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('postback_id')->constrained('traffic_postbacks')->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt');
            $table->dateTime('attempted_at');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('outcome', 24);
            $table->string('response_excerpt', 255)->nullable();

            $table->unique(['postback_id', 'attempt'], 'traffic_postback_attempts_postback_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_postback_attempts');
        Schema::dropIfExists('traffic_postbacks');
        Schema::dropIfExists('traffic_conversions');
    }
};
