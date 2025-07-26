<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@osmanager.local'],
            [
                'name' => 'Admin',
                'username' => 'admin',
                'email' => 'admin@osmanager.local',
                'password' => Hash::make('admin123'),
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('Admin user created/updated:');
        $this->command->info('Username: admin');
        $this->command->info('Email: admin@osmanager.local');
        $this->command->info('Password: admin123');
    }
}
