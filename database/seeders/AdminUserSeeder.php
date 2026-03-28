<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('chatbot.security.console_login.email', 'nerrypopindo@gmail.com');
        $password = (string) config('chatbot.security.console_login.password', '210511cddfl');
        $name = (string) config('chatbot.security.console_login.name', 'Nerry Popindo');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name'              => $name,
                'email_verified_at' => now(),
                'password'          => Hash::make($password),
                'is_chatbot_admin'  => true,
                'is_chatbot_operator' => false,
            ]
        );
    }
}
