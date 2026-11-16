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
        ]);

        $this->assertNull($booking->ppf_scope);
        $this->assertFalse((bool) $booking->product_detailing);
    }

    public function test_part_options_follow_service_scope_and_area(): void
    {
        $R = \App\Filament\Resources\BookingResource::class;

        // Full Body dan Interior: hanya "Seluruh Bagian PPF" (untuk menambah lebih dari satu varian), tanpa panel satu per satu.
        $this->assertSame([Booking::PPF_ALL], array_keys($R::partOptions(['ppf'], Booking::PPF_SCOPE_FULL_BODY, 'exterior')['PPF']));
        $this->assertSame([Booking::PPF_ALL], array_keys($R::partOptions(['ppf'], Booking::PPF_SCOPE_PARTIAL, Booking::PPF_AREA_INTERIOR)['PPF']));
        $this->assertCount(1 + count(Booking::PPF_PARTS), $R::partOptions(['ppf'], Booking::PPF_SCOPE_PARTIAL, 'exterior')['PPF']);
        $this->assertSame(['Window Film'], array_keys($R::partOptions(['kaca_film'], null, null)));
        $this->assertContains(Booking::WF_ALL, array_keys($R::partOptions(['kaca_film'], null, null)['Window Film']));
        $this->assertSame(['PPF', 'Window Film'], array_keys($R::partOptions(['ppf', 'kaca_film'], Booking::PPF_SCOPE_PARTIAL, 'exterior')));
        $this->assertSame([], $R::partOptions(['detailing'], null, null));
        $this->assertSame('window_film', $R::productTypeForPart('Kaca Depan'));
        $this->assertSame('window_film', $R::productTypeForPart(Booking::WF_ALL));
        $this->assertSame('ppf', $R::productTypeForPart('Hood'));
        $this->assertSame('ppf', $R::productTypeForPart(Booking::PPF_ALL));
    }

    public function test_without_ppf_parts_keeps_window_film_rows(): void
    {
        $rows = [['position' => 'Hood'], ['position' => 'Kaca Depan'], ['position' => 'Roof']];

        $this->assertSame([1 => ['position' => 'Kaca Depan']], \App\Filament\Resources\BookingResource::withoutPpfParts($rows));
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
