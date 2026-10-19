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
        // One future publication each, prepared in Content Studio. Not a post:
        // nothing here is on the site until the plan is approved and run.
        Schema::create('studio_plans', function (Blueprint $table) {
            $table->id();
            // Closed sets, checked by the database on every engine.
            $table->enum('status', ['draft', 'approved', 'running', 'completed', 'cancelled'])->default('draft');
            // The Persona the post will be published as. Accounts become
            // tombstones and are never deleted, so nothing may drop either
            // reference silently.
            $table->foreignId('author_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            // The photo, owned by the administrator who uploaded it. Restricted:
            // only the media lifecycle removes an asset, and it never removes
            // one a plan still points at (MediaReferenceChecker).
            $table->foreignId('image_asset_id')->nullable()->constrained('media_assets')->restrictOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('source_url', 2048)->nullable();
            // An incomplete draft may lose a category deleted meanwhile; the
            // plan is checked as a whole before it is approved.
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            // Lists of ids, kept as the post will take them.
            $table->json('tag_ids');
            $table->json('author_answer_option_ids');
            $table->enum('publication_mode', ['now', 'at_date'])->default('now');
            // A date, not a TIMESTAMP: a past publication date is as valid as
            // a future one.
            $table->dateTime('publish_at')->nullable();
            // Grows with every save, so a save made from an older copy is
            // refused instead of writing over a newer one.
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index('status');
            $table->index('author_user_id');
            $table->index('created_by_user_id');
            $table->index('image_asset_id');
            $table->index('category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('studio_plans');
    }
};
