<?php

namespace Tests\Feature;

use App\Filament\Resources\StoreResource;
use App\Filament\Resources\StoreResource\Pages\CreateStore;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Filament\Resources\StoreResource\Pages\ListStores;
use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Toko/Dealer (master data): hak akses via StorePolicy (ubah/hapus default hanya full-access), staf hanya melihat tokonya,
 * daftar + pencarian + filter, tambah / ubah dengan validasi (angka bulat dalam batas, koordinat, jam operasional tidak
 * ambigu, link Google Maps -> koordinat), jejak audit, hapus yang dijaga (toko yang masih punya data tidak boleh dihapus
 * karena cascade) dan helper jam operasional / jarak.
 */
class StoreResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?Store $store = null, ?array $menuAccess = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->storeA)->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function valid(array $extra = []): array
    {
        return array_merge([
            'name' => 'Toko Baru', 'city' => 'Surabaya', 'address' => 'Jl. Baru 1', 'phone' => '031-123456', 'install_capacity_per_day' => 3,
            'opening_hours' => [], 'is_active' => true,
        ], $extra);
    }

    private function booking(Store $store): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create(['booking_number' => 'BKG-T-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $store->id, 'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-09', 'status' => 'confirmed']);
    }

    // ------------------------------------------------------------- akses & cakupan

    public function test_access_follows_the_policy_and_is_strict_by_default(): void
    {
        $this->as($this->user('super_admin'));
        $this->assertTrue(StoreResource::canViewAny());
        $this->assertTrue(StoreResource::canCreate());
        $this->assertTrue(StoreResource::canEdit($this->storeA));
        $this->assertTrue(StoreResource::canDelete($this->storeA));

        $this->as($this->user('kasir'));
        $this->assertTrue(StoreResource::canViewAny());
        $this->assertFalse(StoreResource::canCreate());
        $this->assertFalse(StoreResource::canEdit($this->storeA), 'Staf toko tidak boleh mengubah master toko, termasuk tokonya sendiri.');
        $this->assertFalse(StoreResource::canDelete($this->storeA));

        $this->as($this->user('kasir', null, null, ['menu_permissions' => ['StoreResource' => ['create', 'update', 'delete']]]));
        $this->assertTrue(StoreResource::canCreate());
        $this->assertTrue(StoreResource::canEdit($this->storeA));
        $this->assertTrue(StoreResource::canDelete($this->storeA));

        $this->as($this->user('kasir', null, ['BookingResource']));
        $this->assertFalse(StoreResource::canViewAny());
    }

    public function test_staff_only_see_their_own_store(): void
    {
        $this->as($this->user('kasir', $this->storeA));
        Livewire::test(ListStores::class)
            ->assertCanSeeTableRecords([$this->storeA])
            ->assertCanNotSeeTableRecords([$this->storeB]);

        $this->as($this->user('super_admin'));
        Livewire::test(ListStores::class)->assertCanSeeTableRecords([$this->storeA, $this->storeB]);
    }

    public function test_staff_cannot_open_the_edit_page_even_for_their_own_store(): void
    {
        $this->as($this->user('kasir', $this->storeA));

        $this->get(StoreResource::getUrl('edit', ['record' => $this->storeA]))->assertForbidden();
        try {
            Livewire::test(EditStore::class, ['record' => $this->storeB->getRouteKey()]);
            $this->fail('Toko lain tidak boleh bisa dibuka.');
        } catch (ModelNotFoundException $e) {
            $this->assertTrue(true);
        }
    }

    // ------------------------------------------------------------- daftar

    public function test_list_search_filter_hours_and_review_score(): void
    {
        $jkt = Store::create(['city' => 'Jakarta Selatan', 'address' => 'Jl. X', 'name' => 'Ginnva House', 'is_active' => true, 'reviews_count' => 4, 'positive_reviews_count' => 3, 'opening_hours' => [['days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'open' => '08:30', 'close' => '17:00', 'closed' => false], ['days' => ['sun'], 'closed' => true]]]);
        $off = Store::create(['city' => 'Medan', 'address' => 'Jl. Y', 'name' => 'Toko Mati', 'is_active' => false]);

        $this->as($this->user('super_admin'));
        $withAppends = Store::findOrFail($jkt->id);
        Livewire::test(ListStores::class)
            ->assertCanSeeTableRecords([$jkt, $off])
            ->assertTableColumnStateSet('opening_hours_summary', 'Senin–Jumat 08.30–17.00, Minggu Libur', record: $withAppends)
            ->assertTableColumnFormattedStateSet('positive_rate_percent', '75% positif (4 ulasan)', record: $withAppends);

        Livewire::test(ListStores::class)->searchTable('Medan')->assertCanSeeTableRecords([$off])->assertCanNotSeeTableRecords([$jkt]);
        Livewire::test(ListStores::class)->searchTable('Ginnva')->assertCanSeeTableRecords([$jkt])->assertCanNotSeeTableRecords([$off]);
        Livewire::test(ListStores::class)->filterTable('is_active', false)->assertCanSeeTableRecords([$off])->assertCanNotSeeTableRecords([$jkt]);
    }

    // ------------------------------------------------------------- tambah

    public function test_admin_creates_a_store_with_opening_hours_and_it_is_logged(): void
    {
        $admin = $this->as($this->user('super_admin'));

        Livewire::test(CreateStore::class)
            ->fillForm($this->valid([
                'latitude' => -6.2088, 'longitude' => 106.8456, 'attendance_radius_meters' => 200, 'late_tolerance_minutes' => 30, 'late_deduction_amount' => 25000,
                'detailing_slot_count' => 2, 'instalasi_qc_slot_count' => 3,
                'opening_hours' => [
                    ['days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'closed' => false, 'open' => '08:30', 'close' => '17:00'],
                    ['days' => ['sat'], 'closed' => false, 'open' => '09:00', 'close' => '13:00'],
                    ['days' => ['sun'], 'closed' => true],
                ],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $store = Store::where('name', 'Toko Baru')->firstOrFail();
        $this->assertSame(200, $store->attendance_radius_meters);
        $this->assertSame(3, $store->install_capacity_per_day);
        $this->assertCount(3, $store->opening_hours);
        $this->assertSame($admin->id, Activity::where('log_name', 'store')->latest('id')->firstOrFail()->causer_id);
    }

    public function test_create_validation(): void
    {
        $this->as($this->user('super_admin'));

        $fails = [
            [['name' => ''], 'name', 'required'],
            [['city' => ''], 'city', 'required'],
            [['address' => ''], 'address', 'required'],
            [['install_capacity_per_day' => 0], 'install_capacity_per_day', null],
            [['install_capacity_per_day' => 2.5], 'install_capacity_per_day', null],
            [['install_capacity_per_day' => 5000], 'install_capacity_per_day', null],
            [['detailing_slot_count' => -1], 'detailing_slot_count', null],
            [['attendance_radius_meters' => 5], 'attendance_radius_meters', null],
            [['late_tolerance_minutes' => 99999], 'late_tolerance_minutes', null],
            [['late_deduction_amount' => -1], 'late_deduction_amount', null],
            [['latitude' => 95], 'latitude', null],
            [['longitude' => 200], 'longitude', null],
            [['maps_url' => 'bukan url'], 'maps_url', null],
            [['maps_url' => 'https://www.google.com/maps/' . str_repeat('a', 300)], 'maps_url', 'max'],
        ];

        foreach ($fails as [$override, $field, $rule]) {
            $test = Livewire::test(CreateStore::class)->fillForm($this->valid($override))->call('create');
            $rule ? $test->assertHasFormErrors([$field => $rule]) : $test->assertHasFormErrors([$field]);
        }

        $this->assertSame(0, Store::where('name', 'Toko Baru')->count());
    }

    public function test_opening_hours_cannot_repeat_a_day_or_close_before_opening(): void
    {
        $this->as($this->user('super_admin'));

        Livewire::test(CreateStore::class)
            ->fillForm($this->valid(['opening_hours' => [
                ['days' => ['mon', 'tue'], 'closed' => false, 'open' => '08:00', 'close' => '17:00'],
                ['days' => ['tue', 'wed'], 'closed' => false, 'open' => '09:00', 'close' => '15:00'],
            ]]))
            ->call('create')
            ->assertHasFormErrors(['opening_hours']);

        Livewire::test(CreateStore::class)
            ->fillForm($this->valid(['opening_hours' => [
                ['days' => ['mon'], 'closed' => false, 'open' => '17:00', 'close' => '08:00'],
            ]]))
            ->call('create')
            ->assertHasFormErrors(['opening_hours']);

        $this->assertSame(0, Store::where('name', 'Toko Baru')->count());
    }

    // ------------------------------------------------------------- link Google Maps

    public function test_pasting_a_google_maps_link_fills_the_coordinates(): void
    {
        $this->as($this->user('super_admin'));

        $page = Livewire::test(CreateStore::class);
        $page->set('data.maps_url', 'https://www.google.com/maps/place/Toko/@-6.2000,106.8000,17z/data=!3d-6.2088!4d106.8456')
            ->assertSet('data.latitude', -6.2088)
            ->assertSet('data.longitude', 106.8456)
            ->assertNotified('Koordinat berhasil diisi');

        $page->set('data.maps_url', 'https://www.google.com/maps/place/Toko/@-6.3000,106.9000,17z')
            ->assertSet('data.latitude', -6.3)
            ->assertSet('data.longitude', 106.9);

        $page->set('data.maps_url', 'https://maps.google.com/maps?q=-7.5,110.25')
            ->assertSet('data.latitude', -7.5)
            ->assertSet('data.longitude', 110.25);
    }

    public function test_a_non_google_link_or_one_without_coordinates_only_warns(): void
    {
        $this->as($this->user('super_admin'));

        Livewire::test(CreateStore::class)
            ->set('data.maps_url', 'https://contoh.com/maps/@-6.2,106.8,17z')
            ->assertNotified('Koordinat tidak ditemukan');

        Livewire::test(CreateStore::class)
            ->set('data.maps_url', 'https://www.google.com/maps/place/Tanpa+Koordinat')
            ->assertNotified('Koordinat tidak ditemukan');
    }

    // ------------------------------------------------------------- ubah

    public function test_admin_edit_is_logged_and_a_permitted_staff_can_edit(): void
    {
        $admin = $this->as($this->user('super_admin'));

        Livewire::test(EditStore::class, ['record' => $this->storeA->getRouteKey()])
            ->fillForm(['attendance_radius_meters' => 250, 'late_deduction_amount' => 50000])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $this->storeA->fresh();
        $this->assertSame(250, $fresh->attendance_radius_meters);
        $this->assertEquals(50000, (float) $fresh->late_deduction_amount);
        $log = Activity::where('log_name', 'store')->where('subject_id', $this->storeA->id)->where('description', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $log->causer_id);

        $this->as($this->user('kasir', $this->storeA, null, ['menu_permissions' => ['StoreResource' => ['update']]]));
        Livewire::test(EditStore::class, ['record' => $this->storeA->getRouteKey()])
            ->fillForm(['phone' => '021-999'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('021-999', $this->storeA->fresh()->phone);
    }

    // ------------------------------------------------------------- hapus dijaga

    public function test_an_empty_store_can_be_deleted_from_the_list_and_the_edit_page(): void
    {
        $a = Store::create(['city' => 'Kosong', 'address' => 'Jl. K', 'name' => 'Toko Kosong 1', 'is_active' => true]);
        $b = Store::create(['city' => 'Kosong', 'address' => 'Jl. K', 'name' => 'Toko Kosong 2', 'is_active' => true]);
        BlockedDate::create(['store_id' => $a->id, 'date' => '2026-12-25', 'reason' => 'Natal']);
        $this->as($this->user('super_admin'));

        $this->assertSame([], $a->usageSummary(), 'Tanggal libur hanya pengaturan, tidak menghalangi.');

        Livewire::test(ListStores::class)->callTableAction('delete', $a)->assertNotified('Toko dihapus');
        $this->assertNull(Store::find($a->id));

        Livewire::test(EditStore::class, ['record' => $b->getRouteKey()])->callAction('delete')->assertNotified('Toko dihapus');
        $this->assertNull(Store::find($b->id));
    }

    public function test_a_store_with_data_cannot_be_deleted_and_the_message_names_it(): void
    {
        $booking = $this->booking($this->storeA);
        $admin = $this->as($this->user('super_admin'));

        $usage = $this->storeA->usageSummary();
        $this->assertSame(1, $usage['Booking']);
        $this->assertArrayHasKey('Akun staf', $usage);

        Livewire::test(ListStores::class)
            ->callTableAction('delete', $this->storeA)
            ->assertNotified('Tidak bisa menghapus toko ini');

        Livewire::test(EditStore::class, ['record' => $this->storeA->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('Tidak bisa menghapus toko ini');

        $this->assertNotNull(Store::find($this->storeA->id));
        $this->assertNotNull(Booking::find($booking->id), 'Booking tidak ikut terhapus.');
    }

    public function test_bulk_delete_removes_empty_stores_and_skips_used_ones(): void
    {
        $empty = Store::create(['city' => 'Kosong', 'address' => 'Jl. K', 'name' => 'Toko Kosong', 'is_active' => true]);
        $this->booking($this->storeB);
        $this->as($this->user('super_admin'));

        Livewire::test(ListStores::class)
            ->callTableBulkAction('delete', [$empty, $this->storeB])
            ->assertNotified('1 toko dihapus, 1 tidak bisa dihapus');

        $this->assertNull(Store::find($empty->id));
        $this->assertNotNull(Store::find($this->storeB->id));

        Livewire::test(ListStores::class)
            ->callTableBulkAction('delete', [$this->storeB])
            ->assertNotified('Tidak ada toko yang bisa dihapus');

        $this->as($this->user('kasir', $this->storeA));
        Livewire::test(ListStores::class)->assertTableBulkActionHidden('delete')->assertTableActionHidden('delete', $this->storeA);
    }

    // ------------------------------------------------------------- helper jam operasional & jarak

    public function test_opening_hours_helpers(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Ginnva House', 'is_active' => true, 'opening_hours' => [
            ['days' => ['mon', 'tue', 'wed'], 'open' => '08:30', 'close' => '17:00', 'closed' => false],
            ['days' => ['mon'], 'closed' => false, 'open' => '08:30', 'close' => '17:00'],
            ['days' => ['sat'], 'open' => '09:00', 'close' => '13:00', 'closed' => false],
            ['days' => ['sun'], 'closed' => true],
        ]]);

        $this->assertSame('Senin–Rabu', Store::formatDayRange(['wed', 'mon', 'tue']));
        $this->assertSame('Sen, Rab', Store::formatDayRange(['mon', 'wed']));
        $this->assertSame('Sabtu', Store::formatDayRange(['sat']));
        $this->assertSame('', Store::formatDayRange([]));

        $monday = Carbon::parse('2026-10-05');
        $sunday = Carbon::parse('2026-10-11');
        $thursday = Carbon::parse('2026-10-08');
        $this->assertSame('08:30', $store->openingTimeOn($monday));
        $this->assertSame('17:00', $store->closingTimeOn($monday));
        $this->assertTrue($store->isClosedOn($sunday));
        $this->assertNull($store->openingTimeOn($sunday));
        $this->assertNull($store->closingTimeOn($sunday));
        $this->assertNull($store->openingTimeOn($thursday), 'Hari tanpa baris jadwal tidak punya jam buka.');

        BlockedDate::create(['store_id' => $store->id, 'date' => '2026-10-05', 'reason' => 'Cuti bersama']);
        $fresh = Store::findOrFail($store->id);
        $this->assertTrue($fresh->isClosedOn($monday), 'Tanggal yang diblokir manual dihitung tutup.');
        $this->assertNull($fresh->openingTimeOn($monday));
    }

    public function test_the_schema_org_hours_skip_closed_rows(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Ginnva House', 'is_active' => true, 'opening_hours' => [
            ['days' => ['mon', 'tue'], 'open' => '08:30', 'close' => '17:00', 'closed' => false],
            ['days' => ['sun'], 'closed' => true],
        ]]);

        $schema = $store->opening_hours_schema;
        $this->assertCount(1, $schema);
        $this->assertSame(['Monday', 'Tuesday'], $schema[0]['dayOfWeek']->all());
        $this->assertSame(['08:30', '17:00'], [$schema[0]['opens'], $schema[0]['closes']]);
        $this->assertSame([], Store::create(['city' => 'X', 'address' => 'Y', 'name' => 'Tanpa Jadwal', 'is_active' => true])->opening_hours_schema);
    }

    public function test_distance_is_measured_in_meters_and_unknown_without_coordinates(): void
    {
        $withCoords = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Berkoordinat', 'is_active' => true, 'latitude' => -6.2000, 'longitude' => 106.8000]);

        $this->assertEqualsWithDelta(1001.0, $withCoords->distanceMetersTo(-6.2090, 106.8000), 5.0);
        $this->assertEqualsWithDelta(0.0, $withCoords->distanceMetersTo(-6.2000, 106.8000), 0.01);
        $this->assertNull($this->storeA->distanceMetersTo(-6.2, 106.8), 'Tanpa koordinat bukan 0 meter.');
    }
}
