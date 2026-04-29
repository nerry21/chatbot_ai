<?php

namespace Tests\Unit\Console;

use App\Services\WhatsApp\WhatsAppSenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SendDailySummaryReportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_dry_run_does_not_send(): void
    {
        config(['chatbot.daily_summary.recipients' => ['628117598804', '6281267975175']]);

        $this->mock(WhatsAppSenderService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('sendText');
        });

        $exitCode = $this->artisan('chatbot:daily-summary', ['--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN — not sending.')
            ->run();

        $this->assertSame(0, $exitCode);
    }

    public function test_sends_to_all_recipients(): void
    {
        config(['chatbot.daily_summary.recipients' => ['628117598804', '6281267975175']]);

        $this->mock(WhatsAppSenderService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendText')
                ->twice()
                ->andReturn([
                    'status' => 'success',
                    'provider' => 'whatsapp',
                    'response' => [],
                    'error' => null,
                ]);
        });

        $exitCode = $this->artisan('chatbot:daily-summary')->run();

        $this->assertSame(0, $exitCode);
    }
}
