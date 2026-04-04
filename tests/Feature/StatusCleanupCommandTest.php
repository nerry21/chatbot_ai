<?php

namespace Tests\Feature;

use App\Models\StatusUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusCleanupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivate_expired_statuses_command_disables_only_expired_active_statuses(): void
    {
        $expiredStatus = StatusUpdate::query()->create([
            'author_type' => 'admin',
            'status_type' => 'text',
            'text' => 'Expired status',
            'audience_scope' => 'contacts_and_chatters',
            'is_active' => true,
            'posted_at' => now()->subDay(),
            'expires_at' => now()->subMinute(),
        ]);

        $activeStatus = StatusUpdate::query()->create([
            'author_type' => 'admin',
            'status_type' => 'text',
            'text' => 'Active status',
            'audience_scope' => 'contacts_and_chatters',
            'is_active' => true,
            'posted_at' => now(),
            'expires_at' => now()->addHours(6),
        ]);

        $alreadyInactive = StatusUpdate::query()->create([
            'author_type' => 'admin',
            'status_type' => 'text',
            'text' => 'Inactive status',
            'audience_scope' => 'contacts_and_chatters',
            'is_active' => false,
            'posted_at' => now()->subDay(),
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('statuses:deactivate-expired')
            ->expectsOutputToContain('Status expired dinonaktifkan: 1')
            ->assertExitCode(0);

        $this->assertFalse((bool) $expiredStatus->fresh()?->is_active);
        $this->assertTrue((bool) $activeStatus->fresh()?->is_active);
        $this->assertFalse((bool) $alreadyInactive->fresh()?->is_active);
    }
}
