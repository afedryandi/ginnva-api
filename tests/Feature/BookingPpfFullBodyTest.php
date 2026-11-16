<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Services\BookingRevenueSplitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PPF Full Body: Detailing + Premium Wash sudah termasuk, tanpa tagihan/pembagian pendapatan terpisah. */
class BookingPpfFullBodyTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(array $overrides = []): Booking
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true,
            'preferred_date' => now()->toDateString(),
            'status' => 'pending',
        ], $overrides));
    }

    public function test_full_body_turns_on_detailing_and_premium_wash(): void
    {
        $booking = $this->makeBooking(['ppf_scope' => Booking::PPF_SCOPE_FULL_BODY]);

        $this->assertTrue($booking->product_detailing);
        $this->assertTrue($booking->product_premium_wash);
        $this->assertTrue($booking->includesPpfBonus());
        $this->assertSame(3, $booking->salesProductCount());
    }

    public function test_partial_leaves_flags_alone(): void
    {
        $booking = $this->makeBooking(['ppf_scope' => Booking::PPF_SCOPE_PARTIAL]);

        $this->assertFalse((bool) $booking->product_detailing);
        $this->assertFalse($booking->includesPpfBonus());
    }

    public function test_revenue_goes_entirely_to_ppf_for_full_body(): void
    {
        $booking = $this->makeBooking(['ppf_scope' => Booking::PPF_SCOPE_FULL_BODY]);

        $this->assertSame(['ppf' => 10_000_000.0], BookingRevenueSplitter::shares($booking, 10_000_000));
    }

    public function test_old_booking_without_scope_is_untouched(): void
    {
        $booking = $this->makeBooking(['product_detailing' => true]);

        $this->assertNull($booking->ppf_scope);
        $this->assertSame(['ppf', 'detailing'], BookingRevenueSplitter::productKeys($booking));
    }

    public function test_interior_has_no_scope_bonus_or_parts(): void
    {
        $booking = $this->makeBooking([
            'ppf_area' => Booking::PPF_AREA_INTERIOR,
            'ppf_scope' => Booking::PPF_SCOPE_FULL_BODY,
            'ppf_parts' => ['hood'],
        ]);

        $this->assertNull($booking->ppf_scope);
        $this->assertNull($booking->ppf_parts);
        $this->assertFalse((bool) $booking->product_detailing);
    }

    public function test_part_keeps_selected_panels_only_for_part(): void
    {
        $part = $this->makeBooking(['ppf_area' => 'exterior', 'ppf_scope' => Booking::PPF_SCOPE_PARTIAL, 'ppf_parts' => ['hood', 'roof']]);
        $this->assertSame(['hood', 'roof'], $part->fresh()->ppf_parts);

        $full = $this->makeBooking(['ppf_area' => 'exterior', 'ppf_scope' => Booking::PPF_SCOPE_FULL_BODY, 'ppf_parts' => ['hood']]);
        $this->assertNull($full->ppf_parts);
    }

    public function test_service_type_label_keeps_fixed_order(): void
    {
        $this->assertSame(
            'Pelindung Cat (PPF), Detailing, Premium Wash',
            \App\Filament\Resources\BookingResource::serviceTypeLabel(['premium_wash', 'detailing', 'ppf'])
        );
        $this->assertSame('', \App\Filament\Resources\BookingResource::serviceTypeLabel([]));
    }

    public function test_form_loads_checked_services_from_flags(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

        $admin = \App\Models\User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        $booking = $this->makeBooking(['ppf_scope' => Booking::PPF_SCOPE_FULL_BODY]);

        \Livewire\Livewire::test(\App\Filament\Resources\BookingResource\Pages\EditBooking::class, ['record' => $booking->getKey()])
            ->assertFormSet(['services' => ['ppf', 'detailing', 'premium_wash']]);
    }

    public function test_scope_cleared_when_not_ppf(): void
    {
        $booking = $this->makeBooking([
            'service_type' => 'Kaca Film (Window Film)',
            'product_ppf' => false,
            'product_kaca_film' => true,
            'ppf_scope' => Booking::PPF_SCOPE_FULL_BODY,
        ]);

        $this->assertNull($booking->ppf_scope);
        $this->assertFalse((bool) $booking->product_detailing);
    }
}
