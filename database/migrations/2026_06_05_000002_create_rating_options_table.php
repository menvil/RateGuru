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
            // Deliberate cascade: options are configuration owned by their
            // group, not user content. History is protected one level down —
            // rating_votes/post_author_answers restrict on both the group
            // and the option, so a group with any recorded votes/answers
            // cannot be deleted at all (archive it instead).
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
