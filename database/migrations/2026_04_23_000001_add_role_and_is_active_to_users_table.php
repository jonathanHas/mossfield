<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->nullable()->after('password');
            $table->boolean('is_active')->default(true)->after('role');
            // Email is optional so factory/drivers without a work email can have accounts.
            // The unique index on email still applies to non-null values.
            $table->string('email')->nullable()->change();
        });

        // Preserve access for any user that existed before the role column:
        // on the single-admin prod install this just promotes the seeded
        // admin user. On fresh installs this is a no-op (users table is empty).
        DB::table('users')->whereNull('role')->update(['role' => 'admin']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
