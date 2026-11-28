<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_settings', function (Blueprint $table) {
            // The sites a link may not be imported from, as a list of domains (App\Support\Import\BlockedImportDomains).
            // Null until an administrator saves one: nothing is blocked. A column of its own, so applying a preset
            // never clears it.
            $table->json('blocked_import_domains')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_settings', function (Blueprint $table) {
            $table->dropColumn('blocked_import_domains');
        });
    }
};
