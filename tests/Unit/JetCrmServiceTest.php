<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Services\CRM\JetCrmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JetCrmServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_contact_creates_a_new_customer_when_none_matches(): void
    {
        $result = (new JetCrmService)->upsertContact([
            'name' => 'Nerry',
            'phone' => '+6281234567890',
            'email' => 'new@example.com',
            'total_bookings' => 2,
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertNotNull($result['id']);

        $row = DB::table('customers')->where('id', $result['id'])->first();
        $this->assertNotNull($row);
        $this->assertSame('Nerry', $row->name);
        $this->assertSame('+6281234567890', $row->phone_e164);
        $this->assertSame('new@example.com', $row->email);
        $this->assertSame(2, (int) $row->total_bookings);
    }

    public function test_upsert_contact_updates_existing_customer_matched_by_phone(): void
    {
        $existing = Customer::create([
            'name' => 'Old Name',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $result = (new JetCrmService)->upsertContact([
            'phone' => '+6281234567890',
            'name' => 'New Name',
            'email' => 'updated@example.com',
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertSame($existing->id, $result['id']);

        $row = DB::table('customers')->where('id', $existing->id)->first();
        $this->assertSame('New Name', $row->name);
        $this->assertSame('updated@example.com', $row->email);
    }

    public function test_get_contact_by_id_returns_customer_data(): void
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'email' => 'nerry@example.com',
            'status' => 'active',
        ]);

        $result = (new JetCrmService)->getContactById((string) $customer->id);

        $this->assertSame('success', $result['status']);
        $this->assertSame($customer->id, $result['id']);
        $this->assertSame('Nerry', $result['properties']['name']);
        $this->assertSame('+6281234567890', $result['properties']['phone_e164']);
    }

    public function test_get_contact_by_id_filters_properties(): void
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'email' => 'nerry@example.com',
            'status' => 'active',
        ]);

        $result = (new JetCrmService)->getContactById((string) $customer->id, ['name', 'email']);

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('name', $result['properties']);
        $this->assertArrayHasKey('email', $result['properties']);
        $this->assertArrayNotHasKey('phone_e164', $result['properties']);
    }

    public function test_get_contact_by_id_returns_failure_when_customer_missing(): void
    {
        $result = (new JetCrmService)->getContactById('99999999');

        $this->assertSame('failed', $result['status']);
    }

    public function test_append_note_prepends_timestamped_note_to_customers_notes_column(): void
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
            'notes' => 'pre-existing line',
        ]);

        $result = (new JetCrmService)->appendNote((string) $customer->id, 'first contact made');

        $this->assertSame('success', $result['status']);
        $this->assertSame($customer->id, $result['customer_id']);
        $this->assertStringStartsWith('local_note_', $result['note_id']);

        $stored = DB::table('customers')->where('id', $customer->id)->value('notes');
        $this->assertStringContainsString('first contact made', $stored);
        $this->assertStringContainsString('pre-existing line', $stored);
        $this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}\] first contact made/', $stored);
    }

    public function test_append_note_returns_failure_for_unknown_customer(): void
    {
        $result = (new JetCrmService)->appendNote('99999999', 'orphan note');

        $this->assertSame('non_retryable_failure', $result['status']);
    }

    public function test_update_contact_properties_updates_known_columns_and_reports_dropped(): void
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $result = (new JetCrmService)->updateContactProperties((string) $customer->id, [
            'name' => 'Renamed',
            'email' => 'renamed@example.com',
            'jobtitle' => 'Director',
        ]);

        $this->assertSame('success', $result['status']);

        $row = DB::table('customers')->where('id', $customer->id)->first();
        $this->assertSame('Renamed', $row->name);
        $this->assertSame('renamed@example.com', $row->email);

        $this->assertContains('jobtitle', $result['unknown_properties']);
    }

    public function test_is_enabled_is_always_true(): void
    {
        $this->assertTrue((new JetCrmService)->isEnabled());
    }
}
