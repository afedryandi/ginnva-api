<?php

namespace Tests\Feature;

use App\Filament\Pages\CapacityCalendar;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\StoreCapacityOverride;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Kalender Kapasitas Instalasi: render, override per tanggal & rentang,
 * hapus override, pengaman toko (staf tidak bisa mengubah toko lain), dan
 * efeknya ke kapasitas booking (Booking::capacityForDate / fullDatesInRange).
 */
class CapacityCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('store_manager', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true, 'install_capacity_per_day' => 3]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true, 'install_capacity_per_day' => 3]);

        $this->admin = $this->user('super_admin', null);
        $this->actingAs($this->admin, 'web');
    }

    private function user(string $role, ?int $storeId, ?array $menuAccess = null): User
    {
        $user = User::create([
            'name' => ucfirst($role) . ' ' . uniqid(),
            'email' => uniqid() . '@test.local',
            'password' => 'x',
            'store_id' => $storeId,
            'menu_access' => $menuAccess,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function date(int $daysAhead): string
    {
        return now()->addDays($daysAhead)->toDateString();
    }

    /**
     * Admin: halaman otomatis memilih toko aktif pertama menurut nama (Toko A).
     * Staf toko: terkunci ke tokonya sendiri.
     */
    private function page(array $ignored = [])
    {
        return Livewire::test(CapacityCalendar::class);
    }

    public function test_calendar_renders_full_weeks_for_admin(): void
    {
        $component = $this->page(['storeId' => $this->storeA->id])->assertSuccessful();

        $days = $component->instance()->getCalendarDays();
        $this->assertNotEmpty($days);
        $this->assertSame(0, count($days) % 7, 'Grid kalender harus kelipatan 7 hari penuh.');
        $this->assertSame(3, $days[0]['capacity']);
    }

    public function test_page_access_follows_booking_menu_access(): void
    {
        $this->assertTrue(CapacityCalendar::canAccess());

        $this->actingAs($this->user('store_manager', $this->storeA->id, ['SomeOtherResource']), 'web');
        $this->assertFalse(CapacityCalendar::canAccess());

        $this->actingAs($this->user('store_manager', $this->storeA->id), 'web');
        $this->assertTrue(CapacityCalendar::canAccess());
    }

    public function test_calendar_marks_closed_days_and_counts_confirmed_usage(): void
    {
        $this->storeA->update(['opening_hours' => [['days' => ['sun'], 'closed' => true]]]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => $this->storeA->id,
            'service_type' => 'Kaca Film (Window Film)', 'product_kaca_film' => true,
            'preferred_date' => $this->date(2), 'status' => 'confirmed', 'duration_days' => 1,
        ]);

        $days = collect($this->page(['storeId' => $this->storeA->id])->instance()->getCalendarDays());

        foreach ($days->filter(fn ($d) => Carbon::parse($d['date'])->dayOfWeek === 0) as $sunday) {
            $this->assertTrue($sunday['closed'], "Minggu {$sunday['date']} harus tertandai tutup.");
        }

        $bookedDay = $days->firstWhere('date', $booking->preferred_date->toDateString());
        // Hari itu bisa di luar bulan yang sedang tampil; hanya periksa jika ada di grid.
        if ($bookedDay && ! $bookedDay['closed']) {
            $this->assertSame(1, $bookedDay['used']);
        }
    }

    public function test_saving_and_clearing_a_single_date_override(): void
    {
        $date = $this->date(3);

        $component = $this->page(['storeId' => $this->storeA->id])
            ->call('openDay', $date)
            ->assertSet('editingDate', $date)
            ->assertSet('editingCapacity', 3)
            ->set('editingCapacity', 5)
            ->call('saveCapacity')
            ->assertSet('editingDate', null);

        $override = StoreCapacityOverride::where('store_id', $this->storeA->id)->whereDate('date', $date)->firstOrFail();
        $this->assertSame(5, $override->capacity);
        $this->assertSame($this->admin->id, $override->updated_by);
        $this->assertSame(5, Booking::capacityForDate($this->storeA->id, Carbon::parse($date)));

        $component->call('openDay', $date)->call('clearOverride');

        $this->assertSame(0, StoreCapacityOverride::where('store_id', $this->storeA->id)->count());
        $this->assertSame(3, Booking::capacityForDate($this->storeA->id, Carbon::parse($date)));
    }

    public function test_past_dates_cannot_be_opened_for_editing(): void
    {
        $this->page(['storeId' => $this->storeA->id])
            ->call('openDay', now()->subDays(2)->toDateString())
            ->assertSet('editingDate', null);
    }

    public function test_capacity_below_one_is_ignored(): void
    {
        $this->page(['storeId' => $this->storeA->id])
            ->call('openDay', $this->date(3))
            ->set('editingCapacity', 0)
            ->call('saveCapacity');

        $this->assertSame(0, StoreCapacityOverride::count());
    }

    public function test_range_editor_applies_to_every_future_date_and_skips_past(): void
    {
        $this->page(['storeId' => $this->storeA->id])
            ->call('openRangeEditor')
            ->set('rangeFrom', now()->subDays(2)->toDateString())
            ->set('rangeTo', $this->date(4))
            ->set('rangeCapacity', 2)
            ->call('applyRangeCapacity')
            ->assertSet('rangeEditorOpen', false);

        // Hari ini sampai +4 = 5 tanggal; 2 hari lampau dilewati.
        $this->assertSame(5, StoreCapacityOverride::where('store_id', $this->storeA->id)->count());
        $this->assertSame(0, StoreCapacityOverride::whereDate('date', '<', now()->toDateString())->count());
        $this->assertSame(2, Booking::capacityForDate($this->storeA->id, now()->addDays(2)));
    }

    public function test_range_editor_is_capped_at_90_days_and_ignores_reversed_range(): void
    {
        $this->page(['storeId' => $this->storeA->id])
            ->set('rangeFrom', $this->date(1))
            ->set('rangeTo', $this->date(300))
            ->set('rangeCapacity', 2)
            ->call('applyRangeCapacity');
        $this->assertSame(90, StoreCapacityOverride::where('store_id', $this->storeA->id)->count());

        StoreCapacityOverride::query()->delete();

        $this->page(['storeId' => $this->storeA->id])
            ->set('rangeFrom', $this->date(10))
            ->set('rangeTo', $this->date(5))
            ->set('rangeCapacity', 2)
            ->call('applyRangeCapacity');
        $this->assertSame(0, StoreCapacityOverride::count());
    }

    public function test_clear_all_overrides_removes_future_only(): void
    {
        StoreCapacityOverride::create(['store_id' => $this->storeA->id, 'date' => now()->subDays(3)->toDateString(), 'capacity' => 1, 'updated_by' => $this->admin->id]);
        StoreCapacityOverride::create(['store_id' => $this->storeA->id, 'date' => $this->date(2), 'capacity' => 1, 'updated_by' => $this->admin->id]);
        StoreCapacityOverride::create(['store_id' => $this->storeB->id, 'date' => $this->date(2), 'capacity' => 1, 'updated_by' => $this->admin->id]);

        $this->page(['storeId' => $this->storeA->id])->call('clearAllOverrides');

        $this->assertSame(1, StoreCapacityOverride::where('store_id', $this->storeA->id)->count(), 'Override lampau tetap sebagai riwayat.');
        $this->assertSame(1, StoreCapacityOverride::where('store_id', $this->storeB->id)->count(), 'Toko lain tidak tersentuh.');
    }

    public function test_store_manager_cannot_change_capacity_of_another_store(): void
    {
        $this->actingAs($this->user('store_manager', $this->storeA->id), 'web');
        $date = $this->date(3);

        $this->page()
            ->set('storeId', $this->storeB->id) // manipulasi manual
            ->call('openDay', $date)
            ->set('editingCapacity', 9)
            ->call('saveCapacity');

        $this->assertSame(0, StoreCapacityOverride::where('store_id', $this->storeB->id)->count());
        $this->assertSame(9, StoreCapacityOverride::where('store_id', $this->storeA->id)->firstOrFail()->capacity);
    }

    public function test_override_makes_a_date_full_for_booking_capacity_checks(): void
    {
        $date = $this->date(6);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => $this->storeA->id,
            'service_type' => 'Kaca Film (Window Film)', 'product_kaca_film' => true,
            'preferred_date' => $date, 'status' => 'confirmed', 'duration_days' => 1,
        ]);

        $this->assertSame([], Booking::fullDatesInRange($this->storeA->id, Carbon::parse($date), 1));

        $this->page(['storeId' => $this->storeA->id])
            ->call('openDay', $date)
            ->set('editingCapacity', 1)
            ->call('saveCapacity');

        $this->assertSame([$date], Booking::fullDatesInRange($this->storeA->id, Carbon::parse($date), 1));
    }

    public function test_month_navigation_works(): void
    {
        $component = $this->page(['storeId' => $this->storeA->id]);
        $current = now()->format('Y-m');

        $component->call('nextMonth')->assertSet('month', now()->addMonthNoOverflow()->format('Y-m'));
        $component->call('prevMonth')->call('prevMonth')->assertSet('month', now()->subMonthNoOverflow()->format('Y-m'));
        $component->call('goToday')->assertSet('month', $current);
    }
}
