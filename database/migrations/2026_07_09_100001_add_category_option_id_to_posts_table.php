<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Author-chosen feed category: an option of the first active rating
            // group (the one the sidebar "Categories" block is built from).
            $table->foreignId('category_option_id')
                ->nullable()
                ->after('cuisine_truth')
                ->constrained('rating_options')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_option_id');
        });
    }
};
