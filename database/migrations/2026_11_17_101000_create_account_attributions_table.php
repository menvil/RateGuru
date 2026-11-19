<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a new account is credited to, decided when it signs up — the
        // last game the browser played and its holding paid click, or the
        // unpaid visit — because the confirmation may come later, on another
        // device. Counted once, when the account is confirmed
        // (docs/architecture/traffic.md, "Accounts").
        Schema::create('account_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique('account_attributions_user_unique')->constrained('users')->restrictOnDelete();
            $table->char('visitor_hash', 64)->nullable();
            // Growing sets: AccountSignupMethod, AccountSignupPlace.
            $table->string('signup_method', 16)->nullable();
            $table->string('signup_place', 8)->nullable();
            $table->dateTime('signed_up_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('credited_attempt_id')->nullable()->constrained('game_attempts')->restrictOnDelete();
            $table->foreignId('credited_visit_id')->nullable()->constrained('traffic_visits')->nullOnDelete();
            // A paid click whose window had closed when the account signed up.
            $table->foreignId('expired_visit_id')->nullable()->constrained('traffic_visits')->nullOnDelete();
            $table->boolean('late')->default(false);
            $table->dateTime('first_post_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->timestamps();

            $table->index('visitor_hash', 'account_attributions_visitor_index');
            $table->index('credited_attempt_id', 'account_attributions_attempt_index');
            $table->index('credited_visit_id', 'account_attributions_visit_index');
            $table->index('expired_visit_id', 'account_attributions_expired_visit_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_attributions');
    }
};
