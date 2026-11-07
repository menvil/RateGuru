<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rating_groups', function (Blueprint $table) {
            $table->json('label_translations')->nullable()->after('label');
            $table->json('description_translations')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('rating_groups', function (Blueprint $table) {
            $table->dropColumn(['label_translations', 'description_translations']);
        });
    }
};
