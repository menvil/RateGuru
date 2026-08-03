<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('kind');
            $table->string('disk');
            $table->string('path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type');
            $table->string('extension')->nullable();
            $table->unsignedBigInteger('byte_size');

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            // Wider than a "normal" photo ratio ever needs, because avatars
            // have no width/height validation bounds and can carry extreme
            // aspect ratios that decimal(10,6) (max ~9999.999999) can't hold.
            $table->decimal('aspect_ratio', 12, 6)->nullable();
            $table->string('orientation')->nullable();

            $table->string('checksum_sha256')->nullable();

            $table->string('status');
            $table->string('visibility');
            $table->text('processing_error')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('kind');
            $table->index('status');
            $table->index('disk');
            $table->index('created_at');
            $table->unique(['disk', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
