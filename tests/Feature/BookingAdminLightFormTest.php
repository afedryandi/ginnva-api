<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingResource\Pages\EditBooking;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Form Booking di panel admin untuk layanan ringan (koreksi celah Fase 2, 2026-10-22): jam harus salah satu slot, kolom jam
 * menampilkan slot tersedia/penuh, dan peringatan kekurangan installer muncul setelah simpan (tanpa memblokir).
 */
class BookingAdminLightFormTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;
    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountSeeder::class);
        Http::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        $this->store = Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true,
            'install_capacity_per_day' => 5, 'light_capacity_per_slot' => 1,
            'opening_hours' => [['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'open' => '09:00', 'close' => '18:00']],
        ]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $this->date = now()->addDays(4)->toDateString();
    }

    private function light(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'booking_number' => 'BKG-LF-' . uniqid(),
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'service_type' => 'Premium Wash',
            'product_premium_wash' => true,
            'preferred_date' => $this->date,
            'preferred_time' => '11:00 - 13:00',
            'status' => 'pending',
        ], $overrides));
    }

    private function heavy(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'booking_number' => 'BKG-LF-' . uniqid(),
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true,
            'preferred_date' => $this->date,
            'status' => 'confirmed',
        ], $overrides));
    }

    public function test_a_light_booking_rejects_a_time_outside_the_slots(): void
    {
        $booking = $this->light();

        Livewire::test(EditBooking::class, ['record' => $booking->getKey()])
            ->fillForm(['preferred_time' => '10:30'])
            ->call('save')
            ->assertHasFormErrors(['preferred_time']);

        $this->assertSame('11:00 - 13:00', $booking->fresh()->preferred_time);
    }

    public function test_a_light_booking_accepts_a_known_slot(): void
    {
        $booking = $this->light();

        Livewire::test(EditBooking::class, ['record' => $booking->getKey()])
            ->fillForm(['preferred_time' => '13:00 - 15:00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('13:00 - 15:00', $booking->fresh()->preferred_time);
    }

    public function test_a_heavy_booking_still_takes_free_text_time(): void
    {
        $booking = $this->heavy(['status' => 'pending']);

        Livewire::test(EditBooking::class, ['record' => $booking->getKey()])
            ->fillForm(['preferred_time' => 'sekitar jam 10'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_the_time_field_lists_available_and_full_slots(): void
    {
        $this->light(['status' => 'confirmed', 'preferred_time' => '09:00 - 11:00']); // kapasitas 1 -> penuh
        $mine = $this->light();

        Livewire::test(EditBooking::class, ['record' => $mine->getKey()])
            ->assertSee('Tersedia:')
            ->assertSee('Penuh: 09:00 - 11:00');
    }

    public function test_the_model_warns_when_confirmed_heavy_bookings_exceed_the_working_installers(): void
    {
        Role::findOrCreate('installer', 'web');
        $installer = User::create(['name' => 'Andi', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'join_date' => '2025-01-01', 'is_active' => true]);
        $installer->assignRole('installer');

        $first = $this->heavy();
        $this->assertNull($first->installerShortageWarning(), '1 booking berat, 1 installer: cukup.');

        $second = $this->heavy();
        $this->assertStringContainsString('1 dari 1 installer', (string) $second->installerShortageWarning());

        $this->assertNull($this->heavy(['status' => 'pending'])->installerShortageWarning(), 'Booking pending tidak diperiksa.');
        $this->assertNull($this->light(['status' => 'confirmed'])->installerShortageWarning(), 'Layanan ringan tidak diperiksa.');
    }

    public function test_saving_a_confirmed_heavy_booking_shows_the_shortage_notification(): void
    {
        Role::findOrCreate('installer', 'web');
        $installer = User::create(['name' => 'Andi', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'join_date' => '2025-01-01', 'is_active' => true]);
        $installer->assignRole('installer');

        $this->heavy();
        $second = $this->heavy();

        Livewire::test(EditBooking::class, ['record' => $second->getKey()])
            ->fillForm(['notes' => 'Catatan baru'])
            ->call('save')
            ->assertNotified('Installer mungkin kurang');
    }

    public function test_the_view_page_shows_intake_photos_qc_and_handover(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $booking = $this->heavy(['current_stage' => 'qc']);
        $manager = User::create(['name' => 'Rina Manager', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'join_date' => '2025-01-01', 'is_active' => true]);

        \App\Models\BookingIntakePhoto::create(['booking_id' => $booking->id, 'angle' => 'depan', 'path' => 'booking-intake/x.jpg']);
        \App\Models\BookingIntakePhoto::create(['booking_id' => $booking->id, 'angle' => 'kerusakan', 'path' => 'booking-intake/y.jpg', 'note' => 'Baret pintu kiri']);

        $qc = \App\Models\BookingQcResult::create(['booking_id' => $booking->id, 'inspector_user_id' => $manager->id, 'passed' => false, 'notes' => 'Perlu rapikan tepi']);
        $qc->items()->create(['service_key' => 'ppf', 'label' => 'Tepi film rapi', 'is_required' => true, 'passed' => false, 'note' => 'Kap mesin terangkat']);

        \App\Models\BookingHandover::create(['booking_id' => $booking->id, 'recorded_by_user_id' => $manager->id, 'care_instructions_given' => true, 'wet_signature_required' => true, 'wet_signature_received' => true, 'handed_over_at' => now()]);

        Livewire::test(\App\Filament\Resources\BookingResource\Pages\ViewBooking::class, ['record' => $booking->getKey()])
            ->assertSuccessful()
            ->assertSee('Penerimaan, QC, dan Serah Terima')
            ->assertSee('Baret pintu kiri')
            ->assertSee('Belum lulus')
            ->assertSee('Kap mesin terangkat')
            ->assertSee('Rina Manager')
            ->assertSee('Tanda tangan basah');
    }
}

