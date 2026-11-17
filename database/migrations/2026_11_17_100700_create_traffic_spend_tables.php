<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A file of daily spend the administrator uploaded.
        Schema::create('traffic_spend_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('traffic_sources')->restrictOnDelete();
            $table->string('file_name', 190);
            $table->string('status', 16);
            $table->unsignedInteger('fact_rows')->default(0);
            $table->char('day_from', 10)->nullable();
            $table->char('day_to', 10)->nullable();
            $table->decimal('total', 18, 8)->nullable();
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->decimal('skipped_amount', 18, 8)->nullable();
            $table->string('error', 300)->nullable();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('source_id', 'traffic_spend_imports_source_index');
            $table->index('uploaded_by_user_id', 'traffic_spend_imports_uploaded_by_index');
        });

        // What a source spent on one of its days, by campaign, site or zone
        // where the figure says: read from the network's API, uploaded,
        // typed in, or a placement's fixed price for the day. Shared out over
        // the day's visits by traffic:allocate-spend; what finds no visit is
        // spend without visits. A day's figures from one origin are replaced
        // whole, so there is no unique key.
        Schema::create('traffic_spend_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('traffic_sources')->restrictOnDelete();
            $table->char('network_day', 10);
            // A growing set (TrafficSpendOrigin).
            $table->string('origin', 8);
            $table->foreignId('campaign_id')->nullable()->constrained('traffic_campaigns')->restrictOnDelete();
            $table->string('site_ref', 120)->default('');
            $table->string('zone_ref', 120)->default('');
            $table->decimal('amount', 18, 8);
            $table->decimal('allocated_amount', 18, 8)->default(0);
            // Whether this is the origin the day is counted from.
            $table->boolean('counted')->default(false);
            $table->unsignedInteger('matched_visits')->default(0);
            $table->unsignedInteger('reported_clicks')->nullable();
            $table->unsignedBigInteger('reported_impressions')->nullable();
            $table->foreignId('import_id')->nullable()->constrained('traffic_spend_imports')->cascadeOnDelete();
            $table->foreignId('entered_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('allocated_at')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'network_day', 'origin'], 'traffic_spend_days_source_day_index');
            $table->index(['campaign_id', 'network_day'], 'traffic_spend_days_campaign_day_index');
            $table->index('allocated_at', 'traffic_spend_days_allocated_index');
            $table->index('import_id', 'traffic_spend_days_import_index');
            $table->index('entered_by_user_id', 'traffic_spend_days_entered_by_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_spend_days');
        Schema::dropIfExists('traffic_spend_imports');
    }
};
