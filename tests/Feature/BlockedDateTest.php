<?php

namespace Tests\Feature;

use App\Filament\Pages\CapacityCalendar;
use App\Models\BlockedDate;
use App\Models\Store;
use App\Models\StoreCapacityOverride;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Menutup dan membuka tanggal (dulu menu "Tanggal Tidak Tersedia", sejak 2026-10-26 bagian dari Kalender Kapasitas & Libur): tutup satu
 * tanggal, tutup rentang, tutup semua toko, buka kembali, izin lewat kunci hak akses lama 'BlockedDateResource', isolasi toko, jejak
 * aktivitas, dan efeknya ke endpoint publik. Menu lama sudah dihapus. Semua dialog adalah Aksi Filament (manageDay, closeRange, closeAll,
 * reopenClosure).
 */
class BlockedDateTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('store_manager', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);

        $this->actingAs($this->user('super_admin', null), 'web');
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

    private function blocked(Store $store, int $daysAhead, ?string $reason = 'Libur'): BlockedDate
    {
        return BlockedDate::create(['store_id' => $store->id, 'date' => $this->date($daysAhead), 'reason' => $reason]);
    }

    /** Semua baris tanpa global scope toko: sebagai staf toko, query biasa hanya melihat tokonya sendiri sehingga pemeriksaan "tidak ada" jadi hampa. */
    private function allBlocked()
    {
        return BlockedDate::withoutGlobalScopes();
    }

    private function calendar(Store $store)
    {
        return Livewire::test(CapacityCalendar::class)->set('storeId', $store->id);
    }

    private function manageDay(Store $store, string $date, array $data)
    {
        return $this->calendar($store)->callAction('manageDay', data: $data, arguments: ['date' => $date]);
    }

    // ------------------------------------------------------------- menutup

    public function test_closing_a_day_from_the_day_dialog_creates_one_row_with_the_reason(): void
    {
        $this->manageDay($this->storeA, $this->date(3), ['close' => true, 'reason' => 'Libur nasional'])->assertHasNoActionErrors();

        $row = BlockedDate::where('store_id', $this->storeA->id)->whereDate('date', $this->date(3))->first();
        $this->assertNotNull($row);
        $this->assertSame('Libur nasional', $row->reason);
        $this->assertSame(0, BlockedDate::where('store_id', $this->storeB->id)->count());
    }

    public function test_a_past_day_cannot_be_closed(): void
    {
        $this->manageDay($this->storeA, now()->subDay()->toDateString(), ['close' => true]);

        $this->assertSame(0, BlockedDate::count());
    }

    public function test_a_range_is_closed_one_row_per_day_skipping_existing_dates(): void
    {
        $this->blocked($this->storeA, 2, 'Sudah ada');

        $this->calendar($this->storeA)
            ->callAction('closeRange', data: ['from' => $this->date(0), 'to' => $this->date(3), 'reason' => 'Renovasi'])
            ->assertHasNoActionErrors();

        $dates = BlockedDate::where('store_id', $this->storeA->id)->orderBy('date')->get()->map(fn ($b) => $b->date->toDateString())->all();

        $this->assertSame([$this->date(0), $this->date(1), $this->date(2), $this->date(3)], $dates);
        $this->assertSame('Sudah ada', BlockedDate::whereDate('date', $this->date(2))->value('reason'), 'Baris lama tidak ditimpa.');
        $this->assertSame('Renovasi', BlockedDate::whereDate('date', $this->date(1))->value('reason'));
    }

    public function test_a_range_cannot_start_in_the_past_or_end_before_it_starts(): void
    {
        $this->calendar($this->storeA)
            ->callAction('closeRange', data: ['from' => now()->subDays(2)->toDateString(), 'to' => $this->date(3)])
            ->assertHasActionErrors(['from']);

        $this->calendar($this->storeA)
            ->callAction('closeRange', data: ['from' => $this->date(5), 'to' => $this->date(2)])
            ->assertHasActionErrors(['to']);

        $this->assertSame(0, BlockedDate::count());
    }

    public function test_the_same_date_can_be_closed_for_different_stores(): void
    {
        $this->manageDay($this->storeA, $this->date(4), ['close' => true]);
        $this->manageDay($this->storeB, $this->date(4), ['close' => true]);

        $this->assertSame(2, BlockedDate::count());
    }

    public function test_closing_all_active_stores_skips_existing_rows_and_inactive_stores(): void
    {
        $inactive = Store::create(['city' => 'Medan', 'address' => 'Jl. C', 'name' => 'Toko C', 'is_active' => false]);
        $start = now()->addDays(10);
        BlockedDate::create(['store_id' => $this->storeA->id, 'date' => $start->toDateString(), 'reason' => 'Sudah ada']);

        $this->calendar($this->storeA)
            ->callAction('closeAll', data: ['from' => $start->toDateString(), 'to' => $start->copy()->addDay()->toDateString(), 'reason' => 'Libur Nasional'])
            ->assertHasNoActionErrors();

        $this->assertSame(4, BlockedDate::whereIn('store_id', [$this->storeA->id, $this->storeB->id])->count());
        $this->assertSame('Sudah ada', BlockedDate::where('store_id', $this->storeA->id)->whereDate('date', $start)->value('reason'), 'Baris lama tidak ditimpa.');
        $this->assertSame(0, BlockedDate::where('store_id', $inactive->id)->count(), 'Toko nonaktif tidak ikut.');
    }

    public function test_closing_all_stores_requires_a_reason(): void
    {
        $this->calendar($this->storeA)
            ->callAction('closeAll', data: ['from' => $this->date(6), 'to' => $this->date(6), 'reason' => ''])
            ->assertHasActionErrors(['reason']);

        // Alasan yang hanya spasi lolos form tetapi ditolak oleh logika penutupan.
        $this->calendar($this->storeA)->instance()->closeAllStores($this->date(6), $this->date(6), '   ');

        $this->assertSame(0, BlockedDate::count());
    }

    public function test_a_store_level_user_cannot_close_all_stores(): void
    {
        $this->actingAs($this->user('store_manager', $this->storeA->id), 'web');

        Livewire::test(CapacityCalendar::class)->instance()->closeAllStores($this->date(6), $this->date(6), 'Coba');

        $this->assertSame(0, $this->allBlocked()->count());
    }

    // ------------------------------------------------------------- membuka

    public function test_the_dialog_of_a_closed_day_shows_its_reason_and_can_edit_or_reopen_it(): void
    {
        $this->blocked($this->storeA, 4, 'Salah ketik');

        $this->calendar($this->storeA)
            ->mountAction('manageDay', ['date' => $this->date(4)])
            ->assertActionDataSet(['reason' => 'Salah ketik']);

        $this->manageDay($this->storeA, $this->date(4), ['reason' => 'Perbaikan atap']);
        $this->assertSame('Perbaikan atap', BlockedDate::whereDate('date', $this->date(4))->value('reason'));

        $this->manageDay($this->storeA, $this->date(4), ['reopen' => true]);
        $this->assertSame(0, BlockedDate::count());
    }

    public function test_reopening_from_the_list_works_but_never_for_another_store(): void
    {
        $own = $this->blocked($this->storeA, 4);
        $other = $this->blocked($this->storeB, 4);

        $this->actingAs($this->user('store_manager', $this->storeA->id), 'web');
        $component = Livewire::test(CapacityCalendar::class);

        $component->callAction('reopenClosure', arguments: ['id' => $other->id]);
        $this->assertTrue($this->allBlocked()->whereKey($other->id)->exists(), 'Toko lain tidak boleh dibuka lewat id yang diketik manual.');

        $component->callAction('reopenClosure', arguments: ['id' => $own->id]);
        $this->assertFalse($this->allBlocked()->whereKey($own->id)->exists());
    }

    public function test_the_upcoming_list_shows_only_future_closures_of_the_selected_store(): void
    {
        BlockedDate::create(['store_id' => $this->storeA->id, 'date' => now()->subDays(5)->toDateString(), 'reason' => 'Penutupan lampau']);
        $this->blocked($this->storeA, 4, 'Mendatang');
        $this->blocked($this->storeB, 4, 'Toko lain');

        // Kotak kalender bulan ini boleh menampilkan penutupan yang sudah lewat; yang diperiksa di sini adalah daftar "akan datang".
        $component = $this->calendar($this->storeA)->assertSee('Mendatang')->assertDontSee('Toko lain');

        $this->assertSame(['Mendatang'], $component->instance()->getUpcomingClosures()->pluck('reason')->all());
    }

    // ------------------------------------------------------------- tampilan kalender

    public function test_the_calendar_tells_a_closed_date_apart_from_a_weekly_day_off(): void
    {
        $this->storeA->update(['opening_hours' => [['days' => ['sun'], 'closed' => true]]]);
        $closed = now()->addDays(2);
        $sunday = now()->addDay()->next('Sunday');
        $this->blocked($this->storeA, 2, 'Acara');

        // Bulan ditentukan eksplisit supaya tanggal uji selalu ada di grid, termasuk di akhir bulan.
        $closedDay = collect($this->calendar($this->storeA)->set('month', $closed->format('Y-m'))->instance()->getCalendarDays())
            ->firstWhere('date', $closed->toDateString());
        $this->assertTrue($closedDay['blocked']);
        $this->assertFalse($closedDay['weeklyClosed']);
        $this->assertSame('Acara', $closedDay['blockedReason']);

        $weekly = collect($this->calendar($this->storeA)->set('month', $sunday->format('Y-m'))->instance()->getCalendarDays())
            ->firstWhere('date', $sunday->toDateString());
        $this->assertTrue($weekly['weeklyClosed']);
        $this->assertFalse($weekly['blocked']);
    }

    public function test_a_weekly_day_off_cannot_be_closed_or_given_a_capacity(): void
    {
        $this->storeA->update(['opening_hours' => [['days' => ['sun'], 'closed' => true]]]);
        $sunday = now()->addDay()->next('Sunday')->toDateString();

        $this->manageDay($this->storeA, $sunday, ['close' => true, 'capacity' => 5]);

        $this->assertSame(0, BlockedDate::count());
        $this->assertSame(0, StoreCapacityOverride::count());
    }

    public function test_dates_are_written_in_indonesian_whatever_the_app_locale(): void
    {
        $this->assertSame('Oktober 2026', CapacityCalendar::idDate('2026-10-05', 'F Y'));
        $this->assertSame('Senin, 26 Oktober 2026', CapacityCalendar::idDate('2026-10-26', 'l, d F Y'));

        $this->calendar($this->storeA)->assertSee(CapacityCalendar::idDate(now(), 'F Y'));
    }

    // ------------------------------------------------------------- izin dan isolasi toko

    public function test_a_store_user_is_locked_to_their_own_store_even_if_the_store_property_is_tampered(): void
    {
        $this->actingAs($this->user('store_manager', $this->storeA->id), 'web');

        Livewire::test(CapacityCalendar::class)
            ->set('storeId', $this->storeB->id)
            ->callAction('manageDay', data: ['close' => true], arguments: ['date' => $this->date(8)]);

        $this->assertTrue($this->allBlocked()->where('store_id', $this->storeA->id)->whereDate('date', $this->date(8))->exists());
        $this->assertFalse($this->allBlocked()->where('store_id', $this->storeB->id)->exists());
    }

    public function test_booking_access_alone_does_not_allow_closing_dates_but_allows_capacity(): void
    {
        $this->actingAs($this->user('store_manager', $this->storeA->id, ['BookingResource']), 'web');

        $this->assertTrue(CapacityCalendar::canAccess());
        $this->assertTrue(CapacityCalendar::canManageCapacity());
        $this->assertFalse(CapacityCalendar::canManageClosures());

        $component = Livewire::test(CapacityCalendar::class);

        // Kapasitas boleh diatur.
        $component->callAction('manageDay', data: ['capacity' => 4], arguments: ['date' => $this->date(5)]);
        $this->assertSame(4, StoreCapacityOverride::withoutGlobalScopes()->firstOrFail()->capacity);

        // Menutup tidak bisa: kotak "Tutup tanggal ini" tidak ada di form pengguna ini, dan permintaan paksa pun tidak menutup apa-apa.
        $component->callAction('manageDay', data: ['close' => true, 'reason' => 'Coba'], arguments: ['date' => $this->date(6)]);
        $this->assertSame(0, $this->allBlocked()->count());
    }

    public function test_the_old_closure_permission_alone_allows_closing_but_not_capacity(): void
    {
        $this->actingAs($this->user('store_manager', $this->storeA->id, ['BlockedDateResource']), 'web');

        $this->assertTrue(CapacityCalendar::canAccess());
        $this->assertFalse(CapacityCalendar::canManageCapacity());
        $this->assertTrue(CapacityCalendar::canManageClosures());

        Livewire::test(CapacityCalendar::class)
            ->callAction('manageDay', data: ['close' => true, 'capacity' => 9, 'reason' => 'Acara'], arguments: ['date' => $this->date(5)]);

        $this->assertSame(0, StoreCapacityOverride::withoutGlobalScopes()->count(), 'Tanpa akses Booking, kapasitas tidak boleh diubah.');
        $this->assertSame(1, $this->allBlocked()->count());
    }

    public function test_other_menu_access_cannot_open_the_page(): void
    {
        $this->actingAs($this->user('store_manager', $this->storeA->id, ['SomeOtherResource']), 'web');

        $this->assertFalse(CapacityCalendar::canAccess());
        $this->assertFalse(CapacityCalendar::canManageClosures());
    }

    public function test_a_closed_date_has_no_capacity_to_save(): void
    {
        $this->blocked($this->storeA, 4);

        $this->manageDay($this->storeA, $this->date(4), ['capacity' => 7]);

        $this->assertSame(0, StoreCapacityOverride::count());
    }

    // ------------------------------------------------------------- jejak dan efek ke luar

    public function test_closing_and_reopening_are_recorded_in_the_activity_log(): void
    {
        $this->manageDay($this->storeA, $this->date(4), ['close' => true]);
        $this->manageDay($this->storeA, $this->date(4), ['reopen' => true]);

        $descriptions = Activity::where('log_name', 'blocked_date')->orderBy('id')->pluck('description')->all();

        $this->assertCount(2, $descriptions);
        $this->assertStringContainsString('diblokir', $descriptions[0]);
        $this->assertStringContainsString('kembali tersedia', $descriptions[1]);
    }

    public function test_public_api_reports_closed_dates_and_reopens_after_the_page_reopens_them(): void
    {
        $this->manageDay($this->storeA, $this->date(4), ['close' => true]);

        $this->assertContains($this->date(4), $this->getJson("/api/stores/{$this->storeA->id}/blocked-dates")->json('data'));

        $this->manageDay($this->storeA, $this->date(4), ['reopen' => true]);

        $this->assertNotContains($this->date(4), $this->getJson("/api/stores/{$this->storeA->id}/blocked-dates")->json('data'));
    }

    public function test_the_old_menu_is_gone(): void
    {
        // Diperiksa lewat berkas, bukan class_exists(): classmap Composer bisa masih menyimpan nama kelas lama sampai "composer dump-autoload".
        $this->assertFileDoesNotExist(app_path('Filament/Resources/BlockedDateResource.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Widgets/BlockedDateCalendarWidget.php'));
        $this->assertDirectoryDoesNotExist(app_path('Filament/Resources/BlockedDateResource'));
    }
}
