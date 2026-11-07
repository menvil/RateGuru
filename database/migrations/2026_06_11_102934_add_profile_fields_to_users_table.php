<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('display_name', 80)->nullable()->after('name');
            $table->text('bio')->nullable()->after('display_name');
            $table->string('avatar_path')->nullable()->after('avatar_url');
            $table->string('profile_website_url')->nullable()->after('avatar_path');
            $table->string('rating_activity_visibility', 30)->default('private')->after('profile_website_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'display_name',
                'bio',
                'avatar_path',
                'profile_website_url',
                'rating_activity_visibility',
            ]);
        });
    }
};
