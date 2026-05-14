<?php

use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\PostStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('image_path')->nullable();
            $table->string('image_url')->nullable();
            $table->string('thumbnail_url')->nullable();

            $table->string('source_url')->nullable();

            $table->string('status')->default(PostStatus::Pending->value);
            $table->string('origin_truth')->default(OriginType::Unknown->value);
            $table->string('cuisine_truth')->default(CuisineType::Unknown->value);

            $table->unsignedInteger('upvotes_count')->default(0);
            $table->unsignedInteger('downvotes_count')->default(0);
            $table->unsignedInteger('homemade_votes_count')->default(0);
            $table->unsignedInteger('restaurant_votes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('reports_count')->default(0);

            $table->float('hot_score')->default(0);

            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
