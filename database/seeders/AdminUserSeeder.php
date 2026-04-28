<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('AdminUserSeeder refuses to run in production. Create admin users manually.');

            return;
        }

        $password = env('ADMIN_SEED_PASSWORD', 'admin123');

        User::updateOrCreate(
            ['email' => 'admin@osmanager.local'],
            [
                'name' => 'Admin',
                'username' => 'admin',
                'email' => 'admin@osmanager.local',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'role' => UserRole::Admin->value,
                'is_active' => true,
            ]
        );

        // Dev-only role test accounts. Shared password matches admin for easy switching.
        // These seed rows are dropped on migrate:fresh; re-run this seeder to recreate.
        $testers = [
            ['name' => 'Office Tester', 'username' => 'office_test', 'email' => 'office@osmanager.local', 'role' => UserRole::Office],
            ['name' => 'Factory Tester', 'username' => 'factory_test', 'email' => null, 'role' => UserRole::Factory],
            ['name' => 'Driver Tester', 'username' => 'driver_test', 'email' => null, 'role' => UserRole::Driver],
        ];

        foreach ($testers as $t) {
            User::updateOrCreate(
                ['username' => $t['username']],
                [
                    'name' => $t['name'],
                    'username' => $t['username'],
                    'email' => $t['email'],
                    'password' => Hash::make($password),
                    'email_verified_at' => $t['email'] ? now() : null,
                    'role' => $t['role']->value,
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('Dev users seeded (all share password: '.($password === 'admin123' ? 'admin123' : '[ADMIN_SEED_PASSWORD]').'):');
        $this->command->info('  admin        — UserRole::Admin');
        $this->command->info('  office_test  — UserRole::Office');
        $this->command->info('  factory_test — UserRole::Factory (no email)');
        $this->command->info('  driver_test  — UserRole::Driver (no email)');
    }
}
