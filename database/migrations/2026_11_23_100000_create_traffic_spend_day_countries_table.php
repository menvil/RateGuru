<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A network's impressions of a day by campaign, site and country, for
     * setting beside the visits of one country. Apart from
     * traffic_spend_days, which spend is shared out from: nothing here is
     * ever counted as spend. The cursor is how far back a connection has read
     * them, for the days its figures already held.
     */
    public function up(): void
    {
        Schema::create('traffic_spend_day_countries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('traffic_sources')->restrictOnDelete();
            $table->char('network_day', 10);
            $table->foreignId('campaign_id')->constrained('traffic_campaigns')->restrictOnDelete();
            $table->string('site_ref', 120)->default('');
            $table->char('country', 2);
            $table->unsignedBigInteger('impressions');
            $table->decimal('amount', 18, 8);
            $table->timestamps();

            $table->unique(['source_id', 'network_day', 'campaign_id', 'site_ref', 'country'], 'traffic_spend_day_countries_slice_unique');
            $table->index(['network_day', 'country'], 'traffic_spend_day_countries_day_index');
            $table->index('campaign_id', 'traffic_spend_day_countries_campaign_index');
        });

        Schema::table('traffic_api_connections', function (Blueprint $table) {
            $table->char('country_cursor', 10)->nullable()->after('backfill_cursor');
        });
    }

    public function down(): void
    {
        Schema::table('traffic_api_connections', function (Blueprint $table) {
            $table->dropColumn('country_cursor');
        });

        Schema::dropIfExists('traffic_spend_day_countries');
    }
};
