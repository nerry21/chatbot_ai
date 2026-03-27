<?php

namespace Tests\Unit;

use App\Services\Booking\FareCalculatorService;
use Tests\TestCase;

class FareCalculatorServiceTest extends TestCase
{
    public function test_it_normalizes_bidirectional_routes_and_calculates_total_fare(): void
    {
        $service = app(FareCalculatorService::class);

        $route = $service->normalizedRoute('pku', 'bangkinang');
        $breakdown = $service->fareBreakdown('pku', 'bangkinang', 2);

        $this->assertSame([
            'pickup' => 'Pekanbaru',
            'destination' => 'Bangkinang',
            'trip_key' => 'pekanbaru__bangkinang',
        ], $route);
        $this->assertNotNull($breakdown);
        $this->assertSame(100000, $breakdown['unit_fare']);
        $this->assertSame(2, $breakdown['passenger_count']);
        $this->assertSame(200000, $breakdown['total_fare']);
        $this->assertSame(200000, $service->calculate('pku', 'bangkinang', 2));
    }

    public function test_it_returns_null_for_unsupported_routes_and_flags_admin_escalation(): void
    {
        $service = app(FareCalculatorService::class);

        $this->assertNull($service->unitFare('Kuok', 'Pekanbaru'));
        $this->assertNull($service->fareBreakdown('Kuok', 'Pekanbaru', 1));
        $this->assertTrue($service->needsAdminEscalation('Kuok', 'Pekanbaru'));
    }
}
