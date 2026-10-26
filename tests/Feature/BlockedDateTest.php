<?php

namespace Tests\Feature;

use App\Filament\Pages\CapacityCalendar;
use App\Models\BlockedDate;
use App\Models\StoreCapacityOverride;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Menutup dan membuka tanggal (dulu menu "Tanggal Tidak Tersedia", sejak 2026-10-26 bagian dari Kalender Kapasitas & Libur): tutup satu
 * tanggal, tutup rentang, tutup semua toko, buka kembali, izin lewat kunci hak akses lama 'BlockedDateResource', isolasi toko, jejak
 * aktivitas, dan efeknya ke endpoint publik. Menu lama sudah dihapus.
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

    private function user(string $role, ?int $storeId, ?array $menuAccess = null, ?array $menuPermissions = null): User
    {
        $user = User::create([
            'name' => ucfirst($role) . ' ' . uniqid(),
            'email' => uniqid() . '@test.local',
            'password' => 'x',
            'store_id' => $storeId,
            'menu_access' => $menuAccess,
            'menu_permissions' => $menuPermissions,
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

    private function calendar(Store $store)
    {
        return Livewire::test(CapacityCalendar::class)->set('storeId', $store->id);
    }

    // ------------------------------------------------------------- menutup

    public function test_closing_a_day_from_the_day_panel_creates_one_row_with_the_reason(): void
    {
        $this->calendar($this->storeA)
            ->call('openDay', $this->date(3))
            ->set('editingReason', 'Libur nasional')
            ->call('closeDay')
            ->assertSet('editingDate', null);

        $row = BlockedDate::where('store_id', $this->storeA->id)->whereDate('date', $this->date(3))->first();
        $this->assertNotNull($row);
        $this->assertSame('Libur nasional', $row->reason);
        $this->assertSame(0, BlockedDate::where('store_id', $this->storeB->id)->count());
    }

    public function test_a_past_day_cannot_be_opened_or_closed(): void
    {
        $component = $this->calendar($this->storeA)->call('openDay', now()->subDay()->toDateString());
        $this->assertNull($component->get('editingDate'));

        $component->set('editingDate', now()->subDay()->toDateString())->call('closeDay');
        $this->assertSame(0, BlockedDate::count());
    }

    public function test_a_range_is_closed_one_row_per_day_skipping_existing_and_past_dates(): void
    {
        $this->blocked($this->storeA, 2, 'Sudah ada');

        $this->calendar($this->storeA)
            ->call('openClosureRange')
            ->set('closureFrom', now()->subDays(2)->toDateString())
            ->set('closureTo', $this->date(3))
            ->set('closureReason', 'Renovasi')
            ->call('applyClosureRange')
            ->assertSet('closureRangeOpen', false);

        $dates = BlockedDate::where('store_id', $this->storeA->id)->orderBy('date')->get()->map(fn ($b) => $b->date->toDateString())->all();

        // Hari ini sampai +3 = 4 tanggal; tanggal lampau dilewati; +2 sudah ada dan tidak ditimpa.
        $this->assertSame([$this->date(0), $this->date(1), $this->date(2), $this->date(3)], $dates);
        $this->assertSame('Sudah ada', BlockedDate::whereDate('date', $this->date(2))->value('reason'));
        $this->assertSame('Renovasi', BlockedDate::whereDate('date', $this->date(1))->value('reason'));
    }

    public function test_a_reversed_range_creates_nothing(): void
    {
        $this->calendar($this->storeA)
            ->call('openClosureRange')
            ->set('closureFrom', $this->date(5))
            ->set('closureTo', $this->date(2))
            ->call('applyClosureRange');

        $this->assertSame(0, BlockedDate::count());
    }

    public function test_the_same_date_can_be_closed_for_different_stores(): void
    {
        $this->calendar($this->storeA)->call('openDay', $this->date(4))->call('closeDay');
        $this->calendar($this->storeB)->call('openDay', $this->date(4))->call('closeDay');

        $this->assertSame(2, BlockedDate::count());
    }

    public function test_closing_all_active_stores_skips_existing_rows_and_inactive_stores(): void
    {
        $inactive = Store::create(['city' => 'Medan', 'address' => 'Jl. C', 'name' => 'Toko C', 'is_active' => false]);
        $start = now()->addDays(10);
        BlockedDate::create(['store_id' => $this->storeA->id, 'date' => $start->toDateString(), 'reason' => 'Sudah ada']);

        $this->calendar($this->storeA)
            ->call('openCloseAll')
            ->set('allFrom', $start->toDateString())
            ->set('allTo', $start->copy()->addDay()->toDateString())
            ->set('allReason', 'Libur Nasional')
            ->call('applyCloseAll');

        $this->assertSame(4, BlockedDate::whereIn('store_id', [$this->storeA->id, $this->storeB->id])->count());
        $this->assertSame('Sudah ada', BlockedDate::where('store_id', $this->storeA->id)->whereDate('date', $start)->value('reason'), 'Baris lama tidak ditimpa.');
        $this->assertSame(0, BlockedDate::where('store_id', $inactive->id)->count(), 'Toko nonaktif tidak ikut.');
    }

    public function test_closing_all_stores_requires_a_reason(): void
    {
        $this->calendar($this->storeA)
            ->call('openCloseAll')
            ->set('allFrom', $this->date(6))
            ->set('allTo', $this->date(6))
            ->set('allReason', '  ')
            ->call('applyCloseAll');

        $this->assertSame(0, BlockedDate::count());
    }

    public function test_a_store_level_user_cannot_close_all_stores(): void
    {
        $this->actingAs($this->user('store_manager', $this->storeA->id), 'web');

        $component = Livewire::test(CapacityCalendar::class)->call('openCloseAll');
        $this->assertFalse($component->get('closeAllOpen'));

        $component->set('allFrom', $this->date(6))->set('allTo', $this->date(6))->set('allReason', 'Coba')->call('applyCloseAll');
        $this->assertSame(0, BlockedDate::count());
    }

    // ------------------------------------------------------------- membuka

    public function test_reopening_from_the_day_panel_removes_the_row_and_reason_can_be_edited(): void
    {
        $this->blocked($this->storeA, 4, 'Salah ketik');

        $component = $this->calendar($this->storeA)->call('openDay', $this->date(4));
        $this->assertTrue($component->get('editingBlocked'));
        $this->assertSame('Salah ketik', $component->get('editingReason'));

        $component->set('editingReason', 'Perbaikan atap')->call('saveBlockedReason');
        $this->assertSame('Perbaikan atap', BlockedDate::whereDate('date', $this->date(4))->value('reason'));

        $this->calendar($this->storeA)->call('openDay', $this->date(4))->call('reopenDay');
        $this->assertSame(0, BlockedDate::count());
    }

    public function test_reopening_from_the_list_works_but_never_for_another_store(): void
    {
        $own = $this->blocked($this->storeA, 4);
        $other = $this->blocked($this->storeB, 4);

        $this->actingAs($this->user('store_manager', $this->storeA->id), 'web');
        $component = Livewire::test(CapacityCalendar::class);

        $component->call('reopenById', $other->id);
        $this->assertTrue(BlockedDate::whereKey($other->id)->exists(), 'Toko lain tidak boleh dibuka lewat id yang diketik manual.');

        $component->call('reopenById', $own->id);
        $this->assertFalse(BlockedDate::whereKey($own->id)->exists());
    }

    public function test_the_upcoming_list_shows_only_future_closures_of_the_selected_store(): void
    {
        BlockedDate::create(['store_id' => $this->storeA->id, 'date' => now()->subDays(5)->toDateString(), 'reason' => 'Penutupan lampau']);
        $this->blocked($this->storeA, 4, 'Mendatang');
        $this->blocked($this->storeB, 4, 'Toko lain');

        $component = $this->calendar($this->storeA)->assertSee('Mendatang')->assertDontSee('Toko lain')->assertDontSee('Penutupan lampau');

        $this->assertCount(1, $component->instance()->getUpcomingClosures());
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

    public function test_a_weekly_day_off_cannot_be_opened_in_the_panel(): void
    {
        $this->storeA->update(['opening_hours' => [['days' => ['sun'], 'closed' => true]]]);
        $sunday = now()->addDay()->next('Sunday')->toDateString();

        $component = $this->calendar($this->storeA)->call('openDay', $sunday);

        $this->assertNull($component->get('editingDate'));
    }

    // ------------------------------------------------------------- izin dan isolasi toko

    public function test_a_store_user_is_locked_to_their_own_store_even_if_the_store_property_is_tampered(): void
    {
        $this->actingAs($this->user('store_manager', $this->storeA->id), 'web');

        Livewire::test(CapacityCalendar::class)
            ->set('storeId', $this->storeB->id)
            ->call('openDay', $this->date(8))
            ->call('closeDay');

        $this->assertTrue(BlockedDate::where('store_id', $this->storeA->id)->whereDate('date', $this->date(8))->exists());
        $this->assertFalse(BlockedDate::where('store_id', $this->storeB->id)->exists());
    }

    public function test_booking_access_alone_does_not_allow_closing_dates_but_allows_capacity(): void
    {
        $this->actingAs($this->user('store_manager', $this->storeA->id, ['BookingResource']), 'web');

        $this->assertTrue(CapacityCalendar::canAccess());
        $this->assertTrue(CapacityCalendar::canManageCapacity());
        $this->assertFalse(CapacityCalendar::canManageClosures());

        Livewire::test(CapacityCalendar::class)->call('openDay', $this->date(5))->call('closeDay');
        $this->assertSame(0, BlockedDate::count());
    }

    public function test_the_old_closure_permission_alone_allows_closing_but_not_capacity(): void
    {
        $this->actingAs($this->user('store_manager', $this->storeA->id, ['BlockedDateResource']), 'web');

        $this->assertTrue(CapacityCalendar::canAccess());
        $this->assertFalse(CapacityCalendar::canManageCapacity());
        $this->assertTrue(CapacityCalendar::canManageClosures());

        Livewire::test(CapacityCalendar::class)
            ->call('openDay', $this->date(5))
            ->set('editingCapacity', 9)
            ->call('saveCapacity')
            ->call('closeDay');

        $this->assertSame(0, StoreCapacityOverride::count(), 'Tanpa akses Booking, kapasitas tidak boleh diubah.');
        $this->assertSame(1, BlockedDate::count());
    }

    public function test_an_explicit_delete_permission_is_not_needed_by_default_but_other_menus_cannot_access_the_page(): void
    {
        $this->actingAs($this->user('store_manager', $this->storeA->id, ['SomeOtherResource']), 'web');

        $this->assertFalse(CapacityCalendar::canAccess());
        $this->assertFalse(CapacityCalendar::canManageClosures());
    }

    public function test_a_closed_date_has_no_capacity_to_save(): void
    {
        $this->blocked($this->storeA, 4);

        $this->calendar($this->storeA)->call('openDay', $this->date(4))->set('editingCapacity', 7)->call('saveCapacity');

        $this->assertSame(0, StoreCapacityOverride::count());
    }

    // ------------------------------------------------------------- jejak dan efek ke luar

    public function test_closing_and_reopening_are_recorded_in_the_activity_log(): void
    {
        $this->calendar($this->storeA)->call('openDay', $this->date(4))->call('closeDay');
        $this->calendar($this->storeA)->call('openDay', $this->date(4))->call('reopenDay');

        $descriptions = Activity::where('log_name', 'blocked_date')->orderBy('id')->pluck('description')->all();

        $this->assertCount(2, $descriptions);
        $this->assertStringContainsString('diblokir', $descriptions[0]);
        $this->assertStringContainsString('kembali tersedia', $descriptions[1]);
    }

    public function test_public_api_reports_closed_dates_and_reopens_after_the_page_reopens_them(): void
    {
        $this->calendar($this->storeA)->call('openDay', $this->date(4))->call('closeDay');

        $this->assertContains($this->date(4), $this->getJson("/api/stores/{$this->storeA->id}/blocked-dates")->json('data'));

        $this->calendar($this->storeA)->call('openDay', $this->date(4))->call('reopenDay');

        $this->assertNotContains($this->date(4), $this->getJson("/api/stores/{$this->storeA->id}/blocked-dates")->json('data'));
    }

    public function test_the_old_menu_is_gone(): void
    {
        $this->assertFalse(class_exists('App\\Filament\\Resources\\BlockedDateResource'));
        $this->assertFalse(class_exists('App\\Filament\\Widgets\\BlockedDateCalendarWidget'));
    }
}
