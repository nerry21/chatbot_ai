<?php

namespace Tests\Feature\Api;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\StatusUpdate;
use App\Models\StatusUpdateView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminMobileStatusUpdateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_mobile_can_list_active_status_updates_feed(): void
    {
        Storage::fake('public');

        $admin = $this->createAdmin();
        $token = $this->loginAdmin($admin);
        [, $viewer] = $this->createWhatsAppConversation();

        $newest = StatusUpdate::query()->create([
            'user_id' => $admin->id,
            'author_type' => 'admin',
            'status_type' => 'text',
            'text' => 'Promo akhir pekan',
            'background_color' => '#25D366',
            'text_color' => '#FFFFFF',
            'font_style' => 'default',
            'audience_scope' => 'contacts_and_chatters',
            'is_active' => true,
            'posted_at' => now(),
            'expires_at' => now()->addHours(12),
        ]);

        StatusUpdate::query()->create([
            'user_id' => $admin->id,
            'author_type' => 'admin',
            'status_type' => 'music',
            'text' => 'Playlist admin',
            'music_meta' => ['title' => 'Sunrise', 'artist' => 'Jet Band'],
            'background_color' => '#F6F6F6',
            'text_color' => '#111111',
            'font_style' => 'default',
            'audience_scope' => 'contacts_and_chatters',
            'is_active' => true,
            'posted_at' => now()->subMinute(),
            'expires_at' => now()->addHours(6),
        ]);

        StatusUpdate::query()->create([
            'user_id' => $admin->id,
            'author_type' => 'admin',
            'status_type' => 'text',
            'text' => 'Status lama',
            'audience_scope' => 'contacts_and_chatters',
            'is_active' => true,
            'posted_at' => now()->subDays(2),
            'expires_at' => now()->subHour(),
        ]);

        StatusUpdateView::query()->create([
            'status_update_id' => $newest->id,
            'customer_id' => $viewer->id,
            'viewed_at' => now()->subMinutes(2),
        ]);

        $response = $this->withToken($token)
            ->getJson(route('api.admin-mobile.status-updates.index'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.audience_summary.eligible_viewers', 1)
            ->assertJsonPath('data.audience_summary.rule', 'contacts_and_chatters')
            ->assertJsonPath('data.my_statuses.0.id', $newest->id)
            ->assertJsonPath('data.my_statuses.0.view_count', 1)
            ->assertJsonCount(2, 'data.my_statuses');
    }

    public function test_admin_mobile_can_create_text_status_update(): void
    {
        $admin = $this->createAdmin([
            'email' => 'status-text@example.com',
        ]);
        $token = $this->loginAdmin($admin);
        $this->createWhatsAppConversation();

        $response = $this->withToken($token)->postJson(
            route('api.admin-mobile.status-updates.store'),
            [
                'status_type' => 'text',
                'text' => 'Armada jalan tepat waktu hari ini.',
                'background_color' => '#7EC8A5',
                'text_color' => '#FFFFFF',
                'font_style' => 'default',
            ],
        );

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status.author_name', $admin->name)
            ->assertJsonPath('data.status.status_type', 'text')
            ->assertJsonPath('data.status.text', 'Armada jalan tepat waktu hari ini.')
            ->assertJsonPath('data.audience_summary.eligible_viewers', 1);

        $this->assertDatabaseHas('status_updates', [
            'user_id' => $admin->id,
            'author_type' => 'admin',
            'status_type' => 'text',
            'text' => 'Armada jalan tepat waktu hari ini.',
        ]);
    }

    public function test_admin_mobile_can_upload_image_status_and_view_detail(): void
    {
        Storage::fake('public');

        $admin = $this->createAdmin([
            'email' => 'status-image@example.com',
        ]);
        $token = $this->loginAdmin($admin);
        [, $viewer] = $this->createWhatsAppConversation();

        $response = $this->withToken($token)
            ->withHeader('Accept', 'application/json')
            ->post(route('api.admin-mobile.status-updates.store'), [
                'status_type' => 'image',
                'caption' => 'Suasana keberangkatan pagi ini.',
                'media_file' => UploadedFile::fake()->image('status.jpg', 1080, 1350),
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status.status_type', 'image')
            ->assertJsonPath('data.status.media_original_name', 'status.jpg');

        /** @var StatusUpdate $status */
        $status = StatusUpdate::query()->latest('id')->firstOrFail();

        Storage::disk('public')->assertExists((string) $status->media_path);

        StatusUpdateView::query()->create([
            'status_update_id' => $status->id,
            'customer_id' => $viewer->id,
            'viewed_at' => now()->subMinute(),
        ]);

        $detail = $this->withToken($token)
            ->getJson(route('api.admin-mobile.status-updates.show', ['statusUpdate' => $status]));

        $detail->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status.id', $status->id)
            ->assertJsonPath('data.status.view_count', 1)
            ->assertJsonPath('data.view_summary.total_views', 1)
            ->assertJsonPath('data.view_summary.recent_viewers.0.customer_id', $viewer->id);
    }

    private function createAdmin(array $attributes = []): User
    {
        $index = User::query()->count() + 1;

        return User::factory()->create(array_merge([
            'name' => 'Admin Status '.$index,
            'email' => "admin-status{$index}@example.com",
            'password' => Hash::make('super-secret'),
            'is_chatbot_admin' => true,
            'is_chatbot_operator' => false,
        ], $attributes));
    }

    private function loginAdmin(User $user, string $password = 'super-secret'): string
    {
        $response = $this->postJson(route('api.admin-mobile.auth.login'), [
            'email' => $user->email,
            'password' => $password,
            'device_name' => 'QA Status Mobile',
            'device_id' => 'qa-status-mobile-'.$user->id,
        ]);

        $response->assertOk();

        return (string) $response->json('data.access_token');
    }

    /**
     * @return array{0: Conversation, 1: Customer}
     */
    private function createWhatsAppConversation(array $conversationAttributes = [], array $customerAttributes = []): array
    {
        $index = Customer::query()->count() + 1;

        $customer = Customer::query()->create(array_merge([
            'name' => 'Customer Status '.$index,
            'phone_e164' => '+6281234501'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'email' => "status-customer{$index}@example.com",
            'status' => 'active',
        ], $customerAttributes));

        $conversation = Conversation::query()->create(array_merge([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'needs_human' => false,
            'bot_paused' => false,
            'started_at' => now()->subMinutes(10),
            'last_message_at' => now()->subMinute(),
            'source_app' => 'web-dashboard',
        ], $conversationAttributes));

        return [$conversation, $customer];
    }
}
