<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The days a member came to the site and did something — voted,
        // commented, posted, started a game — one row a day, each thing a
        // flag set the first time it happens that day (UserActivity). Kept
        // apart from the votes and comments themselves: a vote taken back
        // deletes its row, Content Studio dates a Persona's votes as its plan
        // says, and the day of the account's life is worked out in PHP, not
        // in SQL. Kept 400 days (traffic:purge-expired).
        Schema::create('user_activity_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // The UTC day, Y-m-d.
            $table->char('day', 10);
            // Whole UTC days since the day the account signed up, that day 0.
            $table->unsignedSmallInteger('day_number')->nullable();
            $table->boolean('voted')->default(false);
            $table->boolean('commented')->default(false);
            $table->boolean('posted')->default(false);
            $table->boolean('played')->default(false);
            $table->dateTime('first_at');

            // Led by user_id: the index the foreign key's lookups go through too.
            $table->unique(['user_id', 'day'], 'user_activity_days_user_day_unique');
            $table->index('day', 'user_activity_days_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_days');
    }
};
