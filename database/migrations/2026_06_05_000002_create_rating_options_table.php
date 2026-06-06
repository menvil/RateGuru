<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rating_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rating_group_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['rating_group_id', 'key']);
            $table->unique(['id', 'rating_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_options');
    }
};
