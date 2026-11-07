<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_settings', function (Blueprint $table) {
            $table->json('site_name_translations')->nullable()->after('site_name');
            $table->json('site_tagline_translations')->nullable()->after('site_tagline');
            $table->json('site_description_translations')->nullable()->after('site_description');
            $table->json('object_singular_name_translations')->nullable()->after('object_singular_name');
            $table->json('object_plural_name_translations')->nullable()->after('object_plural_name');
            $table->json('upload_cta_label_translations')->nullable()->after('upload_cta_label');
            $table->json('feed_title_translations')->nullable()->after('feed_title');
        });
    }

    public function down(): void
    {
        Schema::table('project_settings', function (Blueprint $table) {
            $table->dropColumn([
                'site_name_translations',
                'site_tagline_translations',
                'site_description_translations',
                'object_singular_name_translations',
                'object_plural_name_translations',
                'upload_cta_label_translations',
                'feed_title_translations',
            ]);
        });
    }
};
