<?php

namespace Tests\Feature;

use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\BookingRescheduleRequest;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sisi customer "Booking Instalasi": toko & ketersediaan tanggal (publik),
 * membuat booking dengan semua aturan penolakan (toko tutup/diblokir/penuh,
 * duplikat, batas 3 pending), batal langsung, dan pengajuan jadwal ulang.
 */
class BookingCustomerFlowTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
    }

    private function date(int $daysAhead): string
    {
        return now()->addDays($daysAhead)->toDateString();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'store_id'       => $this->store->id,
            'service_type'   => 'Pelindung Cat (PPF)',
            'product_ppf'    => true,
            'preferred_date' => $this->date(5),
        ], $overrides);
    }

    private function asCustomer(?Customer $customer = null)
    {
        return $this->actingAs($customer ?? $this->customer, 'customer');
    }

    private function booking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id'    => $this->customer->id,
            'store_id'       => $this->store->id,
            'service_type'   => 'Pelindung Cat (PPF)',
            'product_ppf'    => true,
            'preferred_date' => $this->date(5),
            'status'         => 'pending',
        ], $overrides));
    }

    // ------------------------------------------------------------- membuat booking

    public function test_customer_creates_pending_booking_with_identity_from_token(): void
    {
        $response = $this->asCustomer()->postJson('/api/customer/bookings', $this->payload())->assertStatus(201);

        $booking = Booking::findOrFail($response->json('data.id'));
        $this->assertSame('pending', $booking->status);
        $this->assertSame('app', $booking->source);
        $this->assertSame($this->customer->id, $booking->customer_id);
        $this->assertTrue((bool) $booking->product_ppf);
    }

    public function test_booking_requires_login(): void
    {
        $this->postJson('/api/customer/bookings', $this->payload())->assertStatus(401);
    }

    public function test_booking_validation_rules(): void
    {
        $this->asCustomer()->postJson('/api/customer/bookings', $this->payload(['store_id' => null]))->assertStatus(422);
        $this->asCustomer()->postJson('/api/customer/bookings', $this->payload(['preferred_date' => $this->date(-1)]))->assertStatus(422);
        $this->asCustomer()->postJson('/api/customer/bookings', $this->payload(['service_type' => 'Lainnya']))->assertStatus(422); // wajib catatan
        $this->asCustomer()->postJson('/api/customer/bookings', $this->payload(['service_type' => 'Lainnya', 'notes' => 'Ganti lampu']))->assertStatus(201);

        $inactive = Store::create(['city' => 'Bandung', 'address' => 'Jl. Test 2', 'name' => 'Toko Tutup Permanen', 'is_active' => false]);
        $this->asCustomer()->postJson('/api/customer/bookings', $this->payload(['store_id' => $inactive->id]))->assertStatus(422);
    }

    public function test_booking_on_weekly_closed_day_or_blocked_date_is_rejected(): void
    {
        $sunday = now()->next(\Carbon\Carbon::SUNDAY);
        $this->store->update(['opening_hours' => [['days' => ['sun'], 'closed' => true]]]);

        $this->asCustomer()->postJson('/api/customer/bookings', $this->payload(['preferred_date' => $sunday->toDateString()]))
            ->assertStatus(422);

        BlockedDate::create(['store_id' => $this->store->id, 'date' => $this->date(8), 'reason' => 'Renovasi']);
        $this->asCustomer()->postJson('/api/customer/bookings', $this->payload(['preferred_date' => $this->date(8)]))
            ->assertStatus(422);
    }

    public function test_booking_on_full_date_is_rejected(): void
    {
        $this->store->update(['install_capacity_per_day' => 1]);
        $other = Customer::create(['name' => 'Siti', 'phone_number' => '0813' . random_int(1000000, 9999999)]);
        $this->booking(['customer_id' => $other->id, 'status' => 'confirmed', 'preferred_date' => $this->date(5)]);

        $this->asCustomer()->postJson('/api/customer/bookings', $this->payload(['preferred_date' => $this->date(5)]))
            ->assertStatus(422);
        // Booking PPF 3 hari menempati hari berikutnya juga; tanggal jauh tetap bisa.
        $this->asCustomer()->postJson('/api/customer/bookings', $this->payload(['preferred_date' => $this->date(12)]))
            ->assertStatus(201);
    }

    public function test_exact_duplicate_booking_is_rejected(): void
    {
        $this->asCustomer()->postJson('/api/customer/bookings', $this->payload())->assertStatus(201);
        $this->asCustomer()->postJson('/api/customer/bookings', $this->payload())->assertStatus(422);
        $this->assertSame(1, Booking::where('customer_id', $this->customer->id)->count());
    }

    public function test_customer_is_limited_to_three_pending_bookings(): void
    {
        foreach ([4, 5, 6] as $days) {
            $this->asCustomer()->postJson('/api/customer/bookings', $this->payload(['preferred_date' => $this->date($days)]))->assertStatus(201);
        }

        $this->asCustomer()->postJson('/api/customer/bookings', $this->payload(['preferred_date' => $this->date(7)]))->assertStatus(422);
        $this->assertSame(3, Booking::where('customer_id', $this->customer->id)->count());
    }

    // ------------------------------------------------------------- toko & ketersediaan

    public function test_store_list_shows_only_active_stores(): void
    {
        Store::create(['city' => 'Bandung', 'address' => 'Jl. Test 2', 'name' => 'Toko Nonaktif', 'is_active' => false]);

        $names = collect($this->getJson('/api/stores')->assertSuccessful()->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Toko A'));
        $this->assertFalse($names->contains('Toko Nonaktif'));
    }

    public function test_public_availability_endpoints_report_blocked_and_full_dates(): void
    {
        $this->store->update(['install_capacity_per_day' => 1]);
        BlockedDate::create(['store_id' => $this->store->id, 'date' => $this->date(8), 'reason' => 'Renovasi']);
        $other = Customer::create(['name' => 'Siti', 'phone_number' => '0813' . random_int(1000000, 9999999)]);
        $this->booking(['customer_id' => $other->id, 'status' => 'confirmed', 'preferred_date' => $this->date(5)]);

        $this->assertContains($this->date(8), $this->getJson("/api/stores/{$this->store->id}/blocked-dates")->json('data'));
        $this->assertContains($this->date(5), $this->getJson("/api/stores/{$this->store->id}/full-dates")->json('data'));

        $unavailable = $this->getJson("/api/stores/{$this->store->id}/unavailable-dates")->assertSuccessful()->json('data');
        $this->assertContains($this->date(5), $unavailable);
        $this->assertContains($this->date(8), $unavailable);
    }

    public function test_availability_for_inactive_store_is_not_found(): void
    {
        $inactive = Store::create(['city' => 'Bandung', 'address' => 'Jl. Test 2', 'name' => 'Toko Nonaktif', 'is_active' => false]);

        $this->getJson("/api/stores/{$inactive->id}/full-dates")->assertStatus(404);
        $this->getJson("/api/stores/{$inactive->id}/unavailable-dates")->assertStatus(404);
    }

    public function test_own_booking_does_not_make_its_own_date_unavailable_for_the_owner_only(): void
    {
        $this->store->update(['install_capacity_per_day' => 1]);
        $mine = $this->booking(['status' => 'confirmed', 'preferred_date' => $this->date(5)]);

        // Tamu: tanggal itu penuh (booking_id diabaikan tanpa login).
        $this->assertContains($this->date(5), $this->getJson("/api/stores/{$this->store->id}/unavailable-dates?booking_id={$mine->id}")->json('data'));

        // Pemilik: booking sendiri dikecualikan dari hitungan.
        $this->assertNotContains($this->date(5), $this->asCustomer()
            ->getJson("/api/stores/{$this->store->id}/unavailable-dates?booking_id={$mine->id}")->json('data'));
    }

    // ------------------------------------------------------------- batal & jadwal ulang

    public function test_customer_can_cancel_pending_booking_directly_but_not_confirmed(): void
    {
        $pending = $this->booking();
        $this->asCustomer()->postJson("/api/customer/bookings/{$pending->id}/cancel", ['reason' => 'Salah tanggal'])->assertSuccessful();

        $fresh = $pending->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('Salah tanggal', $fresh->cancel_reason);
        $this->assertSame('customer', $fresh->cancelled_by_type);

        $confirmed = $this->booking(['status' => 'confirmed', 'preferred_date' => $this->date(9)]);
        $this->asCustomer()->postJson("/api/customer/bookings/{$confirmed->id}/cancel")->assertStatus(422);
        $this->assertSame('confirmed', $confirmed->fresh()->status);
    }

    public function test_customer_cannot_touch_another_customers_booking(): void
    {
        $stranger = Customer::create(['name' => 'Orang Lain', 'phone_number' => '0814' . random_int(1000000, 9999999)]);
        $booking = $this->booking();

        $this->asCustomer($stranger)->postJson("/api/customer/bookings/{$booking->id}/cancel")->assertStatus(404);
        $this->asCustomer($stranger)->postJson("/api/customer/bookings/{$booking->id}/reschedule-request", ['requested_date' => $this->date(9)])->assertStatus(404);
    }

    public function test_reschedule_request_rules(): void
    {
        $booking = $this->booking(['preferred_date' => $this->date(5)]);

        // Tanggal sama dengan jadwal sekarang ditolak.
        $this->asCustomer()->postJson("/api/customer/bookings/{$booking->id}/reschedule-request", ['requested_date' => $this->date(5)])->assertStatus(422);

        // Diterima, lalu pengajuan kedua selama yang pertama menunggu ditolak.
        $this->asCustomer()->postJson("/api/customer/bookings/{$booking->id}/reschedule-request", ['requested_date' => $this->date(9), 'reason' => 'Ada acara'])->assertSuccessful();
        $this->assertSame(1, BookingRescheduleRequest::where('booking_id', $booking->id)->where('status', 'pending')->count());

        $this->asCustomer()->postJson("/api/customer/bookings/{$booking->id}/reschedule-request", ['requested_date' => $this->date(10)])->assertStatus(422);
    }

    public function test_reschedule_to_full_date_is_rejected(): void
    {
        $this->store->update(['install_capacity_per_day' => 1]);
        $other = Customer::create(['name' => 'Siti', 'phone_number' => '0813' . random_int(1000000, 9999999)]);
        $this->booking(['customer_id' => $other->id, 'status' => 'confirmed', 'preferred_date' => $this->date(9)]);
        $mine = $this->booking(['preferred_date' => $this->date(5)]);

        $this->asCustomer()->postJson("/api/customer/bookings/{$mine->id}/reschedule-request", ['requested_date' => $this->date(9)])
            ->assertStatus(422);
    }
}
