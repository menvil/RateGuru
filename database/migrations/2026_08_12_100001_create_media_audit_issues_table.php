<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_audit_issues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('media_audit_run_id')->constrained('media_audit_runs')->cascadeOnDelete();

            // Plain strings, not native DB enums — see media_audit_runs.status.
            $table->string('issue_type');
            $table->string('severity');

            // Deliberately no foreign key constraint on either id: an issue
            // row is a historical snapshot, and the asset/variant it names
            // may legitimately be purged (or, for a variant, regenerated —
            // a fresh row, hence a *different* id) long after this issue was
            // recorded. A constrained nullOnDelete()/cascadeOnDelete() would
            // either silently rewrite or delete that history; plain nullable
            // columns keep the snapshot intact and unconstrained.
            $table->unsignedBigInteger('media_asset_id')->nullable();
            $table->unsignedBigInteger('media_variant_id')->nullable();

            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->json('context')->nullable();

            $table->timestamps();

            $table->index('media_audit_run_id');
            $table->index('issue_type');
            $table->index('severity');
            $table->index('media_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_audit_issues');
    }
};
