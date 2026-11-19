<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On PostgreSQL a busy table fails the deploy before the release
        // switches instead of queueing the players' queries behind an ALTER.
        $this->limitLockWait();

        // A flow's goal (GameFlowGoal): what analytics, version comparison and
        // postbacks count for it. Null means the goal its steps give it.
        Schema::table('game_flows', function (Blueprint $table) {
            $table->string('goal', 40)->nullable()->after('name');
        });

        // The goal a version was published with, beside the copy frozen into
        // its snapshot, so analytics can group by it in SQL. Added in place on
        // SQLite: a table SQLite rebuilds loses the CHECK on its status.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('alter table "game_versions" add column "goal" varchar(40)');
        } else {
            Schema::table('game_versions', function (Blueprint $table) {
                $table->string('goal', 40)->nullable()->after('flow_id');
            });
        }

        // Versions published before goals existed get the goal their flow's
        // steps gave it — the rule written out here, as it stood, so a later
        // change of the application's rule never rewrites what this one did.
        // A version whose snapshot carries the goal it was published with
        // keeps that one.
        DB::table('game_versions')->whereNull('goal')->orderBy('id')->chunkById(100, function ($versions): void {
            foreach ($versions as $version) {
                DB::table('game_versions')->where('id', $version->id)->update(['goal' => $this->goalOf((string) $version->snapshot)]);
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('alter table "game_versions" drop column "goal"');
        } else {
            Schema::table('game_versions', function (Blueprint $table) {
                $table->dropColumn('goal');
            });
        }

        Schema::table('game_flows', function (Blueprint $table) {
            $table->dropColumn('goal');
        });
    }

    /**
     * The goal a snapshot was published with, when it carries one; otherwise
     * the goal the published flow's steps give it, for the eleven step types
     * that existed then.
     */
    private function goalOf(string $snapshot): string
    {
        $data = json_decode($snapshot, true);

        if (is_string($data['flow']['goal'] ?? null) && $data['flow']['goal'] !== '') {
            return $data['flow']['goal'];
        }

        $steps = is_array($data['flow']['steps'] ?? null) ? $data['flow']['steps'] : [];
        $types = array_map(fn (mixed $step): string => is_array($step) ? (string) ($step['type'] ?? '') : '', $steps);

        foreach (['registration' => 'account', 'next_game' => 'next_game', 'email_capture' => 'email', 'share' => 'shared', 'feed' => 'feed'] as $type => $goal) {
            if (in_array($type, $types, true)) {
                return $goal;
            }
        }

        foreach ($steps as $step) {
            if (is_array($step) && ($step['type'] ?? null) === 'full_result' && ($step['settings']['signup'] ?? 'inline') === 'inline') {
                return 'account';
            }
        }

        return 'completed';
    }

    private function limitLockWait(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }
    }
};
