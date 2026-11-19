<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const array COLUMNS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    public function up(): void
    {
        // The campaign tags the session came by (App\Support\Analytics\UtmTags),
        // written on each of its visits as it is recorded and kept as long as
        // the visit, so a campaign named only by its tags is read from the
        // visit itself.
        Schema::table('traffic_visits', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->string($column, 120)->nullable();
            }

            $table->index(['utm_campaign', 'arrived_at'], 'traffic_visits_utm_campaign_index');
        });
    }

    public function down(): void
    {
        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->dropIndex('traffic_visits_utm_campaign_index');
        });

        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
