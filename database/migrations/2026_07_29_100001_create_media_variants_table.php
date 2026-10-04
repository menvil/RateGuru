<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_variants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();

            $table->string('name');
            $table->string('disk');
            $table->string('path');
            $table->string('mime_type');
            $table->string('extension')->nullable();
            $table->unsignedBigInteger('byte_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('checksum_sha256')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['media_asset_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_variants');
    }
};
