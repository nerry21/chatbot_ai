<?php

namespace Tests\Unit\Chatbot;

use App\Models\KnowledgeArticle;
use App\Services\Booking\FareCalculatorService;
use App\Services\Booking\RouteValidationService;
use App\Services\Booking\SeatAvailabilityService;
use App\Services\Chatbot\LlmAgentToolRegistry;
use App\Services\Knowledge\KnowledgeBaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class LlmAgentToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): LlmAgentToolRegistry
    {
        return app(LlmAgentToolRegistry::class);
    }

    public function test_returns_tools_schema_with_5_tools(): void
    {
        $schema = $this->registry()->getToolsSchema();

        $this->assertCount(5, $schema);

        $names = [];
        foreach ($schema as $entry) {
            $this->assertSame('function', $entry['type'] ?? null);
            $this->assertNotEmpty($entry['function']['name'] ?? null);
            $names[] = $entry['function']['name'];
        }

        $this->assertSame(
            ['get_fare_for_route', 'check_seat_availability', 'search_knowledge_base', 'get_route_info', 'escalate_to_admin'],
            $names,
        );
    }

    public function test_executes_get_fare_for_route_returns_amount(): void
    {
        $result = $this->registry()->execute('get_fare_for_route', [
            'pickup' => 'Pasir Pengaraian',
            'dropoff' => 'Pekanbaru',
        ]);

        $this->assertSame(150000, $result['amount']);
        $this->assertSame('Rp 150.000', $result['formatted']);
        $this->assertSame('Pasir Pengaraian', $result['pickup']);
        $this->assertSame('Pekanbaru', $result['dropoff']);
        $this->assertTrue($result['supported']);
    }

    public function test_executes_get_fare_for_route_returns_error_for_unsupported(): void
    {
        $result = $this->registry()->execute('get_fare_for_route', [
            'pickup' => 'Atlantis',
            'dropoff' => 'Mars',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertFalse($result['supported']);
    }

    public function test_executes_check_seat_availability(): void
    {
        $result = $this->registry()->execute('check_seat_availability', [
            'date' => '2026-05-01',
            'time' => '07:00',
        ]);

        $this->assertArrayHasKey('available_seats', $result);
        $this->assertIsArray($result['available_seats']);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('has_capacity', $result);
        $this->assertSame(count($result['available_seats']), $result['count']);
    }

    public function test_executes_search_knowledge_base(): void
    {
        config(['chatbot.knowledge.enabled' => true]);

        KnowledgeArticle::create([
            'title' => 'Tarif Pekanbaru',
            'slug' => 'tarif-pekanbaru',
            'category' => 'tarif',
            'content' => 'Tarif perjalanan ke Pekanbaru adalah Rp 150.000 per kursi.',
            'keywords' => ['tarif', 'pekanbaru'],
            'is_active' => true,
        ]);

        $result = $this->registry()->execute('search_knowledge_base', [
            'query' => 'tarif pekanbaru',
        ]);

        $this->assertArrayHasKey('hits', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertGreaterThanOrEqual(1, $result['count']);
        $this->assertSame('Tarif Pekanbaru', $result['hits'][0]['title']);
    }

    public function test_executes_get_route_info_finds_location(): void
    {
        $result = $this->registry()->execute('get_route_info', [
            'query_text' => 'Saya di Pasir Pengaraian',
        ]);

        $this->assertSame('Pasir Pengaraian', $result['location']);
        $this->assertIsArray($result['supported_destinations']);
    }

    public function test_executes_get_route_info_returns_null_when_no_location(): void
    {
        $result = $this->registry()->execute('get_route_info', [
            'query_text' => 'XXXXXXXXX',
        ]);

        $this->assertNull($result['location']);
        $this->assertSame('Lokasi tidak terdeteksi', $result['message']);
    }

    public function test_executes_escalate_to_admin_returns_handoff(): void
    {
        $result = $this->registry()->execute('escalate_to_admin', [
            'reason' => 'refund request',
        ]);

        $this->assertTrue($result['handoff_triggered']);
        $this->assertSame('refund request', $result['reason']);
    }

    public function test_throws_on_unknown_tool(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry()->execute('nonexistent_tool', []);
    }
}
