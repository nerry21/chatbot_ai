<?php

namespace Tests\Unit;

use App\Services\Booking\BookingInteractiveMessageService;
use Tests\TestCase;

class BookingInteractiveMessageServiceTest extends TestCase
{
    public function test_it_builds_departure_time_menu_with_interactive_payload_and_numbered_fallback(): void
    {
        $service = app(BookingInteractiveMessageService::class);

        $menu = $service->departureTimeMenu(
            'Izin Bapak/Ibu, mohon pilih jam keberangkatannya ya.',
            (array) config('chatbot.jet.departure_slots', []),
        );

        $this->assertSame('interactive', $menu['message_type']);
        $this->assertSame('list', $menu['outbound_payload']['interactive']['type']);
        $this->assertSame('Pilih Jam', $menu['outbound_payload']['interactive']['action']['button']);
        $this->assertSame('departure_time:05:00', $menu['outbound_payload']['interactive']['action']['sections'][0]['rows'][0]['id']);
        $this->assertSame('Subuh (05.00 WIB)', $menu['outbound_payload']['interactive']['action']['sections'][0]['rows'][0]['title']);
        $this->assertStringContainsString('1. Subuh (05.00 WIB)', $menu['text']);
        $this->assertStringContainsString('Jika menu tidak tampil, balas angka atau jam yang dipilih.', $menu['text']);
    }

    public function test_it_builds_pickup_location_menu_with_numbered_fallback(): void
    {
        $service = app(BookingInteractiveMessageService::class);

        $menu = $service->pickupLocationMenu(
            'Izin Bapak/Ibu, mohon pilih lokasi penjemputannya ya.',
            ['SKPD', 'Simpang D', 'Pasir Pengaraian', 'Pekanbaru'],
        );

        $this->assertSame('interactive', $menu['message_type']);
        $this->assertSame('list', $menu['outbound_payload']['interactive']['type']);
        $this->assertSame('pickup_location:skpd', $menu['outbound_payload']['interactive']['action']['sections'][0]['rows'][0]['id']);
        $this->assertStringContainsString('1. SKPD', $menu['text']);
        $this->assertStringContainsString('3. Pasir Pengaraian', $menu['text']);
        $this->assertStringContainsString('Jika menu tidak tampil, balas angka atau nama lokasi jemput.', $menu['text']);
    }

    public function test_it_builds_dropoff_location_menu_with_numbered_fallback(): void
    {
        $service = app(BookingInteractiveMessageService::class);

        $menu = $service->dropoffLocationMenu(
            'Izin Bapak/Ibu, mohon pilih lokasi pengantarannya ya.',
            ['Bangkinang', 'Pekanbaru'],
        );

        $this->assertSame('interactive', $menu['message_type']);
        $this->assertSame('list', $menu['outbound_payload']['interactive']['type']);
        $this->assertSame('dropoff_location:bangkinang', $menu['outbound_payload']['interactive']['action']['sections'][0]['rows'][0]['id']);
        $this->assertStringContainsString('1. Bangkinang', $menu['text']);
        $this->assertStringContainsString('2. Pekanbaru', $menu['text']);
        $this->assertStringContainsString('Jika menu tidak tampil, balas angka atau nama lokasi tujuan.', $menu['text']);
    }
}
