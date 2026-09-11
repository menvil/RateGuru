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
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            // Removed explicitly by account anonymization, never via FK
            // cascade: users must not be hard-deletable at the DB level at all.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_user_id');
            $table->timestamps();

            // The external identity itself is the key: one provider subject
            // belongs to at most one account, whatever two racing callbacks
            // believed a moment earlier...
            $table->unique(['provider', 'provider_user_id']);
            // ...and one account holds at most one identity per provider — a
            // second Google identity is a conflict, never a silent replacement.
            $table->unique(['user_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
