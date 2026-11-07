<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // What Content Studio keeps about a Persona for itself: never shown on
        // the site, never part of the account's public profile, which stays in
        // `users` like anyone's.
        Schema::create('persona_profiles', function (Blueprint $table) {
            $table->id();
            // One profile per account. Removed explicitly by account
            // anonymization, never via FK cascade: users must not be
            // hard-deletable at the DB level at all.
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('short_description', 500)->nullable();
            $table->string('character', 2000)->nullable();
            $table->string('speech_style', 2000)->nullable();
            $table->string('topics', 2000)->nullable();
            $table->string('never_writes_about', 2000)->nullable();
            // Closed sets, checked by the database on every engine.
            $table->enum('emoji_frequency', ['none', 'occasional', 'frequent'])->default('none');
            $table->enum('engagement', ['off', 'low', 'normal', 'high'])->default('off');
            $table->unsignedTinyInteger('max_replies_per_day')->default(0);
            // Who made it. The account outlives any edit of its creator; only
            // a physical delete of that row would clear the reference.
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('persona_profiles');
    }
};
