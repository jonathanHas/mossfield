<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Grandfather existing users past the new email-verification requirement
     * so they aren't locked out when User gains MustVerifyEmail. New accounts
     * created after this migration still have to verify.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Not safely reversible: we don't know which rows were backfilled.
    }
};
