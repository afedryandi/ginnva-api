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
        $this->assertSame([Booking::PPF_ALL], array_keys($R::partOptions(['ppf'], Booking::PPF_SCOPE_FULL_BODY, true, false)['PPF']));
        // Interior saja: tanpa panel eksterior, tetapi ada bagian "Interior" untuk varian produk interior.
        $this->assertSame([Booking::PPF_ALL, Booking::PPF_INTERIOR_PART], array_keys($R::partOptions(['ppf'], Booking::PPF_SCOPE_PARTIAL, false, true)['PPF']));
        // Eksterior dan Interior bersamaan: panel (untuk Part) ditambah bagian Interior.
        $this->assertCount(2 + count(Booking::PPF_PARTS), $R::partOptions(['ppf'], Booking::PPF_SCOPE_PARTIAL, true, true)['PPF']);
        $this->assertCount(1 + count(Booking::PPF_PARTS), $R::partOptions(['ppf'], Booking::PPF_SCOPE_PARTIAL, true, false)['PPF']);
        $this->assertSame(['Window Film'], array_keys($R::partOptions(['kaca_film'], null)));
        $this->assertContains(Booking::WF_ALL, array_keys($R::partOptions(['kaca_film'], null)['Window Film']));
        $this->assertSame(['PPF', 'Window Film'], array_keys($R::partOptions(['ppf', 'kaca_film'], Booking::PPF_SCOPE_PARTIAL, true, false)));
        $this->assertSame([], $R::partOptions(['detailing'], null));
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

    public function test_exterior_and_interior_can_be_chosen_together(): void
    {
        $booking = $this->makeBooking(['ppf_exterior' => true, 'ppf_interior' => true, 'ppf_scope' => Booking::PPF_SCOPE_FULL_BODY]);

        $this->assertSame(Booking::PPF_AREA_BOTH, $booking->ppf_area, 'ppf_area diturunkan dari dua penanda.');
        $this->assertSame('Eksterior & Interior', $booking->ppfAreaLabel());
        $this->assertSame(Booking::DEFAULT_DURATION_DAYS_PPF, (int) $booking->duration_days, 'Interior tidak menambah hari kerja: Eksterior dan Interior sekaligus mengikuti perkiraan PPF (4 hari).');
        $this->assertSame(4, Booking::DEFAULT_DURATION_DAYS_PPF);
        $this->assertSame(Booking::PPF_SCOPE_FULL_BODY, $booking->ppf_scope, 'Cakupan tetap berlaku untuk bagian Eksterior.');
        $this->assertTrue($booking->includesPpfBonus(), 'Bonus untuk Full Body di bagian Eksterior.');
    }

    public function test_interior_only_takes_one_day_and_has_no_scope_or_bonus(): void
    {
        $booking = $this->makeBooking(['ppf_interior' => true, 'ppf_scope' => Booking::PPF_SCOPE_FULL_BODY]);

        $this->assertSame(Booking::PPF_AREA_INTERIOR, $booking->ppf_area);
        $this->assertSame('Interior', $booking->ppfAreaLabel());
        $this->assertSame(1, (int) $booking->duration_days);
        $this->assertNull($booking->ppf_scope);
        $this->assertFalse($booking->includesPpfBonus());
    }

    public function test_exterior_only_and_unspecified_ppf_use_the_four_day_estimate_and_the_bonus_rule(): void
    {
        $exterior = $this->makeBooking(['ppf_exterior' => true, 'ppf_scope' => Booking::PPF_SCOPE_FULL_BODY]);
        $this->assertSame(4, (int) $exterior->duration_days);
        $this->assertSame('Eksterior', $exterior->ppfAreaLabel());
        $this->assertTrue($exterior->includesPpfBonus());

        $unspecified = $this->makeBooking(['ppf_scope' => Booking::PPF_SCOPE_FULL_BODY]);
        $this->assertSame(4, (int) $unspecified->duration_days, 'Belum menentukan area dihitung Eksterior (perilaku lama).');
        $this->assertTrue($unspecified->includesPpfBonus());
    }

    public function test_the_legacy_ppf_area_value_still_sets_the_two_flags(): void
    {
        $interior = $this->makeBooking(['ppf_area' => Booking::PPF_AREA_INTERIOR]);
        $this->assertFalse((bool) $interior->ppf_exterior);
        $this->assertTrue((bool) $interior->ppf_interior);

        $both = $this->makeBooking(['ppf_area' => Booking::PPF_AREA_BOTH]);
        $this->assertTrue((bool) $both->ppf_exterior);
        $this->assertTrue((bool) $both->ppf_interior);
    }

    public function test_the_default_duration_follows_the_areas(): void
    {
        $this->assertSame(1, Booking::defaultDurationDays(false, false, false));
        $this->assertSame(4, Booking::defaultDurationDays(true, true, false));
        $this->assertSame(1, Booking::defaultDurationDays(true, false, true));
        $this->assertSame(4, Booking::defaultDurationDays(true, true, true), 'Interior tidak menambah hari kerja.');
        $this->assertSame(4, Booking::defaultDurationDays(true, false, false));
    }

    public function test_turning_ppf_off_clears_the_areas(): void
    {
        $booking = $this->makeBooking(['ppf_exterior' => true, 'ppf_interior' => true]);
        $booking->update(['product_ppf' => false]);

        $fresh = $booking->fresh();
        $this->assertFalse((bool) $fresh->ppf_exterior);
        $this->assertFalse((bool) $fresh->ppf_interior);
        $this->assertNull($fresh->ppf_area);
    }
}
