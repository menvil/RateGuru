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
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();

            $table->string('target_type');
            $table->unsignedBigInteger('target_id');

            $table->string('reason');
            $table->text('message')->nullable();

            $table->string('status')->default('open');

            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['target_type', 'target_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
