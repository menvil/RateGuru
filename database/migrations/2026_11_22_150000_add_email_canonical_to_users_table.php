<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The mailbox behind a Gmail address, for telling another spelling of it
     * from a new person. Not unique: accounts that already hold two spellings
     * of one mailbox stay as they are, and Google sign-in may create another.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_canonical')->nullable()->after('email')->index();
        });

        $this->backfill();
    }

    /** Separate from the schema change so it can be run on its own in a test transaction. */
    public function backfill(): void
    {
        DB::table('users')
            ->select(['id', 'email'])
            ->where(fn ($gmail) => $gmail
                ->whereRaw('LOWER(email) LIKE ?', ['%@gmail.com'])
                ->orWhereRaw('LOWER(email) LIKE ?', ['%@googlemail.com']))
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    // Written out rather than calling the application's class:
                    // a migration has to keep meaning what it meant when it ran.
                    $email = strtolower(trim((string) $user->email));
                    $local = substr($email, 0, (int) strrpos($email, '@'));
                    $mailbox = str_replace('.', '', explode('+', $local, 2)[0]);

                    if ($mailbox !== '') {
                        DB::table('users')->where('id', $user->id)->update(['email_canonical' => $mailbox.'@gmail.com']);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['email_canonical']);
            $table->dropColumn('email_canonical');
        });
    }
};
