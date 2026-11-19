<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A player's votes since they left a game for the feed: three within
        // 30 minutes reach the flow's "voted in the feed" goal. Without it the
        // count reads the whole table.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('post_votes', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'post_votes_user_created_index');
        });
    }

    public function down(): void
    {
        // MySQL and MariaDB may have let the index the foreign key on user_id
        // made for itself go once this one could serve the key; the key then
        // needs an index of its own before this one can be dropped.
        $selfIndexing = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        $userIndexed = collect(Schema::getIndexes('post_votes'))
            ->contains(fn (array $index): bool => $index['columns'] === ['user_id']);

        Schema::table('post_votes', function (Blueprint $table) use ($selfIndexing, $userIndexed) {
            if ($selfIndexing && ! $userIndexed) {
                $table->index('user_id', 'post_votes_user_id_index');
            }

            $table->dropIndex('post_votes_user_created_index');
        });
    }
};
