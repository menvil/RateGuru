<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The check of one text's language: a post's title and description, or a comment's body. What the local
        // detector said when the text was written, and what OpenAI said once languages:work asked it — the table is
        // that command's queue and the record the post languages are worked out from again. Implementation-owned:
        // a check means nothing without its text and goes with it (docs/architecture/community-fk-safety.md).
        Schema::create('content_language_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('comment_id')->nullable()->constrained()->cascadeOnDelete();
            // The local detector's answer and how much it counts: less for a text of only a few letters.
            $table->string('local_language', 12)->nullable();
            $table->decimal('local_weight', 4, 3)->nullable();
            // OpenAI's answer, null while it has not been asked, when it could tell no language, or when it failed.
            $table->string('ai_language', 12)->nullable();
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->string('status', 16)->default('pending');
            // Texts written now go before the ones a backfill queued.
            $table->string('priority', 16)->default('live');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('run_after');
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->unique('post_id', 'content_language_checks_post_unique');
            $table->unique('comment_id', 'content_language_checks_comment_unique');
            $table->index(['status', 'priority', 'run_after'], 'content_language_checks_queue_index');
            $table->index('finished_at', 'content_language_checks_finished_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_language_checks');
    }
};
