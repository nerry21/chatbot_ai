<?php

namespace Tests\Feature\Api;

use App\Models\StatusUpdate;
use App\Models\StatusUpdateView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileStatusFeedApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_customer_can_list_active_admin_status_feed(): void
    {
        [$token, $customerId] = $this->registerMobileCustomer(
            deviceId: 'device-status-feed-001',
            name: 'Rina',
            email: 'rina-status@example.com',
        );

        $admin = User::factory()->create([
            'name' => 'Admin Feed',
            'is_chatbot_admin' => true,
        ]);

        $viewedStatus = StatusUpdate::query()->create([
            'user_id' => $admin->id,
            'author_type' => 'admin',
            'status_type' => 'text',
            'text' => 'Promo sudah dilihat',
            'background_color' => '#25D366',
            'text_color' => '#FFFFFF',
            'font_style' => 'default',
            'audience_scope' => 'contacts_and_chatters',
            'is_active' => true,
            'posted_at' => now()->subMinute(),
            'expires_at' => now()->addHours(4),
        ]);

        $unviewedStatus = StatusUpdate::query()->create([
            'user_id' => $admin->id,
            'author_type' => 'admin',
            'status_type' => 'image',
            'caption' => 'Poster promo baru',
            'media_disk' => 'public',
            'media_path' => 'status-updates/images/poster.jpg',
            'media_mime_type' => 'image/jpeg',
            'audience_scope' => 'public',
            'is_active' => true,
            'posted_at' => now(),
            'expires_at' => now()->addHours(6),
        ]);

        StatusUpdate::query()->create([
            'user_id' => $admin->id,
            'author_type' => 'admin',
            'status_type' => 'text',
            'text' => 'Status expired',
            'audience_scope' => 'contacts_and_chatters',
            'is_active' => true,
            'posted_at' => now()->subDay(),
            'expires_at' => now()->subMinute(),
        ]);

        StatusUpdate::query()->create([
            'user_id' => $admin->id,
            'author_type' => 'admin',
            'status_type' => 'text',
            'text' => 'Status private',
            'audience_scope' => 'private',
            'is_active' => true,
            'posted_at' => now(),
            'expires_at' => now()->addHours(4),
        ]);

        StatusUpdateView::query()->create([
            'status_update_id' => $viewedStatus->id,
            'customer_id' => $customerId,
            'viewed_at' => now()->subSeconds(30),
        ]);

        $response = $this->withToken($token)
            ->getJson(route('api.mobile.status-feed.index'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.author_id', $admin->id)
            ->assertJsonPath('data.items.0.author_name', 'Admin Feed')
            ->assertJsonPath('data.items.0.has_unviewed', true)
            ->assertJsonCount(2, 'data.items.0.statuses');

        $statuses = collect($response->json('data.items.0.statuses'));

        $this->assertSame(
            false,
            $statuses->firstWhere('id', $unviewedStatus->id)['is_viewed'] ?? true,
        );
        $this->assertSame(
            true,
            $statuses->firstWhere('id', $viewedStatus->id)['is_viewed'] ?? false,
        );
        $this->assertSame(
            1,
            $statuses->firstWhere('id', $viewedStatus->id)['viewer_count'] ?? 0,
        );
        $this->assertSame(
            0,
            $statuses->firstWhere('id', $viewedStatus->id)['segment_index'] ?? -1,
        );
        $this->assertSame(
            1,
            $statuses->firstWhere('id', $unviewedStatus->id)['segment_index'] ?? -1,
        );
        $this->assertSame(
            2,
            $statuses->firstWhere('id', $viewedStatus->id)['segment_total'] ?? 0,
        );
    }

    public function test_mobile_customer_can_show_status_and_mark_it_viewed(): void
    {
        [$token, $customerId] = $this->registerMobileCustomer(
            deviceId: 'device-status-feed-002',
            name: 'Tari',
            email: 'tari-status@example.com',
        );

        $admin = User::factory()->create([
            'name' => 'Admin Viewer',
            'is_chatbot_admin' => true,
        ]);

        $status = StatusUpdate::query()->create([
            'user_id' => $admin->id,
            'author_type' => 'admin',
            'status_type' => 'text',
            'text' => 'Status untuk detail',
            'background_color' => '#7EC8A5',
            'text_color' => '#FFFFFF',
            'font_style' => 'default',
            'audience_scope' => 'contacts_and_chatters',
            'is_active' => true,
            'posted_at' => now(),
            'expires_at' => now()->addHours(12),
        ]);

        $detail = $this->withToken($token)
            ->getJson(route('api.mobile.status-feed.show', ['statusUpdate' => $status]));

        $detail->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status.id', $status->id)
            ->assertJsonPath('data.status.is_viewed', false)
            ->assertJsonPath('data.status.viewer_count', 0);

        $markViewed = $this->withToken($token)
            ->postJson(route('api.mobile.status-feed.view', ['statusUpdate' => $status]));

        $markViewed->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status_id', $status->id)
            ->assertJsonPath('data.viewer_count', 1);

        $this->assertDatabaseHas('status_update_views', [
            'status_update_id' => $status->id,
            'customer_id' => $customerId,
        ]);

        $detailAfterView = $this->withToken($token)
            ->getJson(route('api.mobile.status-feed.show', ['statusUpdate' => $status]));

        $detailAfterView->assertOk()
            ->assertJsonPath('data.status.is_viewed', true)
            ->assertJsonPath('data.status.viewer_count', 1);
    }

    public function test_mobile_customer_cannot_open_expired_status(): void
    {
        [$token] = $this->registerMobileCustomer(
            deviceId: 'device-status-feed-003',
            name: 'Nia',
            email: 'nia-status@example.com',
        );

        $admin = User::factory()->create([
            'name' => 'Admin Expired',
            'is_chatbot_admin' => true,
        ]);

        $expiredStatus = StatusUpdate::query()->create([
            'user_id' => $admin->id,
            'author_type' => 'admin',
            'status_type' => 'text',
            'text' => 'Sudah expired',
            'audience_scope' => 'contacts_and_chatters',
            'is_active' => true,
            'posted_at' => now()->subDay(),
            'expires_at' => now()->subMinute(),
        ]);

        $this->withToken($token)
            ->getJson(route('api.mobile.status-feed.show', ['statusUpdate' => $expiredStatus]))
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function registerMobileCustomer(string $deviceId, string $name, string $email): array
    {
        $response = $this->postJson(route('api.mobile.auth.register'), [
            'device_id' => $deviceId,
            'name' => $name,
            'email' => $email,
        ]);

        $response->assertCreated();

        return [
            (string) $response->json('data.access_token'),
            (int) $response->json('data.customer.id'),
        ];
    }
}
