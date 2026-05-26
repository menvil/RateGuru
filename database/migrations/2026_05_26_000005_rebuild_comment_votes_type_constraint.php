<?php

use Illuminate\Database\Migrations\Migration;
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

        DB::statement(<<<'SQL'
            INSERT INTO comment_votes_constrained (id, comment_id, user_id, type, created_at, updated_at)
            SELECT id, comment_id, user_id, type, created_at, updated_at
            FROM comment_votes
            WHERE type IN ('up', 'down')
        SQL);

        Schema::drop('comment_votes');
        Schema::rename('comment_votes_constrained', 'comment_votes');

        DB::statement('CREATE UNIQUE INDEX comment_votes_comment_id_user_id_unique ON comment_votes (comment_id, user_id)');
    }

    public function down(): void
    {
        // The previous schema used the same columns without a type check.
        // Leaving the constrained table in place is safer than rebuilding
        // it to accept invalid vote types.
    }
};
