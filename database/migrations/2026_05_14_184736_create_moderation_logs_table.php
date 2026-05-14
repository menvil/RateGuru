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
        Schema::create('moderation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action');

            $table->string('target_type');
            $table->unsignedBigInteger('target_id');

            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['target_type', 'target_id']);
            $table->index('moderator_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_logs');
    }
};
