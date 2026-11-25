<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('posts', function (Blueprint $table) {
            // The language the post is written in, worked out from its text, its author's language, its comments and
            // the languages of those who voted on it (App\Support\Languages\LanguageProfile): the one holding at least
            // half the weight, or null when none does. A viewer's feed puts fresh posts in their own language first.
            $table->string('language', 12)->nullable();
            $table->decimal('language_share', 4, 3)->nullable();
            // Set in the one save a vote or a comment makes of the post: its language is worked out again in the
            // background (languages:work), never in that transaction, which may update the post only once.
            $table->dateTime('language_stale_at')->nullable();
            $table->index(['status', 'language', 'published_at'], 'posts_status_language_published_index');
        });

        Schema::table('comments', function (Blueprint $table) {
            // The language the comment is written in, or null when its text tells none ("wow", an emoji, a name):
            // a thread shows the viewer's own language first, and the post's language counts its comments.
            $table->string('language', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn('language');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_status_language_published_index');
            $table->dropColumn(['language', 'language_share', 'language_stale_at']);
        });
    }
};
