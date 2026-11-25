<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether a picture or video is safe for work (docs/architecture/
        // content-rating.md): unknown until a classifier or a moderator says.
        // The post carries its media's rating too, indexed, for the viewer's
        // SFW mode to filter on. Constant defaults and nullable columns are
        // added in place, waited for briefly, never behind a long write.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('content_rating')->default('unknown');
            // The score per category the deciding classifiers gave, kept for
            // the finer decisions to come without classifying again.
            $table->json('content_scores')->nullable();
            $table->string('content_rating_source')->nullable();
            $table->timestamp('content_rated_at')->nullable();
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->string('content_rating')->default('unknown');
            $table->index('content_rating');
        });

        Schema::create('media_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            // Which classifier, which model of it, and why it ran: shadow
            // (recorded, deciding nothing), active (deciding), benchmark.
            $table->string('classifier', 64);
            $table->string('model_version', 64)->nullable();
            $table->string('mode', 16);
            $table->string('status', 16);
            // What it was shown — a variant's name, a video's frame times.
            $table->json('inputs')->nullable();
            $table->json('scores')->nullable();
            $table->json('frame_scores')->nullable();
            // The provider's own answer, trimmed: for looking into a decision.
            $table->json('raw')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('cost_microusd')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['media_asset_id', 'classifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_classifications');

        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['content_rating']);
            $table->dropColumn('content_rating');
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropColumn(['content_rating', 'content_scores', 'content_rating_source', 'content_rated_at']);
        });
    }
};
