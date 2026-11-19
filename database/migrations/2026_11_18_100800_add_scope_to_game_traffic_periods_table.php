<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whom a period is for: everyone — the game's general split, both
        // columns empty — or the paid clicks of one traffic source, or of one
        // of its campaigns. Sources and campaigns are never deleted (a
        // campaign is archived), so the reference restricts.
        //
        // `open_scope` names the scope while the period is open — `everyone`,
        // `source:{id}` or `campaign:{id}` — and is cleared when it ends: the
        // unique index then allows one open period per game, audience and
        // scope and any number of ended ones, on every engine (unique indexes
        // let NULLs repeat).
        if (DB::getDriverName() === 'sqlite') {
            // Added in place, references and all: a table SQLite rebuilds
            // loses the CHECK on its audience.
            DB::statement('alter table "game_traffic_periods" add column "source_id" integer references "traffic_sources" ("id") on delete restrict');
            DB::statement('alter table "game_traffic_periods" add column "campaign_id" integer references "traffic_campaigns" ("id") on delete restrict');
            DB::statement('alter table "game_traffic_periods" add column "open_scope" varchar(40)');
        } else {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("set local lock_timeout = '5s'");
            }

            Schema::table('game_traffic_periods', function (Blueprint $table) {
                $table->foreignId('source_id')->nullable()->after('audience')->constrained('traffic_sources')->restrictOnDelete();
                $table->foreignId('campaign_id')->nullable()->after('source_id')->constrained('traffic_campaigns')->restrictOnDelete();
                $table->string('open_scope', 40)->nullable()->after('hidden_at');
            });
        }

        // The open period each game and audience has now — the latest, as the
        // game has always read it — is everyone's.
        $open = DB::table('game_traffic_periods')
            ->whereNull('ended_at')
            ->groupBy('game_id', 'audience')
            ->selectRaw('MAX(id) AS id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        foreach (array_chunk($open, 500) as $ids) {
            DB::table('game_traffic_periods')->whereIn('id', $ids)->update(['open_scope' => 'everyone']);
        }

        Schema::table('game_traffic_periods', function (Blueprint $table) {
            $table->unique(['game_id', 'audience', 'open_scope'], 'game_traffic_periods_open_scope_unique');
            // A delete of a source or campaign checks its periods (docs/architecture/community-fk-safety.md).
            $table->index('source_id', 'game_traffic_periods_source_index');
            $table->index('campaign_id', 'game_traffic_periods_campaign_index');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('game_traffic_periods', function (Blueprint $table) {
                $table->dropIndex('game_traffic_periods_campaign_index');
                $table->dropIndex('game_traffic_periods_source_index');
                $table->dropUnique('game_traffic_periods_open_scope_unique');
            });

            // Dropped in place too, for the same reason.
            DB::statement('alter table "game_traffic_periods" drop column "open_scope"');
            DB::statement('alter table "game_traffic_periods" drop column "campaign_id"');
            DB::statement('alter table "game_traffic_periods" drop column "source_id"');

            return;
        }

        Schema::table('game_traffic_periods', function (Blueprint $table) {
            // The foreign keys first: MariaDB holds an index a foreign key uses.
            $table->dropForeign(['campaign_id']);
            $table->dropForeign(['source_id']);
            $table->dropUnique('game_traffic_periods_open_scope_unique');
            $table->dropIndex('game_traffic_periods_campaign_index');
            $table->dropIndex('game_traffic_periods_source_index');
            $table->dropColumn(['open_scope', 'campaign_id', 'source_id']);
        });
    }
};
