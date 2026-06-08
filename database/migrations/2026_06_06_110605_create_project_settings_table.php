<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_name', 120);
            $table->string('site_tagline', 180)->nullable();
            $table->text('site_description')->nullable();
            $table->string('object_singular_name', 80);
            $table->string('object_plural_name', 80);
            $table->string('upload_cta_label', 80);
            $table->string('feed_title', 120);
            $table->string('default_locale', 12)->default('en');
            $table->string('default_theme', 20)->default('system');
            $table->string('default_sort', 30)->default('hot');
            $table->string('active_preset_key', 80)->nullable();
            $table->json('feature_flags')->nullable()->default('[]');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_settings');
    }
};
