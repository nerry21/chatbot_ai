<?php

namespace Tests\Unit;

use App\Services\CRM\HubSpotService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubSpotServiceTest extends TestCase
{
    public function test_it_updates_existing_contact_when_match_is_found(): void
    {
        config([
            'chatbot.crm.hubspot.enabled' => true,
            'chatbot.crm.hubspot.token' => 'test-token',
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/objects/contacts/search')) {
                return Http::response([
                    'results' => [
                        ['id' => '123'],
                    ],
                ]);
            }

            if ($request->method() === 'PATCH' && str_ends_with($request->url(), '/objects/contacts/123')) {
                $payload = json_decode($request->body(), true);

                return Http::response([
                    'id' => '123',
                    'properties' => $payload['properties'] ?? [],
                ]);
            }

            return Http::response([], 500);
        });

        $result = (new HubSpotService)->upsertContact([
            'email' => 'nerry@example.com',
            'firstname' => 'Nerry',
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertSame('123', $result['contact_id']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PATCH'
                && str_ends_with($request->url(), '/objects/contacts/123');
        });
    }

    public function test_it_creates_contact_when_no_existing_match_is_found(): void
    {
        config([
            'chatbot.crm.hubspot.enabled' => true,
            'chatbot.crm.hubspot.token' => 'test-token',
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/objects/contacts/search')) {
                return Http::response([
                    'results' => [],
                ]);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/objects/contacts')) {
                $payload = json_decode($request->body(), true);

                return Http::response([
                    'id' => '456',
                    'properties' => $payload['properties'] ?? [],
                ]);
            }

            return Http::response([], 500);
        });

        $result = (new HubSpotService)->upsertContact([
            'email' => 'new@example.com',
            'firstname' => 'New Contact',
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertSame('456', $result['contact_id']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/objects/contacts');
        });
    }
}
