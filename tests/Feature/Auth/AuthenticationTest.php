<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAIL = 'nerrypopindo@gmail.com';

    private const ADMIN_PASSWORD = '210511cddfl';

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200)
            ->assertSee(self::ADMIN_EMAIL);
    }

    public function test_configured_admin_can_authenticate_using_the_login_screen(): void
    {
        $response = $this->post('/login', [
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('admin.chatbot.live-chats.index', absolute: false));
        $this->assertSame(self::ADMIN_EMAIL, auth()->user()?->email);
        $this->assertTrue((bool) auth()->user()?->is_chatbot_admin);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $this->post('/login', [
            'email' => self::ADMIN_EMAIL,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_non_configured_email_can_not_authenticate(): void
    {
        User::factory()->create([
            'email' => 'other-admin@example.com',
            'is_chatbot_admin' => true,
        ]);

        $this->post('/login', [
            'email' => 'other-admin@example.com',
            'password' => self::ADMIN_PASSWORD,
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
