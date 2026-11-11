<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every tracked moment of a game, append-only, written in the same
        // transaction as the change it records (GameEventRecorder). Ids and
        // codes only: no text a visitor typed. Kept for a fixed number of days
        // (games:purge-expired).
        Schema::create('game_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->restrictOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('game_versions')->restrictOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('game_attempts')->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('game_assignments')->restrictOnDelete();
            $table->enum('audience', ['anonymous', 'authenticated']);
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('visitor_hash', 64)->nullable();
            // A growing set (GameEventType).
            $table->string('type', 40);
            $table->string('step_type', 40)->nullable();
            $table->unsignedSmallInteger('round_index')->nullable();
            $table->string('provider', 40)->nullable();
            $table->dateTime('occurred_at');

            $table->index(['game_id', 'type', 'occurred_at'], 'game_events_game_type_index');
            $table->index(['version_id', 'type', 'occurred_at'], 'game_events_version_type_index');
            $table->index('attempt_id', 'game_events_attempt_index');
            $table->index('assignment_id', 'game_events_assignment_index');
            $table->index('user_id', 'game_events_user_index');
            $table->index('occurred_at', 'game_events_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_events');
    }
};
