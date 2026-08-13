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

            // Ownership attribution follows the same rule as every other
            // user reference: the owner becomes a tombstone, never a
            // deleted row, so nothing may silently drop the reference.
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->string('kind');
            $table->string('disk');
            $table->string('path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type');
            $table->string('extension')->nullable();
            $table->unsignedBigInteger('byte_size');

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            // Wide enough to hold the ratio of any two values width/height
            // can actually take (both unsignedInteger): avatars have no
            // dimension or aspect-ratio validation bounds, so the worst case
            // is as extreme as those columns allow, not just "unusual photo".
            $table->decimal('aspect_ratio', 16, 6)->nullable();
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
