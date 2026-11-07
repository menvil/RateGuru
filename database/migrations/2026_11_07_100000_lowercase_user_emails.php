<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lowercase every stored address that is not.
 *
 * Sign-in and password reset now compare the typed address lowercased, so an
 * address stored with capitals could no longer sign in. Registration and
 * profile updates already require lowercase and the admin editor stores it,
 * but the console's admin command did not: an address it created is lowered
 * here. One whose lowercase form another account already holds is left as it
 * is — two accounts are never merged or overwritten by a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->select(['id', 'email'])
            ->orderBy('id')
            ->chunkById(500, function (iterable $rows): void {
                foreach ($rows as $row) {
                    if (! is_string($row->email)) {
                        continue;
                    }

                    $lower = Str::lower($row->email);

                    // Another account than this one: where the collation ignores
                    // case, the row itself matches its lowercase form.
                    if ($lower === $row->email || DB::table('users')->where('email', $lower)->where('id', '!=', $row->id)->exists()) {
                        continue;
                    }

                    DB::table('users')->where('id', $row->id)->update(['email' => $lower]);
                }
            });
    }

    public function down(): void
    {
        // The original capitalisation is not kept, and the lowercase address
        // signs in either way: nothing to restore.
    }
};
