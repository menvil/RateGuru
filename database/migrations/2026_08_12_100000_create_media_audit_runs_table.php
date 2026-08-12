<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_audit_runs', function (Blueprint $table) {
            $table->id();

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            // Plain string, not a native DB enum column — portable across
            // PostgreSQL/MariaDB/SQLite without driver-specific DDL, cast to
            // MediaAuditRunStatus in the model. Same convention media_assets/
            // media_variants already use for kind/status/visibility/name.
            $table->string('status');

            $table->unsignedInteger('assets_checked')->default(0);
            $table->unsignedInteger('variants_checked')->default(0);
            $table->unsignedInteger('healthy_assets')->default(0);
            $table->unsignedInteger('active_unreferenced_assets')->default(0);
            $table->unsignedInteger('soft_deleted_within_grace')->default(0);
            $table->unsignedInteger('purgeable_assets')->default(0);
            $table->unsignedInteger('missing_masters')->default(0);
            $table->unsignedInteger('missing_variant_files')->default(0);
            $table->unsignedInteger('physical_orphan_candidates')->default(0);
            $table->unsignedInteger('failed_media_jobs')->default(0);

            $table->timestamps();

            $table->index('status');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_audit_runs');
    }
};
