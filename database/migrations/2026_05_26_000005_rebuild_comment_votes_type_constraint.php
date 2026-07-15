<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite' || ! Schema::hasTable('comment_votes')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE TABLE comment_votes_constrained (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                comment_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                type VARCHAR NOT NULL CHECK (type IN ('up', 'down')),
                created_at DATETIME,
                updated_at DATETIME,
                FOREIGN KEY(comment_id) REFERENCES comments(id) ON DELETE CASCADE,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        SQL);

        DB::table('comment_votes_constrained')->insertUsing(
            ['id', 'comment_id', 'user_id', 'type', 'created_at', 'updated_at'],
            DB::table('comment_votes')
                ->select(['id', 'comment_id', 'user_id', 'type', 'created_at', 'updated_at'])
                ->whereIn('type', ['up', 'down'])
        );

        Schema::drop('comment_votes');
        Schema::rename('comment_votes_constrained', 'comment_votes');

        Schema::table('comment_votes', function (Blueprint $table) {
            $table->unique(['comment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite' || ! Schema::hasTable('comment_votes')) {
            return;
        }

        Schema::create('comment_votes_unconstrained', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('comment_id');
            $table->unsignedBigInteger('user_id');
            $table->string('type');
            $table->timestamps();

            $table->foreign('comment_id')->references('id')->on('comments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        DB::table('comment_votes_unconstrained')->insertUsing(
            ['id', 'comment_id', 'user_id', 'type', 'created_at', 'updated_at'],
            DB::table('comment_votes')->select(['id', 'comment_id', 'user_id', 'type', 'created_at', 'updated_at'])
        );

        Schema::drop('comment_votes');
        Schema::rename('comment_votes_unconstrained', 'comment_votes');

        Schema::table('comment_votes', function (Blueprint $table) {
            $table->unique(['comment_id', 'user_id']);
        });
    }
};
