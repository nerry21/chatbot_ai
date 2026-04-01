<?php

namespace Tests\Feature\Api;

use App\Enums\BookingStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\AdminNote;
use App\Models\AuditLog;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationState;
use App\Models\ConversationTag;
use App\Models\Customer;
use App\Models\CustomerTag;
use App\Models\Escalation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminMobileReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_user_cannot_login_admin_mobile(): void
    {
        User::factory()->create([
            'name' => 'Regular User',
            'email' => 'regular@example.com',
            'password' => Hash::make('super-secret'),
            'is_chatbot_admin' => false,
            'is_chatbot_operator' => false,
        ]);

        $response = $this->postJson(route('api.admin-mobile.auth.login'), [
            'email' => 'regular@example.com',
            'password' => 'super-secret',
            'device_name' => 'Unauthorized Device',
            'device_id' => 'unauthorized-device',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Akun ini tidak memiliki akses admin mobile.');
    }

    public function test_admin_mobile_login_me_and_logout_flow(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin Omnichannel',
            'email' => 'admin@example.com',
            'password' => Hash::make('super-secret'),
            'is_chatbot_admin' => true,
        ]);

        $login = $this->postJson(route('api.admin-mobile.auth.login'), [
            'email' => 'admin@example.com',
            'password' => 'super-secret',
            'device_name' => 'iPhone Admin',
            'device_id' => 'ios-admin-001',
        ]);

        $login->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'access_token',
                    'token_type',
                    'user',
                ],
            ]);

        $token = (string) $login->json('data.access_token');

        $me = $this->withToken($token)
            ->getJson(route('api.admin-mobile.auth.me'));

        $me->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'admin@example.com')
            ->assertJsonPath('data.user.can_access_chatbot_admin', true);

        $logout = $this->withToken($token)
            ->postJson(route('api.admin-mobile.auth.logout'));

        $logout->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Logout admin mobile berhasil.');

        $meAfterLogout = $this->withToken($token)
            ->getJson(route('api.admin-mobile.auth.me'));

        $meAfterLogout->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_admin_mobile_read_api_returns_workspace_list_detail_poll_dashboard_and_filters(): void
    {
        $admin = User::factory()->create([
            'name' => 'Nadia Admin',
            'email' => 'nadia@example.com',
            'password' => Hash::make('super-secret'),
            'is_chatbot_admin' => true,
        ]);

        $token = $this->loginAdmin($admin, 'super-secret');

        [$bookingConversation, $bookingCustomer, $afterMessageId] = $this->seedWorkspaceConversation($admin);
        $this->seedClosedConversation();

        $workspace = $this->withToken($token)->getJson(route('api.admin-mobile.workspace', [
            'scope' => 'booking_in_progress',
            'search' => 'Pekanbaru',
            'selected_conversation_id' => $bookingConversation->id,
        ]));

        $workspace->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary_counts.scope_totals.booking_in_progress', 1)
            ->assertJsonPath('data.conversation_list.items.0.id', $bookingConversation->id)
            ->assertJsonPath('data.conversation_list.sort.by', 'last_message_at')
            ->assertJsonPath('data.conversation_list.sort.direction', 'desc')
            ->assertJsonPath('data.selected_conversation.id', $bookingConversation->id)
            ->assertJsonPath('data.selected_conversation.customer.id', $bookingCustomer->id)
            ->assertJsonPath('data.insight_pane.conversation_tags.0.tag', 'vip')
            ->assertJsonPath('data.insight_pane.customer_tags.0.tag', 'gold')
            ->assertJsonPath('data.insight_pane.quick_details.channel.label', 'WhatsApp')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'summary_counts',
                    'filters_meta',
                    'conversation_list' => [
                        'items',
                        'pagination',
                        'selected_conversation_id',
                        'refreshed_at',
                    ],
                    'selected_conversation',
                    'messages',
                    'thread_groups',
                    'insight_pane',
                ],
            ]);

        $conversations = $this->withToken($token)->getJson(route('api.admin-mobile.conversations.index', [
            'scope' => 'unread',
            'search' => 'booking',
        ]));

        $conversations->assertOk()
            ->assertJsonPath('data.conversation_list.items.0.id', $bookingConversation->id)
            ->assertJsonPath('data.summary_counts.scope_totals.unread', 1);

        $detail = $this->withToken($token)
            ->getJson(route('api.admin-mobile.conversations.show', [
                'conversation' => $bookingConversation,
            ]));

        $detail->assertOk()
            ->assertJsonPath('data.selected_conversation.id', $bookingConversation->id)
            ->assertJsonPath('data.insight_pane.latest_escalation.status', 'open');

        $messages = $this->withToken($token)
            ->getJson(route('api.admin-mobile.conversations.messages.index', [
                'conversation' => $bookingConversation,
            ]));

        $messages->assertOk()
            ->assertJsonPath('data.meta.message_order', 'desc')
            ->assertJsonCount(2, 'data.messages');

        $poll = $this->withToken($token)
            ->getJson(route('api.admin-mobile.conversations.poll', [
                'conversation' => $bookingConversation,
                'after_message_id' => $afterMessageId,
            ]));

        $poll->assertOk()
            ->assertJsonPath('data.meta.message_order', 'asc')
            ->assertJsonPath('data.meta.delta_count', 1)
            ->assertJsonPath('data.messages.0.message_text', 'Baik, saya bantu cek seat yang tersedia.');

        $pollList = $this->withToken($token)
            ->getJson(route('api.admin-mobile.poll.list', [
                'scope' => 'unread',
            ]));

        $pollList->assertOk()
            ->assertJsonPath('data.conversation_list.items.0.id', $bookingConversation->id);

        $dashboard = $this->withToken($token)
            ->getJson(route('api.admin-mobile.dashboard.summary'));

        $dashboard->assertOk()
            ->assertJsonPath('data.dashboard_summary.core_stats.total_conversations', 2)
            ->assertJsonPath('data.dashboard_summary.workspace_insights.conversation_by_status.bot_active', 1);

        $filters = $this->withToken($token)
            ->getJson(route('api.admin-mobile.meta.filters'));

        $filters->assertOk()
            ->assertJsonPath('data.filters_meta.available_scopes.0.value', 'all')
            ->assertJsonPath('data.filters_meta.available_channels.1.value', 'whatsapp')
            ->assertJsonPath('data.filters_meta.available_sorts.0.value', 'last_message_at');
    }

    public function test_admin_mobile_conversation_list_supports_filter_sort_and_pagination_safely(): void
    {
        $admin = User::factory()->create([
            'name' => 'Filter Admin',
            'email' => 'filter-admin@example.com',
            'password' => Hash::make('super-secret'),
            'is_chatbot_admin' => true,
        ]);

        $token = $this->loginAdmin($admin, 'super-secret');
        [$olderWhatsapp, $newerWhatsapp] = $this->seedConversationListFixtures();

        $filtered = $this->withToken($token)->getJson(route('api.admin-mobile.conversations.index', [
            'channel' => 'whatsapp',
            'sort_by' => 'started_at',
            'sort_dir' => 'asc',
            'per_page' => 1,
            'page' => 2,
        ]));

        $filtered->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.filters_meta.channel', 'whatsapp')
            ->assertJsonPath('data.filters_meta.sort_by', 'started_at')
            ->assertJsonPath('data.filters_meta.sort_dir', 'asc')
            ->assertJsonPath('data.conversation_list.pagination.current_page', 2)
            ->assertJsonPath('data.conversation_list.pagination.per_page', 1)
            ->assertJsonPath('data.conversation_list.sort.by', 'started_at')
            ->assertJsonPath('data.conversation_list.sort.direction', 'asc')
            ->assertJsonPath('data.conversation_list.items.0.id', $newerWhatsapp->id)
            ->assertJsonPath('data.conversation_list.items.0.channel', 'whatsapp');

        $invalidSort = $this->withToken($token)->getJson(route('api.admin-mobile.conversations.index', [
            'sort_by' => 'drop_table',
            'sort_dir' => 'desc',
        ]));

        $invalidSort->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_admin_mobile_messages_and_poll_include_image_media_payload(): void
    {
        $admin = User::factory()->create([
            'name' => 'Media Admin',
            'email' => 'media-admin@example.com',
            'password' => Hash::make('super-secret'),
            'is_chatbot_admin' => true,
        ]);

        $token = $this->loginAdmin($admin, 'super-secret');
        [$conversation] = $this->seedWorkspaceConversation($admin);

        config()->set('app.url', 'https://spesial.online');

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'sender_type' => SenderType::Admin,
            'message_type' => 'image',
            'message_text' => 'Foto timbangan terbaru',
            'raw_payload' => [
                'outbound_payload' => [
                    'image' => [
                        'link' => 'http://spesial.online/storage/conversation-media/images/timbangan.jpg',
                    ],
                ],
                'media_caption' => 'Foto timbangan terbaru',
                'mime_type' => 'image/jpeg',
            ],
            'sent_at' => now()->subMinutes(2),
            'delivery_status' => MessageDeliveryStatus::Sent,
        ]);

        $messages = $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'spesial.online',
        ])->withToken($token)->getJson(route('api.admin-mobile.conversations.messages.index', [
            'conversation' => $conversation,
        ]));

        $messages->assertOk()
            ->assertJsonPath('data.messages.2.message_type', 'image')
            ->assertJsonPath('data.messages.2.media.image_url', 'https://spesial.online/storage/conversation-media/images/timbangan.jpg')
            ->assertJsonPath('data.messages.2.media.caption', 'Foto timbangan terbaru');

        $poll = $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'spesial.online',
        ])->withToken($token)->getJson(route('api.admin-mobile.conversations.poll', [
            'conversation' => $conversation,
        ]));

        $poll->assertOk()
            ->assertJsonPath('data.messages.2.message_type', 'image')
            ->assertJsonPath('data.messages.2.media.image_url', 'https://spesial.online/storage/conversation-media/images/timbangan.jpg');
    }

    public function test_mobile_customer_token_cannot_access_admin_mobile_routes(): void
    {
        $register = $this->postJson(route('api.mobile.auth.register'), [
            'device_id' => 'device-hardening-01',
            'name' => 'Customer Token',
            'email' => 'customer-token@example.com',
        ]);

        $register->assertCreated();

        $customerToken = (string) $register->json('data.access_token');

        $workspace = $this->withToken($customerToken)
            ->getJson(route('api.admin-mobile.workspace'));

        $workspace->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    /**
     * @return array{0: Conversation, 1: Customer, 2: int}
     */
    private function seedWorkspaceConversation(User $admin): array
    {
        $customer = Customer::create([
            'name' => 'Budi Santoso',
            'phone_e164' => '+628123450001',
            'email' => 'budi@example.com',
            'status' => 'active',
            'total_bookings' => 3,
            'total_spent' => 450000,
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'needs_human' => false,
            'started_at' => now()->subHour(),
            'last_message_at' => now(),
            'source_app' => 'web-dashboard',
            'assigned_admin_id' => $admin->id,
        ]);

        $inbound = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => 'Saya mau booking travel ke Pekanbaru besok pagi.',
            'raw_payload' => [],
            'sent_at' => now()->subMinutes(5),
        ]);

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'sender_type' => SenderType::Bot,
            'message_type' => 'text',
            'message_text' => 'Baik, saya bantu cek seat yang tersedia.',
            'raw_payload' => [],
            'sent_at' => now()->subMinutes(4),
            'delivery_status' => MessageDeliveryStatus::Sent,
        ]);

        BookingRequest::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'pickup_location' => 'Pasir Pengaraian',
            'pickup_full_address' => 'Pasir Pengaraian Kota',
            'destination' => 'Pekanbaru',
            'destination_full_address' => 'Bandara Sultan Syarif Kasim II',
            'departure_date' => now()->addDay()->toDateString(),
            'departure_time' => '08:00',
            'passenger_count' => 2,
            'selected_seats' => ['CC', 'BS'],
            'passenger_name' => 'Budi Santoso',
            'contact_number' => '+628123450001',
            'booking_status' => BookingStatus::Draft,
            'price_estimate' => 300000,
        ]);

        ConversationState::create([
            'conversation_id' => $conversation->id,
            'state_key' => 'passenger_name',
            'state_value' => ['Budi Santoso'],
        ]);

        ConversationState::create([
            'conversation_id' => $conversation->id,
            'state_key' => 'selected_seats',
            'state_value' => ['CC', 'BS'],
        ]);

        ConversationTag::create([
            'conversation_id' => $conversation->id,
            'tag' => 'vip',
            'created_by' => $admin->id,
        ]);

        CustomerTag::create([
            'customer_id' => $customer->id,
            'tag' => 'gold',
        ]);

        AdminNote::create([
            'noteable_type' => Conversation::class,
            'noteable_id' => $conversation->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'author_id' => $admin->id,
            'body' => 'Customer pernah booking rute yang sama minggu lalu.',
        ]);

        AuditLog::create([
            'actor_user_id' => $admin->id,
            'action_type' => 'admin_reply_sent',
            'conversation_id' => $conversation->id,
            'auditable_type' => Conversation::class,
            'auditable_id' => $conversation->id,
            'message' => 'Admin meninjau percakapan.',
            'context' => [
                'reason' => 'monitoring',
            ],
        ]);

        Escalation::create([
            'conversation_id' => $conversation->id,
            'reason' => 'needs_follow_up',
            'priority' => 'high',
            'status' => 'open',
            'summary' => 'Booking membutuhkan follow up manual.',
        ]);

        return [$conversation, $customer, $inbound->id];
    }

    private function seedClosedConversation(): void
    {
        $customer = Customer::create([
            'name' => 'Siti',
            'phone_e164' => '+628123450002',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'mobile_live_chat',
            'status' => ConversationStatus::Closed,
            'handoff_mode' => 'bot',
            'started_at' => now()->subDay(),
            'last_message_at' => now()->subHours(2),
            'source_app' => 'flutter',
            'closed_at' => now()->subHours(2),
        ]);

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => 'Terima kasih, chat saya sudah selesai.',
            'raw_payload' => [],
            'sent_at' => now()->subHours(3),
        ]);
    }

    /**
     * @return array{0: Conversation, 1: Conversation}
     */
    private function seedConversationListFixtures(): array
    {
        $olderCustomer = Customer::create([
            'name' => 'Ari WhatsApp',
            'phone_e164' => '+628123450010',
            'status' => 'active',
        ]);

        $olderWhatsapp = Conversation::create([
            'customer_id' => $olderCustomer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now()->subHours(5),
            'last_message_at' => now()->subHours(2),
            'source_app' => 'web-dashboard',
        ]);

        ConversationMessage::create([
            'conversation_id' => $olderWhatsapp->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => 'Halo dari WhatsApp yang lebih lama.',
            'raw_payload' => [],
            'sent_at' => now()->subHours(2),
        ]);

        $newerCustomer = Customer::create([
            'name' => 'Bela WhatsApp',
            'phone_e164' => '+628123450011',
            'status' => 'active',
        ]);

        $newerWhatsapp = Conversation::create([
            'customer_id' => $newerCustomer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now()->subHours(4),
            'last_message_at' => now()->subHour(),
            'source_app' => 'web-dashboard',
        ]);

        ConversationMessage::create([
            'conversation_id' => $newerWhatsapp->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => 'Halo dari WhatsApp yang lebih baru.',
            'raw_payload' => [],
            'sent_at' => now()->subHour(),
        ]);

        $mobileCustomer = Customer::create([
            'name' => 'Cici Mobile',
            'phone_e164' => 'mlc:filter-mobile-001',
            'status' => 'active',
        ]);

        $mobileConversation = Conversation::create([
            'customer_id' => $mobileCustomer->id,
            'channel' => 'mobile_live_chat',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now()->subHours(3),
            'last_message_at' => now()->subMinutes(20),
            'source_app' => 'flutter',
        ]);

        ConversationMessage::create([
            'conversation_id' => $mobileConversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => 'Halo dari mobile live chat.',
            'raw_payload' => [],
            'sent_at' => now()->subMinutes(20),
        ]);

        return [$olderWhatsapp, $newerWhatsapp];
    }

    private function loginAdmin(User $admin, string $password): string
    {
        $response = $this->postJson(route('api.admin-mobile.auth.login'), [
            'email' => $admin->email,
            'password' => $password,
            'device_name' => 'QA Device',
            'device_id' => 'qa-admin-device',
        ]);

        $response->assertOk();

        return (string) $response->json('data.access_token');
    }
}
