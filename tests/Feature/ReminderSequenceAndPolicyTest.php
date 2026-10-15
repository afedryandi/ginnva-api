<?php

namespace Tests\Feature;

use App\Filament\Resources\AppSettingResource;
use App\Filament\Resources\AppSettingResource\Pages\ListAppSettings;
use App\Models\AppSetting;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingRescheduleService;
use App\Services\PushNotificationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fase 4 pengerjaan ulang booking (2026-10-20): urutan pengingat pelanggan (H-2, H-1, pagi hari-H) yang menyebut layanan/toko/
 * tanggal/jam dan membuka Booking Saya, serta teks kebijakan pembatalan yang diedit admin.
 */
class ReminderSequenceAndPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Kemang', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
    }

    private function booking(int $daysAhead, array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'booking_number' => 'BKG-RM-' . uniqid(),
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'service_type' => 'Premium Wash',
            'product_premium_wash' => true,
            'preferred_date' => now()->addDays($daysAhead)->toDateString(),
            'preferred_time' => '09:00 - 11:00',
            'status' => 'confirmed',
        ], $overrides));
    }

    /** Menangkap judul, isi, dan data tiap push pelanggan. */
    private function capturePushes(): \ArrayObject
    {
        $sent = new \ArrayObject();
        $push = $this->mock(PushNotificationService::class);
        $push->shouldReceive('sendToCustomer')->andReturnUsing(function ($customerId, $title, $body, $data = []) use ($sent) {
            $sent[] = compact('customerId', 'title', 'body', 'data');
        });
        $push->shouldReceive('sendToUsers')->andReturnNull();

        return $sent;
    }

    public function test_h2_reminder_names_the_service_store_and_time_and_opens_my_bookings(): void
    {
        $booking = $this->booking(2);
        $sent = $this->capturePushes();

        $this->artisan('bookings:daily-reminders')->assertSuccessful();

        $this->assertCount(1, $sent);
        $this->assertSame('booking_reminder_h2', $sent[0]['data']['type']);
        $this->assertSame('/account/my-bookings', $sent[0]['data']['route']);
        $this->assertStringContainsString('Premium Wash', $sent[0]['body']);
        $this->assertStringContainsString('Toko Kemang', $sent[0]['body']);
        $this->assertStringContainsString('09:00 - 11:00', $sent[0]['body']);
        $this->assertStringContainsString('Kebijakan:', $sent[0]['body'], 'H-2 menyertakan kalimat kebijakan batal.');
        $this->assertSame(['reschedule' => (string) $booking->id], $sent[0]['data']['params'], 'Membuka langsung ajuan jadwal ulang booking ini.');
        $this->assertNotNull($booking->fresh()->h2_reminder_sent_at);

        // Run kedua tidak mengirim ulang.
        $this->artisan('bookings:daily-reminders')->assertSuccessful();
        $this->assertCount(1, $sent);
    }

    public function test_day_of_reminder_goes_out_on_the_morning_run_only(): void
    {
        $booking = $this->booking(0);
        $sent = $this->capturePushes();

        $this->artisan('bookings:daily-reminders', ['--h1-only' => true])->assertSuccessful();
        $this->assertCount(0, $sent, 'Run sore (--h1-only) tidak mengirim pengingat hari-H.');

        $this->artisan('bookings:daily-reminders')->assertSuccessful();
        $this->assertCount(1, $sent);
        $this->assertSame('booking_reminder_dayof', $sent[0]['data']['type']);
        $this->assertNotNull($booking->fresh()->dayof_reminder_sent_at);
    }

    public function test_h1_reminder_is_enriched_and_opens_my_bookings(): void
    {
        $this->booking(1);
        $sent = $this->capturePushes();

        $this->artisan('bookings:daily-reminders', ['--h1-only' => true])->assertSuccessful();

        $this->assertCount(1, $sent);
        $this->assertSame('booking_reminder_h1', $sent[0]['data']['type']);
        $this->assertSame('/account/my-bookings', $sent[0]['data']['route']);
        $this->assertStringContainsString('Toko Kemang', $sent[0]['body']);
        $this->assertArrayHasKey('reschedule', $sent[0]['data']['params']);
    }

    public function test_the_confirmation_push_carries_the_cancellation_policy(): void
    {
        // Mock dipasang SEBELUM model dipakai: observer menerima layanan push saat model pertama kali di-boot.
        $sent = $this->capturePushes();
        $booking = $this->booking(3, ['status' => 'pending']);

        $booking->update(['status' => 'confirmed']);

        $confirm = collect($sent->getArrayCopy())->firstWhere('data.type', 'booking_confirmed');
        $this->assertNotNull($confirm);
        $this->assertStringContainsString('Kebijakan:', $confirm['body']);
    }

    public function test_only_confirmed_bookings_in_the_right_window_are_reminded(): void
    {
        $this->booking(2, ['status' => 'pending']);
        $this->booking(3);
        $this->booking(2, ['status' => 'cancelled']);
        $sent = $this->capturePushes();

        $this->artisan('bookings:daily-reminders')->assertSuccessful();

        $this->assertCount(0, $sent);
    }

    public function test_reschedule_resets_all_reminder_flags(): void
    {
        $booking = $this->booking(5);
        DB::table('bookings')->where('id', $booking->id)->update([
            'h1_reminder_sent_at' => now(), 'h2_reminder_sent_at' => now(), 'dayof_reminder_sent_at' => now(),
        ]);

        app(BookingRescheduleService::class)->apply($booking->fresh(), now()->addDays(8));

        $fresh = $booking->fresh();
        $this->assertNull($fresh->h1_reminder_sent_at);
        $this->assertNull($fresh->h2_reminder_sent_at);
        $this->assertNull($fresh->dayof_reminder_sent_at);
    }

    public function test_reminder_flags_are_not_exposed_to_customers(): void
    {
        $booking = $this->booking(2);
        DB::table('bookings')->where('id', $booking->id)->update(['h2_reminder_sent_at' => now(), 'dayof_reminder_sent_at' => now()]);

        $row = $this->actingAs($this->customer, 'customer')->getJson('/api/customer/bookings')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('h2_reminder_sent_at', $row);
        $this->assertArrayNotHasKey('dayof_reminder_sent_at', $row);
    }

    // ------------------------------------------------------------- kebijakan pembatalan

    public function test_catalog_returns_the_cancellation_policy_and_follows_edits(): void
    {
        $default = $this->getJson('/api/booking/services')->assertOk()->json('data.cancellation_policy');
        $this->assertStringContainsString('Tidak ada biaya pembatalan', $default);

        AppSetting::query()->whereKey(AppSetting::CANCELLATION_POLICY)->update(['value' => 'Batal paling lambat H-1.']);
        \Illuminate\Support\Facades\Cache::forget('app_setting.' . AppSetting::CANCELLATION_POLICY);

        $this->assertSame('Batal paling lambat H-1.', $this->getJson('/api/booking/services')->json('data.cancellation_policy'));

        // Nilai dikosongkan -> kembali ke bawaan, bukan teks kosong.
        AppSetting::query()->whereKey(AppSetting::CANCELLATION_POLICY)->update(['value' => '']);
        \Illuminate\Support\Facades\Cache::forget('app_setting.' . AppSetting::CANCELLATION_POLICY);
        $this->assertStringContainsString('Tidak ada biaya pembatalan', $this->getJson('/api/booking/services')->json('data.cancellation_policy'));
    }

    public function test_admin_menu_lists_the_policy_and_only_full_access_can_edit(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Role::findOrCreate('super_admin', 'web');
        $admin = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => null, 'is_active' => true, 'join_date' => '2025-01-01']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        Livewire::test(ListAppSettings::class)->assertSuccessful()->assertSee('Kebijakan Pembatalan Booking');
        $this->assertTrue(AppSettingResource::canEdit(AppSetting::first()));
        $this->assertFalse(AppSettingResource::canCreate());

        Role::findOrCreate('store_manager', 'web');
        $manager = User::create(['name' => 'SM', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => null, 'is_active' => true, 'join_date' => '2025-01-01']);
        $manager->assignRole('store_manager');
        $this->actingAs($manager, 'web');
        $this->assertFalse(AppSettingResource::canEdit(AppSetting::first()));
    }
}
