<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const array COUNTERS = ['votes', 'comments', 'posts_created'];

    public function up(): void
    {
        // What a member did on the visit: the new votes they cast, the
        // comments and replies they wrote, the posts they made
        // (RecordUserActivityAction). Kept as long as the visit.
        Schema::table('traffic_visits', function (Blueprint $table) {
            foreach (self::COUNTERS as $column) {
                $table->unsignedSmallInteger($column)->default(0);
            }
        });

        // The posts a visit opened, shared, voted on or commented on, one row
        // a post (RecordVisitPostAction): how often it was opened, when it was
        // first shared, whether it was voted on or commented on, and when the
        // row began. A browser's history of what it looked at, so kept 90 days
        // from first_at (traffic:purge-expired), and gone with its visit.
        Schema::create('traffic_visit_posts', function (Blueprint $table) {
            $table->foreignId('visit_id')->constrained('traffic_visits')->cascadeOnDelete();
            // A post by id, never a hold on it: a post leaves by its own deletion rules.
            $table->unsignedBigInteger('post_id');
            $table->unsignedSmallInteger('views')->default(0);
            $table->dateTime('shared_at')->nullable();
            $table->boolean('voted')->default(false);
            $table->boolean('commented')->default(false);
            $table->dateTime('first_at');

            // Led by visit_id: the index the foreign key's lookups go through too.
            $table->primary(['visit_id', 'post_id']);
            $table->index(['post_id', 'visit_id'], 'traffic_visit_posts_post_index');
            $table->index('first_at', 'traffic_visit_posts_first_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_visit_posts');

        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->dropColumn(self::COUNTERS);
        });
    }
};
