<?php

namespace Tests\Feature;

use App\Exports\SpendPromoExport;
use App\Filament\Resources\SpendPromoResource;
use App\Filament\Resources\SpendPromoResource\Pages\CreateSpendPromo;
use App\Filament\Resources\SpendPromoResource\Pages\EditSpendPromo;
use App\Filament\Resources\SpendPromoResource\Pages\ListSpendPromos;
use App\Filament\Resources\SpendPromoResource\RelationManagers\BookingsRelationManager;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\SpendPromo;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Promo Total Pembelian: hak akses (hapus hanya full-access kecuali dibuka), daftar + status (berjalan / terjadwal /
 * nonaktif), tambah + ubah dengan validasi (ambang & potongan > 0, potongan <= ambang, tanggal berakhir tidak sebelum mulai),
 * jendela berlaku (batas hari inklusif), hapus (potongan di booking tetap tersimpan), daftar booking pemakai, ekspor + log.
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class SpendPromoResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        SpendPromo::query()->delete();
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?array $menuAccess = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function promo(string $name, array $extra = []): SpendPromo
    {
        return SpendPromo::create(array_merge(['name' => $name, 'min_purchase_amount' => 1000000, 'discount_amount' => 100000, 'is_active' => true], $extra));
    }

    private function booking(SpendPromo $promo, float $discount = 100000): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create([
            'booking_number' => 'BKG-T-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $this->store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-09', 'status' => 'confirmed',
            'spend_promo_id' => $promo->id, 'spend_promo_discount' => $discount, 'transaction_amount' => 900000,
        ]);
    }

    private function valid(array $extra = []): array
    {
        return array_merge(['name' => 'Cashback PPF', 'is_active' => true, 'min_purchase_amount' => 2000000, 'discount_amount' => 200000], $extra);
    }

    // ------------------------------------------------------------- akses

    public function test_access_rules(): void
    {
        $record = new SpendPromo();

        $this->as($this->user('super_admin'));
        $this->assertTrue(SpendPromoResource::canViewAny());
        $this->assertTrue(SpendPromoResource::canCreate());
        $this->assertTrue(SpendPromoResource::canEdit($record));
        $this->assertTrue(SpendPromoResource::canDelete($record));

        $this->as($this->user('kasir'));
        $this->assertTrue(SpendPromoResource::canViewAny());
        $this->assertTrue(SpendPromoResource::canCreate());
        $this->assertTrue(SpendPromoResource::canEdit($record));
        $this->assertFalse(SpendPromoResource::canDelete($record));

        $this->as($this->user('kasir', null, ['menu_permissions' => ['SpendPromoResource' => ['delete']]]));
        $this->assertTrue(SpendPromoResource::canDelete($record));

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(SpendPromoResource::canViewAny());
        $this->assertFalse(SpendPromoResource::canCreate());
        $this->assertFalse(SpendPromoResource::canEdit($record));
    }

    // ------------------------------------------------------------- daftar

    public function test_list_shows_status_period_and_usage(): void
    {
        $running = $this->promo('Berjalan', ['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $scheduled = $this->promo('Terjadwal', ['starts_on' => '2026-11-01']);
        $expired = $this->promo('Lewat', ['ends_on' => '2026-09-30']);
        $off = $this->promo('Mati', ['is_active' => false]);
        $open = $this->promo('Tanpa Batas');
        $this->booking($running);
        $this->booking($running);

        $this->as($this->user('kasir'));
        $withCount = SpendPromo::withCount('bookings')->findOrFail($running->id);

        Livewire::test(ListSpendPromos::class)
            ->assertCanSeeTableRecords([$running, $scheduled, $expired, $off, $open])
            ->assertTableColumnStateSet('status', 'Berjalan', record: $running)
            ->assertTableColumnStateSet('status', 'Terjadwal / Lewat', record: $scheduled)
            ->assertTableColumnStateSet('status', 'Terjadwal / Lewat', record: $expired)
            ->assertTableColumnStateSet('status', 'Nonaktif', record: $off)
            ->assertTableColumnStateSet('status', 'Berjalan', record: $open)
            ->assertTableColumnStateSet('period', '—  s/d  —', record: $open)
            ->assertTableColumnStateSet('bookings_count', 2, record: $withCount);
    }

    public function test_search_and_active_filter(): void
    {
        $a = $this->promo('Cashback PPF');
        $b = $this->promo('Diskon Window Film', ['is_active' => false]);

        $this->as($this->user('kasir'));
        Livewire::test(ListSpendPromos::class)->searchTable('Window')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListSpendPromos::class)->filterTable('is_active', true)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
        Livewire::test(ListSpendPromos::class)->filterTable('is_active', false)->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
    }

    // ------------------------------------------------------------- tambah & ubah

    public function test_create_a_promo_and_log_it(): void
    {
        $user = $this->as($this->user('kasir'));

        Livewire::test(CreateSpendPromo::class)
            ->fillForm($this->valid(['starts_on' => '2026-10-10', 'ends_on' => '2026-10-31', 'description' => 'Untuk PPF full body']))
            ->call('create')
            ->assertHasNoFormErrors();

        $promo = SpendPromo::where('name', 'Cashback PPF')->firstOrFail();
        $this->assertEquals(2000000, (float) $promo->min_purchase_amount);
        $this->assertEquals(200000, (float) $promo->discount_amount);
        $this->assertSame('2026-10-10', $promo->starts_on->toDateString());
        $this->assertSame($user->id, $promo->created_by);
        $this->assertSame($user->id, Activity::where('log_name', 'spend_promo')->latest('id')->firstOrFail()->causer_id);
    }

    public function test_create_validation(): void
    {
        $this->as($this->user('kasir'));

        Livewire::test(CreateSpendPromo::class)->fillForm($this->valid(['name' => '']))->call('create')->assertHasFormErrors(['name' => 'required']);
        Livewire::test(CreateSpendPromo::class)->fillForm($this->valid(['name' => str_repeat('a', 256)]))->call('create')->assertHasFormErrors(['name' => 'max']);
        Livewire::test(CreateSpendPromo::class)->fillForm($this->valid(['min_purchase_amount' => 0]))->call('create')->assertHasFormErrors(['min_purchase_amount']);
        Livewire::test(CreateSpendPromo::class)->fillForm($this->valid(['discount_amount' => 0]))->call('create')->assertHasFormErrors(['discount_amount']);
        Livewire::test(CreateSpendPromo::class)->fillForm($this->valid(['min_purchase_amount' => null]))->call('create')->assertHasFormErrors(['min_purchase_amount' => 'required']);
        Livewire::test(CreateSpendPromo::class)->fillForm($this->valid(['min_purchase_amount' => 1000000, 'discount_amount' => 1500000]))->call('create')->assertHasFormErrors(['discount_amount']);
        Livewire::test(CreateSpendPromo::class)->fillForm($this->valid(['min_purchase_amount' => 1e13, 'discount_amount' => 1000]))->call('create')->assertHasFormErrors(['min_purchase_amount']);
        Livewire::test(CreateSpendPromo::class)->fillForm($this->valid(['starts_on' => '2026-10-20', 'ends_on' => '2026-10-10']))->call('create')->assertHasFormErrors(['ends_on']);
        Livewire::test(CreateSpendPromo::class)->fillForm($this->valid(['description' => str_repeat('a', 501)]))->call('create')->assertHasFormErrors(['description' => 'max']);

        $this->assertSame(0, SpendPromo::count());
    }

    public function test_a_discount_equal_to_the_threshold_and_a_same_day_window_are_allowed(): void
    {
        $this->as($this->user('kasir'));

        Livewire::test(CreateSpendPromo::class)
            ->fillForm($this->valid(['min_purchase_amount' => 500000, 'discount_amount' => 500000, 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-10']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, SpendPromo::count());
    }

    public function test_edit_updates_the_promo_and_can_deactivate_it(): void
    {
        $promo = $this->promo('Cashback PPF');
        $this->as($this->user('kasir'));

        Livewire::test(EditSpendPromo::class, ['record' => $promo->getRouteKey()])
            ->fillForm(['name' => 'Cashback PPF Plus', 'discount_amount' => 150000, 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $promo->fresh();
        $this->assertSame('Cashback PPF Plus', $fresh->name);
        $this->assertEquals(150000, (float) $fresh->discount_amount);
        $this->assertFalse($fresh->is_active);
    }

    // ------------------------------------------------------------- jendela berlaku

    public function test_the_running_window_is_inclusive_on_both_days(): void
    {
        $today = $this->promo('Mulai Hari Ini', ['starts_on' => '2026-10-08']);
        $lastDay = $this->promo('Berakhir Hari Ini', ['ends_on' => '2026-10-08']);
        $ended = $this->promo('Berakhir Kemarin', ['ends_on' => '2026-10-07']);
        $tomorrow = $this->promo('Mulai Besok', ['starts_on' => '2026-10-09']);
        $off = $this->promo('Nonaktif', ['is_active' => false]);
        $open = $this->promo('Terbuka');

        foreach ([$today, $lastDay, $open] as $promo) {
            $this->assertTrue($promo->isRunning(), $promo->name);
        }
        foreach ([$ended, $tomorrow, $off] as $promo) {
            $this->assertFalse($promo->isRunning(), $promo->name);
        }

        $this->assertEqualsCanonicalizing(
            ['Mulai Hari Ini', 'Berakhir Hari Ini', 'Terbuka'],
            SpendPromo::running()->pluck('name')->all(),
            'Scope running() sejalan dengan isRunning().'
        );
    }

    // ------------------------------------------------------------- hapus & pemakai

    public function test_only_full_access_can_delete_and_booking_snapshots_survive(): void
    {
        $promo = $this->promo('Cashback PPF');
        $booking = $this->booking($promo, 100000);

        $this->as($this->user('kasir'));
        Livewire::test(ListSpendPromos::class)->assertTableActionHidden('delete', $promo);

        $this->as($this->user('super_admin'));
        Livewire::test(ListSpendPromos::class)
            ->assertTableActionVisible('delete', $promo)
            ->callTableAction('delete', $promo);

        $this->assertNull(SpendPromo::find($promo->id));
        $fresh = $booking->fresh();
        $this->assertNull($fresh->spend_promo_id, 'Tautan dilepas.');
        $this->assertEquals(100000, (float) $fresh->spend_promo_discount, 'Nilai potongan di booking tetap tersimpan.');
    }

    public function test_the_relation_manager_lists_the_bookings_using_the_promo(): void
    {
        $promo = $this->promo('Cashback PPF');
        $other = $this->promo('Lain');
        $mine = $this->booking($promo);
        $theirs = $this->booking($other);

        $this->as($this->user('kasir'));
        Livewire::test(BookingsRelationManager::class, ['ownerRecord' => $promo, 'pageClass' => EditSpendPromo::class])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    // ------------------------------------------------------------- ekspor

    public function test_exports_download_and_are_logged(): void
    {
        $running = $this->promo('Cashback PPF', ['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $this->promo('Mati', ['is_active' => false]);
        $this->booking($running);

        $export = new SpendPromoExport();
        $rows = $export->collection()->keyBy(0);
        $this->assertSame(['Nama', 'Minimal Pembelian', 'Potongan', 'Mulai Berlaku', 'Berakhir', 'Dipakai (Booking)', 'Status'], $export->headings());
        $this->assertEquals(['Cashback PPF', 1000000.0, 100000.0, '2026-10-01', '2026-10-31', 1, 'Berjalan'], $rows['Cashback PPF']);
        $this->assertSame('Nonaktif', $rows['Mati'][6]);
        $this->assertSame('-', $rows['Mati'][3]);

        $admin = $this->as($this->user('super_admin'));
        Excel::fake();
        $page = Livewire::test(ListSpendPromos::class);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        Excel::assertDownloaded('promo-total-pembelian-20261008-100000.xlsx');
        $page->callAction('exportPdf')->assertFileDownloaded('promo-total-pembelian-20261008-100000.pdf');

        $logs = Activity::where('log_name', 'report_export')->orderBy('id')->get();
        $this->assertSame(['xlsx', 'pdf'], $logs->pluck('properties.format')->all());
        $this->assertSame('spend_promo', $logs[0]->properties['report']);
        $this->assertSame($admin->id, $logs[0]->causer_id);
    }
}
