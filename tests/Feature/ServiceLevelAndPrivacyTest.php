<?php

namespace Tests\Feature;

use App\Filament\Pages\BookingServiceLevelReport;
use App\Models\AppSetting;
use App\Models\Booking;
use App\Models\BookingHandover;
use App\Models\BookingIntakePhoto;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingServiceLevelService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fase 5 pengerjaan ulang booking (2026-10-21): persetujuan pemberitahuan privasi (UU PDP), waktu konfirmasi dan laporan SLA /
 * tidak datang, serta retensi foto kendaraan (24 bulan).
 */
class ServiceLevelAndPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake('public');

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
    }

    private function booking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'booking_number' => 'BKG-SL-' . uniqid(),
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->addDays(3)->toDateString(),
            'status' => 'pending',
        ], $overrides));
    }

    private function forgetPrivacyCache(): void
    {
        Cache::forget('app_setting.' . AppSetting::PRIVACY_NOTICE);
    }

    // ------------------------------------------------------------- persetujuan privasi

    public function test_the_catalog_exposes_the_privacy_notice_with_a_version_that_follows_edits(): void
    {
        $first = $this->getJson('/api/booking/services')->assertOk()->json('data.privacy_notice');
        $this->assertStringContainsString('24 bulan', $first['text']);
        $this->assertSame(8, strlen($first['version']));

        AppSetting::query()->whereKey(AppSetting::PRIVACY_NOTICE)->update(['value' => 'Teks privasi baru.']);
        $this->forgetPrivacyCache();

        $second = $this->getJson('/api/booking/services')->json('data.privacy_notice');
        $this->assertSame('Teks privasi baru.', $second['text']);
        $this->assertNotSame($first['version'], $second['version']);
    }

    public function test_v2_bookings_need_the_privacy_consent_and_record_it(): void
    {
        $vehicleId = $this->actingAs($this->customer, 'customer')->postJson('/api/customer/vehicles', ['brand' => 'Toyota', 'model' => 'Avanza', 'plate' => 'B 1234 ABC'])->json('data.id');
        $payload = ['store_id' => $this->store->id, 'services' => ['ppf'], 'customer_vehicle_id' => $vehicleId, 'preferred_date' => now()->addDays(4)->toDateString()];

        $this->actingAs($this->customer, 'customer')->postJson('/api/customer/bookings', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('privacy_consent');
        $this->actingAs($this->customer, 'customer')->postJson('/api/customer/bookings', $payload + ['privacy_consent' => false])
            ->assertStatus(422);

        $id = $this->actingAs($this->customer, 'customer')->postJson('/api/customer/bookings', $payload + ['privacy_consent' => true])
            ->assertCreated()->json('data.id');

        $booking = Booking::findOrFail($id);
        $this->assertNotNull($booking->consent_at);
        $this->assertSame(AppSetting::privacyNotice()['version'], $booking->consent_version);
    }

    public function test_the_old_payload_still_works_without_a_consent_field(): void
    {
        $id = $this->actingAs($this->customer, 'customer')->postJson('/api/customer/bookings', [
            'store_id' => $this->store->id,
            'service_type' => 'Kaca Film (Window Film)',
            'product_kaca_film' => true,
            'preferred_date' => now()->addDays(4)->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->assertNull(Booking::findOrFail($id)->consent_at);
    }

    // ------------------------------------------------------------- waktu konfirmasi

    public function test_confirmed_at_is_set_once_when_the_booking_is_confirmed(): void
    {
        $booking = $this->booking();
        $this->assertNull($booking->confirmed_at);

        $booking->update(['status' => 'confirmed']);
        $first = $booking->fresh()->confirmed_at;
        $this->assertNotNull($first);

        Carbon::setTestNow(now()->addHour());
        $booking->update(['notes' => 'ubah catatan']);
        $this->assertEquals($first, $booking->fresh()->confirmed_at, 'Tidak ditimpa pada perubahan lain.');
        Carbon::setTestNow();

        $this->assertNotNull($this->booking(['status' => 'confirmed'])->confirmed_at, 'Booking yang langsung dibuat confirmed (staff) juga terisi.');
    }

    // ------------------------------------------------------------- laporan SLA

    public function test_service_level_numbers_for_a_known_set_of_bookings(): void
    {
        $day = now()->subDay();

        $make = function (string $status, ?float $waitHours, array $extra = []) use ($day) {
            $b = $this->booking(['status' => $status === 'no_show' ? 'cancelled' : $status] + $extra);
            DB::table('bookings')->where('id', $b->id)->update([
                'created_at' => $day,
                'confirmed_at' => $waitHours === null ? null : $day->copy()->addMinutes((int) round($waitHours * 60)),
            ] + ($status === 'no_show' ? ['no_show_at' => $day] : []));

            return $b;
        };

        $make('confirmed', 2);
        $make('confirmed', 6);
        $make('pending', null);
        $make('cancelled', null, ['cancel_reason' => 'Jadwal bentrok', 'cancelled_by_type' => 'customer']);
        $make('no_show', 3);
        $make('completed', 1);

        $r = app(BookingServiceLevelService::class)->summarize(now()->subDays(2), now(), null);

        $this->assertSame(6, $r['created']);
        $this->assertSame(1, $r['pending']);
        $this->assertSame(4, $r['reached_confirmed']);
        $this->assertSame(66.7, $r['conversion_percent']);
        $this->assertSame(4, $r['confirmed_measured']);
        $this->assertSame(3, $r['within_target']);
        $this->assertSame(75.0, $r['within_target_percent']);
        $this->assertSame(2.5, $r['median_wait_hours']);
        $this->assertSame(3.0, $r['avg_wait_hours']);
        $this->assertSame(1, $r['no_show']);
        $this->assertSame(1, $r['completed']);
        $this->assertSame(50.0, $r['no_show_percent']);
        $this->assertSame(1, $r['cancelled'], 'No-show tidak dihitung ganda sebagai pembatalan biasa.');
        $this->assertSame(['customer' => 1], $r['cancelled_by']);
        $this->assertSame([['reason' => 'Jadwal bentrok', 'total' => 1]], $r['cancel_reasons']);
    }

    public function test_empty_periods_return_null_percentages_not_errors(): void
    {
        $r = app(BookingServiceLevelService::class)->summarize(now()->subYear(), now()->subYear()->addDay(), null);

        $this->assertSame(0, $r['created']);
        $this->assertNull($r['conversion_percent']);
        $this->assertNull($r['median_wait_hours']);
        $this->assertNull($r['no_show_percent']);
    }

    public function test_the_report_page_renders_for_full_access(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Role::findOrCreate('super_admin', 'web');
        $admin = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => null, 'is_active' => true, 'join_date' => '2025-01-01']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        $this->booking();

        Livewire::test(BookingServiceLevelReport::class)->assertSuccessful()->assertSee('Konversi ke Terkonfirmasi');
    }

    // ------------------------------------------------------------- retensi foto

    private function photoSet(Booking $booking): array
    {
        Storage::disk('public')->put("booking-intake/{$booking->id}.jpg", 'x');
        Storage::disk('public')->put("booking-handovers/{$booking->id}.jpg", 'x');
        Storage::disk('public')->put("booking-messages/{$booking->id}.jpg", 'x');

        BookingIntakePhoto::create(['booking_id' => $booking->id, 'angle' => 'depan', 'path' => "booking-intake/{$booking->id}.jpg"]);
        BookingHandover::create(['booking_id' => $booking->id, 'care_instructions_given' => true, 'final_photo_path' => "booking-handovers/{$booking->id}.jpg", 'handed_over_at' => now()]);
        $message = $booking->messages()->create(['sender_type' => 'admin', 'type' => 'photo']);
        $message->photos()->create(['path' => "booking-messages/{$booking->id}.jpg"]);

        return ["booking-intake/{$booking->id}.jpg", "booking-handovers/{$booking->id}.jpg", "booking-messages/{$booking->id}.jpg"];
    }

    public function test_old_finished_bookings_lose_their_photos_but_keep_the_booking(): void
    {
        $old = $this->booking(['status' => 'completed', 'preferred_date' => now()->subMonths(30)->toDateString()]);
        $files = $this->photoSet($old);

        $this->artisan('bookings:prune-photos')->assertSuccessful();

        foreach ($files as $file) {
            Storage::disk('public')->assertMissing($file);
        }
        $this->assertSame(0, BookingIntakePhoto::where('booking_id', $old->id)->count());
        $this->assertNull(BookingHandover::where('booking_id', $old->id)->first()->final_photo_path);
        $this->assertNotNull(Booking::find($old->id), 'Data teks booking tetap utuh.');
    }

    public function test_recent_and_unfinished_bookings_keep_their_photos(): void
    {
        $recent = $this->booking(['status' => 'completed', 'preferred_date' => now()->subMonths(6)->toDateString()]);
        $oldButOpen = $this->booking(['status' => 'confirmed', 'preferred_date' => now()->subMonths(30)->toDateString()]);
        $recentFiles = $this->photoSet($recent);
        $openFiles = $this->photoSet($oldButOpen);

        $this->artisan('bookings:prune-photos')->assertSuccessful();

        foreach (array_merge($recentFiles, $openFiles) as $file) {
            Storage::disk('public')->assertExists($file);
        }
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $old = $this->booking(['status' => 'cancelled', 'preferred_date' => now()->subMonths(30)->toDateString()]);
        $files = $this->photoSet($old);

        $this->artisan('bookings:prune-photos', ['--dry-run' => true])->assertSuccessful();

        foreach ($files as $file) {
            Storage::disk('public')->assertExists($file);
        }
        $this->assertSame(1, BookingIntakePhoto::where('booking_id', $old->id)->count());
    }
}
