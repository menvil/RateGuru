<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the attempt reached its flow's goal (at most once); the paid or
     * unpaid visit it was started under; and where its flow sent the visitor
     * on to — a post, the feed, a page or the next game — and when, which is
     * what a vote on that post or in the feed within 30 minutes is credited
     * by. Pointers without foreign keys: a visit is purged on its own
     * schedule, and a post by its own deletion rules.
     *
     * @var array<string, string>
     */
    private const array COLUMNS = [
        'goal_reached_at' => 'datetime',
        'traffic_visit_id' => 'integer',
        'onward_kind' => 'varchar(16)',
        'onward_post_id' => 'integer',
        'onward_at' => 'datetime',
    ];

    public function up(): void
    {
        // Added in place on SQLite: a table SQLite rebuilds loses the CHECKs
        // on its audience, status and account outcome.
        if (DB::getDriverName() === 'sqlite') {
            foreach (self::COLUMNS as $column => $type) {
                DB::statement('alter table "game_attempts" add column "'.$column.'" '.$type);
            }

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('game_attempts', function (Blueprint $table) {
            $table->dateTime('goal_reached_at')->nullable()->after('completed_at');
            $table->unsignedBigInteger('traffic_visit_id')->nullable()->after('assignment_id');
            $table->string('onward_kind', 16)->nullable()->after('goal_reached_at');
            $table->unsignedBigInteger('onward_post_id')->nullable()->after('onward_kind');
            $table->dateTime('onward_at')->nullable()->after('onward_post_id');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (array_reverse(array_keys(self::COLUMNS)) as $column) {
                DB::statement('alter table "game_attempts" drop column "'.$column.'"');
            }

            return;
        }

        Schema::table('game_attempts', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::COLUMNS));
        });
    }
};
