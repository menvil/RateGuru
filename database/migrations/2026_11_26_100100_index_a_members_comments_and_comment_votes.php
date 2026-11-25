<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A person's comments and the votes they cast on comments, each counted for every row of Admin → Users
     * (ModerationUsersQuery): foreign keys PostgreSQL does not index by itself. MySQL and MariaDB index a foreign key
     * when they make it, so there these are already in place.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const INDEXES = [
        'comments' => ['comments_user_id_index' => ['user_id']],
        'comment_votes' => ['comment_votes_user_id_index' => ['user_id']],
    ];

    public function up(): void
    {
        if ($this->indexesForeignKeysItself()) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            // Building an index holds writes to its table: give up rather than queue every comment and vote behind a
            // long-running writer while a release migrates.
            DB::statement("set local lock_timeout = '5s'");
        }

        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $table) use ($indexes) {
                foreach ($indexes as $name => $columns) {
                    $table->index($columns, $name);
                }
            });
        }
    }

    public function down(): void
    {
        if ($this->indexesForeignKeysItself()) {
            return;
        }

        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $table) use ($indexes) {
                foreach (array_keys($indexes) as $name) {
                    $table->dropIndex($name);
                }
            });
        }
    }

    private function indexesForeignKeysItself(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
