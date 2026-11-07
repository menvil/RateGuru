<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->integer('upvotes_count')->default(0)->after('reports_count');
            $table->integer('downvotes_count')->default(0)->after('upvotes_count');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn(['upvotes_count', 'downvotes_count']);
        });
    }
};
