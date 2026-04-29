<?php

namespace Tests\Unit\Models;

use App\Models\Customer;
use App\Models\CustomerPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'name'       => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status'     => 'active',
        ]);
    }

    public function test_get_typed_value_casts_string(): void
    {
        $customer = $this->makeCustomer();

        $pref = CustomerPreference::create([
            'customer_id' => $customer->id,
            'key'         => 'preferred_pickup_area',
            'value'       => 'Pasir Pengaraian',
            'value_type'  => 'string',
            'confidence'  => 0.8,
            'source'      => 'inferred',
        ]);

        $this->assertSame('Pasir Pengaraian', $pref->getTypedValue());
    }

    public function test_get_typed_value_casts_int(): void
    {
        $customer = $this->makeCustomer();

        $pref = CustomerPreference::create([
            'customer_id' => $customer->id,
            'key'         => 'cancellation_rate',
            'value'       => '15',
            'value_type'  => 'int',
            'confidence'  => 0.7,
            'source'      => 'inferred',
        ]);

        $this->assertSame(15, $pref->getTypedValue());
    }

    public function test_get_typed_value_casts_bool_variants(): void
    {
        $customer = $this->makeCustomer();

        foreach ([
            ['1', true],
            ['0', false],
            ['true', true],
            ['false', false],
            ['yes', true],
            ['no', false],
        ] as [$raw, $expected]) {
            $pref = CustomerPreference::create([
                'customer_id' => $customer->id,
                'key'         => 'flag_'.$raw,
                'value'       => $raw,
                'value_type'  => 'bool',
                'confidence'  => 0.5,
                'source'      => 'inferred',
            ]);

            $this->assertSame($expected, $pref->getTypedValue(), "raw={$raw}");
        }
    }

    public function test_get_typed_value_casts_json(): void
    {
        $customer = $this->makeCustomer();

        $pref = CustomerPreference::create([
            'customer_id' => $customer->id,
            'key'         => 'multi_value',
            'value'       => json_encode(['a', 'b']),
            'value_type'  => 'json',
            'confidence'  => 0.5,
            'source'      => 'inferred',
        ]);

        $this->assertSame(['a', 'b'], $pref->getTypedValue());
    }

    public function test_reliable_scope_filters_below_threshold(): void
    {
        $customer = $this->makeCustomer();

        CustomerPreference::create([
            'customer_id' => $customer->id,
            'key'         => 'reliable_one',
            'value'       => 'x',
            'value_type'  => 'string',
            'confidence'  => 0.6,
            'source'      => 'inferred',
        ]);

        CustomerPreference::create([
            'customer_id' => $customer->id,
            'key'         => 'unreliable_one',
            'value'       => 'y',
            'value_type'  => 'string',
            'confidence'  => 0.3,
            'source'      => 'inferred',
        ]);

        $reliable = CustomerPreference::query()->reliable()->pluck('key')->all();

        $this->assertSame(['reliable_one'], $reliable);
    }
}
