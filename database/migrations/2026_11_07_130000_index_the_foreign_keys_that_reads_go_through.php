<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foreign keys PostgreSQL does not index by itself, each read by a page or
     * checked on every delete of the row it points at: a post's comments and
     * a comment's replies, the posts of a category or a tag, and who uses a
     * picture. MySQL and MariaDB index a foreign key when they make it, so
     * there these are already in place.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const INDEXES = [
        'comments' => [
            'comments_post_id_parent_id_index' => ['post_id', 'parent_id'],
            'comments_parent_id_index' => ['parent_id'],
        ],
        'posts' => [
            'posts_category_id_index' => ['category_id'],
            'posts_image_asset_id_index' => ['image_asset_id'],
        ],
        'users' => [
            'users_avatar_asset_id_index' => ['avatar_asset_id'],
        ],
        'post_tag' => [
            'post_tag_tag_id_index' => ['tag_id'],
        ],
    ];

    public function up(): void
    {
        if ($this->indexesForeignKeysItself()) {
            return;
        }

        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $table) use ($indexes) {
                foreach ($indexes as $name => $columns) {
                    $table->index($columns, $name);
                }
            });
        }
    }

    public function down(): void
    {
        if ($this->indexesForeignKeysItself()) {
            return;
        }

        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $table) use ($indexes) {
                foreach (array_keys($indexes) as $name) {
                    $table->dropIndex($name);
                }
            });
        }
    }

    private function indexesForeignKeysItself(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
