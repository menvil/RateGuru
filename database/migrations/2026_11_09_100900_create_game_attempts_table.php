<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One play of a version, from Start to its last screen. Pinned to its
        // version for good, so a new allocation of traffic never moves it;
        // its score is the server's, worked out from its answers and the
        // version's snapshot.
        Schema::create('game_attempts', function (Blueprint $table) {
            $table->id();
            // What a browser names the attempt by, instead of its id.
            $table->ulid('public_id')->unique();
            $table->foreignId('game_id')->constrained('games')->restrictOnDelete();
            $table->foreignId('version_id')->constrained('game_versions')->restrictOnDelete();
            // None in an assignment-free start, such as a replay of an
            // assignment that has since ended.
            $table->foreignId('assignment_id')->nullable()->constrained('game_assignments')->restrictOnDelete();
            // The audience it started in, kept when the visitor signs in later.
            $table->enum('audience', ['anonymous', 'authenticated']);
            // A guest's attempt gains its person when they sign in or register
            // (ClaimGuestGameAttemptsAction) and keeps its visitor hash.
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('visitor_hash', 64)->nullable();
            $table->enum('status', ['started', 'completed'])->default('started');
            // The flow step it stands on, by the step's key in the snapshot.
            $table->string('current_step_key', 40)->nullable();
            $table->unsignedSmallInteger('rounds_answered')->default(0);
            $table->unsignedSmallInteger('matches')->nullable();
            $table->unsignedInteger('score')->nullable();
            $table->unsignedInteger('max_score');
            $table->string('profile_key', 40)->nullable();
            $table->string('locale', 12);
            // Whether a guest's attempt led to a new account or a sign-in.
            $table->enum('account_outcome', ['registered', 'signed_in'])->nullable();
            $table->dateTime('account_outcome_at')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('last_activity_at');
            $table->timestamps();

            $table->index(['game_id', 'started_at'], 'game_attempts_game_started_index');
            $table->index(['version_id', 'status', 'completed_at'], 'game_attempts_version_status_index');
            $table->index('assignment_id', 'game_attempts_assignment_index');
            $table->index('user_id', 'game_attempts_user_index');
            $table->index('visitor_hash', 'game_attempts_visitor_index');
        });

        // A pick, one per round of an attempt, in order. Its outcome is read
        // from the version's snapshot when it is made, never sent by the browser.
        Schema::create('game_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('game_attempts')->restrictOnDelete();
            $table->unsignedSmallInteger('round_index');
            $table->string('round_key', 40);
            $table->unsignedTinyInteger('option_position');
            $table->enum('outcome', ['correct', 'incorrect', 'neutral']);
            $table->unsignedInteger('points');
            $table->dateTime('answered_at');
            $table->timestamps();

            $table->unique(['attempt_id', 'round_index'], 'game_answers_attempt_round_unique');
        });

        // An email a guest left on an Email Capture step: stored, with the
        // consent they did or did not give, and never sent anything. Not an
        // account.
        Schema::create('game_email_captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->unique()->constrained('game_attempts')->restrictOnDelete();
            $table->foreignId('game_id')->constrained('games')->restrictOnDelete();
            $table->string('email', 254);
            $table->boolean('marketing_consent')->default(false);
            // The consent wording the guest saw, as it was shown.
            $table->string('consent_label', 300)->nullable();
            $table->dateTime('captured_at');
            $table->timestamps();

            $table->index(['game_id', 'captured_at'], 'game_email_captures_game_index');
            // What games:purge-expired finds expired emails by.
            $table->index('captured_at', 'game_email_captures_captured_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_email_captures');
        Schema::dropIfExists('game_answers');
        Schema::dropIfExists('game_attempts');
    }
};
