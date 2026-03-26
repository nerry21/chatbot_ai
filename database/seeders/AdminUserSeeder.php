<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@chatbot.ai'],
            [
                'name'              => 'Admin',
                'email_verified_at' => now(),
                'password'          => Hash::make(env('LOGIN_PASSWORD', 'admin123')),
            ]
        );
    }
}
