<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An administrator's own slice of a paid source's traffic, by the
        // parameters each of its clicks carries — site, zone, campaign,
        // creative, device, country (SegmentParameter) — as a list of
        // conditions that must all hold (SegmentRules). The parameters are
        // columns of every visit, so a segment counts the visits before it
        // was made too. Kept for good; deleting one only marks it, so Undo
        // can bring it back. Sources are never deleted and the people who
        // make segments are administrators, whose accounts become
        // tombstones: both references restrict.
        Schema::create('traffic_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('traffic_sources')->restrictOnDelete();
            $table->string('name', 120);
            $table->json('rules');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->dateTime('deleted_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            // A source's live segments, and the lookups each foreign key's delete check goes through.
            $table->index(['source_id', 'deleted_at'], 'traffic_segments_source_index');
            $table->index('created_by_user_id', 'traffic_segments_created_by_index');
            $table->index('updated_by_user_id', 'traffic_segments_updated_by_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_segments');
    }
};
