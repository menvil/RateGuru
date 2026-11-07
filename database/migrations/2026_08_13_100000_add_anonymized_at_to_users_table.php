<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Set exactly once, when account anonymization commits. A
            // non-null value marks the row as an irreversible tombstone —
            // this is deliberately NOT a SoftDeletes deleted_at: the row is
            // never hidden from queries and can never be "restored".
            $table->timestamp('anonymized_at')->nullable()->after('avatar_asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });
    }
};
