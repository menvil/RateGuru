<?php

use App\Enums\TrafficChannel;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\CampaignKey;
use App\Support\Analytics\LandingRoute;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const array COLUMNS = ['entry_visit_id', 'source_key', 'campaign_key', 'creative_key', 'landing_key'];

    public function up(): void
    {
        // The visit each visit's session began with, and where that one came
        // from — its source, campaign, creative and landing page — copied on
        // every visit of the session as it is recorded (OpenTrafficVisitAction),
        // so Analytics groups and counts people by a column of the visit
        // itself. No foreign key: a purged visit leaves the ids pointing at it
        // as they were.
        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->unsignedBigInteger('entry_visit_id')->nullable();
            $table->string('source_key', 140)->nullable();
            $table->string('campaign_key', 140)->nullable();
            $table->string('creative_key', 140)->nullable();
            $table->string('landing_key', 60)->nullable();

            $table->index('entry_visit_id', 'traffic_visits_entry_index');
            $table->index(['source_key', 'arrived_at'], 'traffic_visits_source_key_index');
            $table->index(['campaign_key', 'arrived_at'], 'traffic_visits_campaign_key_index');
        });

        // The visits recorded before sessions each begin their own, read by
        // what the visit itself kept: a paid click by its source and
        // campaign, an unpaid visit by its channel and origin, the landing as
        // its game's page — the route it was drawn by was never kept.
        DB::table('traffic_visits')
            ->whereNull('entry_visit_id')
            ->select(['id', 'channel', 'source_id', 'campaign_id', 'creative_ref', 'origin', 'game_id', 'version_id'])
            ->chunkById(500, function ($visits): void {
                foreach ($visits as $visit) {
                    $gameId = $visit->game_id === null ? null : (int) $visit->game_id;

                    DB::table('traffic_visits')->where('id', $visit->id)->update([
                        'entry_visit_id' => $visit->id,
                        'source_key' => AnalyticsSource::keyFor(
                            TrafficChannel::tryFrom((string) $visit->channel) ?? TrafficChannel::Direct,
                            $visit->source_id === null ? null : (int) $visit->source_id,
                            $visit->origin === null ? null : (string) $visit->origin,
                        ),
                        'campaign_key' => CampaignKey::paid($visit->campaign_id === null ? null : (int) $visit->campaign_id),
                        'creative_key' => $visit->creative_ref,
                        'landing_key' => LandingRoute::key(
                            $gameId === null ? null : 'games.play',
                            $gameId,
                            $visit->version_id === null ? null : (int) $visit->version_id,
                        ),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->dropIndex('traffic_visits_entry_index');
            $table->dropIndex('traffic_visits_source_key_index');
            $table->dropIndex('traffic_visits_campaign_key_index');
        });

        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
