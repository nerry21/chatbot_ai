<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Chatbot\ConversationManagerService;
use App\Services\Support\PhoneNumberService;
use App\Services\WhatsApp\WhatsAppCallWebhookService;
use App\Services\WhatsApp\WhatsAppMessageParser;
use App\Services\WhatsApp\WhatsAppWebhookService;
use PHPUnit\Framework\TestCase;

class WhatsAppWebhookServiceCriticalPathTest extends TestCase
{
    public function testHandleWithInvalidPayloadReturnsProcessedReportWithZeroCounts(): void
    {
        $parser = $this->createMock(WhatsAppMessageParser::class);
        $parser->method('isValidWebhookPayload')->willReturn(false);

        // Should not be called, but define safe defaults
        $parser->method('extractMessages')->willReturn([]);
        $parser->method('extractStatuses')->willReturn([]);
        $parser->method('extractCalls')->willReturn([]);

        $callWebhookService = $this->createMock(WhatsAppCallWebhookService::class);
        $phoneService = $this->createMock(PhoneNumberService::class);
        $conversationManager = $this->createMock(ConversationManagerService::class);

        $service = new WhatsAppWebhookService(
            parser: $parser,
            callWebhookService: $callWebhookService,
            phoneService: $phoneService,
            conversationManager: $conversationManager,
        );

        $payload = []; // invalid payload on purpose

        $report = $service->handle($payload);

        $this->assertIsArray($report);
        $this->assertArrayHasKey('trace_id', $report);
        $this->assertArrayHasKey('status', $report);
        $this->assertArrayHasKey('message_count', $report);
        $this->assertArrayHasKey('status_count', $report);
        $this->assertArrayHasKey('call_count', $report);
        $this->assertArrayHasKey('queued_jobs', $report);
        $this->assertArrayHasKey('duplicates', $report);
        $this->assertArrayHasKey('errors', $report);

        $this->assertSame('processed', $report['status']);
        $this->assertSame(0, $report['message_count']);
        $this->assertSame(0, $report['status_count']);
        $this->assertSame(0, $report['call_count']);
        $this->assertSame(0, $report['queued_jobs']);
        $this->assertSame(0, $report['duplicates']);
        $this->assertIsArray($report['errors']);
        $this->assertCount(0, $report['errors']);
    }

    public function testHandleWithCallsOnlyIncrementsCallCount(): void
    {
        $parser = $this->createMock(WhatsAppMessageParser::class);
        $parser->method('isValidWebhookPayload')->willReturn(true);
        $parser->method('extractMessages')->willReturn([]);
        $parser->method('extractStatuses')->willReturn([]);
        $parser->method('extractCalls')->willReturn([
            ['wa_call_id' => 'CALL-1', 'event' => 'connected'],
            ['wa_call_id' => 'CALL-2', 'event' => 'ended'],
        ]);

        $callWebhookService = $this->createMock(WhatsAppCallWebhookService::class);
        $callWebhookService
            ->expects($this->exactly(2))
            ->method('handleCallEvent')
            ->willReturn(['result' => 'processed']);

        $phoneService = $this->createMock(PhoneNumberService::class);
        $conversationManager = $this->createMock(ConversationManagerService::class);

        $service = new WhatsAppWebhookService(
            parser: $parser,
            callWebhookService: $callWebhookService,
            phoneService: $phoneService,
            conversationManager: $conversationManager,
        );

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [], // content doesn't matter, parser is mocked
        ];

        $report = $service->handle($payload);

        $this->assertIsArray($report);
        $this->assertSame('processed', $report['status']);
        $this->assertSame(0, $report['message_count'], 'message_count should be 0');
        $this->assertSame(0, $report['status_count'], 'status_count should be 0');
        $this->assertSame(2, $report['call_count'], 'call_count should reflect number of parsed calls');
        $this->assertSame(0, $report['queued_jobs']);
        $this->assertSame(0, $report['duplicates']);
        $this->assertIsArray($report['errors']);
        $this->assertCount(0, $report['errors']);
    }
}
