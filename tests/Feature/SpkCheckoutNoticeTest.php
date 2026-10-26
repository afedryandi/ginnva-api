<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\SpkService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kendaraan keluar di SPK tidak mengubah status booking (keputusan 2026-09-25), jadi notifikasi mengingatkan bila booking masih Dikonfirmasi
 * dan daftar booking punya filter untuk menemukan yang terlewat.
 */
class SpkCheckoutNoticeTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
    }

    private function bookingWithSpk(string $status, bool $checkedOut): array
    {
        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(), 'customer_id' => $this->customer->id, 'store_id' => $this->store->id,
            'service_type' => 'Pelindung Cat (PPF)', 'product_ppf' => true,
            'preferred_date' => now()->addDays(3)->toDateString(), 'status' => 'confirmed',
        ]);
        $spk = app(SpkService::class)->createFromBooking($booking, null);

        if ($checkedOut) {
            $spk->update(['checked_out_at' => now()]);
        }

        $booking->update(['status' => $status]);

        return [$booking->fresh(), $spk->fresh()];
    }

    public function test_the_checkout_notice_warns_only_while_the_booking_is_still_confirmed(): void
    {
        [$confirmed, $spkConfirmed] = $this->bookingWithSpk('confirmed', true);
        [, $spkCompleted] = $this->bookingWithSpk('completed', true);

        $this->assertStringContainsString('sudah ditandai kendaraan keluar', $spkConfirmed->checkoutNotice());
        $this->assertStringContainsString("Booking {$confirmed->booking_number} masih berstatus Dikonfirmasi", $spkConfirmed->checkoutNotice());
        $this->assertStringNotContainsString('masih berstatus Dikonfirmasi', $spkCompleted->checkoutNotice());
    }

    public function test_checking_out_does_not_change_the_booking_status(): void
    {
        [$booking, $spk] = $this->bookingWithSpk('confirmed', false);

        $spk->update(['checked_out_at' => now()]);

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_the_booking_filter_finds_checked_out_vehicles_whose_booking_is_still_confirmed(): void
    {
        [$stuck] = $this->bookingWithSpk('confirmed', true);
        [$notOut] = $this->bookingWithSpk('confirmed', false);
        [$done] = $this->bookingWithSpk('completed', true);

        Livewire::test(ListBookings::class)
            ->filterTable('spk_keluar_belum_selesai')
            ->assertCanSeeTableRecords([$stuck])
            ->assertCanNotSeeTableRecords([$notOut, $done]);
    }
}
