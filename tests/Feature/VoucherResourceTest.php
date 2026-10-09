<?php

namespace Tests\Feature;

use App\Exports\VoucherExport;
use App\Filament\Resources\VoucherResource;
use App\Filament\Resources\VoucherResource\Pages\CreateVoucher;
use App\Filament\Resources\VoucherResource\Pages\EditVoucher;
use App\Filament\Resources\VoucherResource\Pages\ListVouchers;
use App\Filament\Resources\VoucherResource\RelationManagers\ClaimsRelationManager;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Services\VoucherService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Voucher Promo: kampanye (hanya full-access membuat/mengubah; nominal & stok terkunci begitu ada klaim; hapus hanya kalau
 * belum ada klaim), daftar, kode fisik di-assign ke customer app / walk-in (stok, kode unik tanpa memandang huruf besar,
 * 1 kode per akun per kampanye, panjang kode sesuai kolom), tandai terpakai, hapus klaim mengembalikan stok, pemakaian
 * pada booking (potongan, lepas), jejak audit dan ekspor. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class VoucherResourceTest extends TestCase
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
        Voucher::query()->delete();
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

    private function voucher(string $name = 'Voucher Rp500.000', array $extra = []): Voucher
    {
        return Voucher::create(array_merge(['name' => $name, 'discount_amount' => 500000, 'total_stock' => 5, 'claimed_count' => 0, 'is_active' => true], $extra));
    }

    private function customer(string $name = 'Budi Santoso'): Customer
    {
        return Customer::create(['name' => $name, 'phone_number' => '0812' . random_int(10000000, 99999999)]);
    }

    private function claim(Voucher $voucher, string $code, array $extra = []): VoucherClaim
    {
        $claim = VoucherClaim::create(array_merge(['voucher_id' => $voucher->id, 'customer_id' => $this->customer()->id, 'code' => $code, 'status' => 'active'], $extra));
        $voucher->increment('claimed_count');

        return $claim;
    }

    private function manager(Voucher $voucher)
    {
        return Livewire::test(ClaimsRelationManager::class, ['ownerRecord' => $voucher, 'pageClass' => EditVoucher::class]);
    }

    private function booking(): Booking
    {
        return Booking::create([
            'booking_number' => 'BKG-T-' . strtoupper(uniqid()), 'customer_id' => $this->customer()->id, 'store_id' => $this->store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-09', 'status' => 'confirmed',
        ]);
    }

    // ------------------------------------------------------------- akses

    public function test_campaign_rights_are_strict_by_default_and_delete_needs_zero_claims(): void
    {
        $free = $this->voucher('Bebas');
        $used = $this->voucher('Terpakai', ['claimed_count' => 2]);

        $this->as($this->user('super_admin'));
        $this->assertTrue(VoucherResource::canViewAny());
        $this->assertTrue(VoucherResource::canCreate());
        $this->assertTrue(VoucherResource::canEdit($free));
        $this->assertTrue(VoucherResource::canDelete($free));
        $this->assertFalse(VoucherResource::canDelete($used));

        $this->as($this->user('kasir'));
        $this->assertTrue(VoucherResource::canViewAny());
        $this->assertFalse(VoucherResource::canCreate(), 'Kampanye company-wide: default hanya full-access.');
        $this->assertFalse(VoucherResource::canEdit($free));
        $this->assertFalse(VoucherResource::canDelete($free));

        $this->as($this->user('kasir', null, ['menu_permissions' => ['VoucherResource' => ['create', 'update', 'delete']]]));
        $this->assertTrue(VoucherResource::canCreate());
        $this->assertTrue(VoucherResource::canEdit($free));
        $this->assertTrue(VoucherResource::canDelete($free));
        $this->assertFalse(VoucherResource::canDelete($used));

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(VoucherResource::canViewAny());
    }

    public function test_staff_without_the_create_right_cannot_open_the_create_page(): void
    {
        $this->as($this->user('kasir'));
        $this->get(VoucherResource::getUrl('create'))->assertForbidden();

        $this->as($this->user('super_admin'));
        $this->get(VoucherResource::getUrl('create'))->assertSuccessful();
    }

    // ------------------------------------------------------------- daftar

    public function test_list_shows_assigned_over_total_and_supports_search(): void
    {
        $a = $this->voucher('Voucher Member', ['claimed_count' => 2, 'total_stock' => 10]);
        $b = $this->voucher('Voucher Reseller', ['expires_at' => '2026-12-31 23:59:00']);

        $this->as($this->user('kasir'));
        Livewire::test(ListVouchers::class)
            ->assertCanSeeTableRecords([$a, $b])
            ->assertTableColumnFormattedStateSet('claimed_count', '2 / 10', record: $a)
            ->assertTableColumnStateSet('name', 'Voucher Member', record: $a);

        Livewire::test(ListVouchers::class)->searchTable('Reseller')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
    }

    // ------------------------------------------------------------- tambah & ubah

    public function test_admin_creates_a_campaign_and_it_is_logged(): void
    {
        $admin = $this->as($this->user('super_admin'));

        Livewire::test(CreateVoucher::class)
            ->fillForm(['name' => 'Voucher Rp1.000.000', 'description' => 'Untuk 100 pembeli pertama', 'discount_amount' => 1000000, 'total_stock' => 100, 'expires_at' => '2026-12-31 23:59:00', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $voucher = Voucher::where('name', 'Voucher Rp1.000.000')->firstOrFail();
        $this->assertEquals(1000000, (float) $voucher->discount_amount);
        $this->assertSame(100, $voucher->total_stock);
        $this->assertSame(0, $voucher->claimed_count);
        $log = Activity::where('log_name', 'voucher')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $log->causer_id);
    }

    public function test_create_validation(): void
    {
        $this->as($this->user('super_admin'));
        $valid = ['name' => 'Voucher', 'discount_amount' => 100000, 'total_stock' => 10];

        Livewire::test(CreateVoucher::class)->fillForm(array_merge($valid, ['name' => '']))->call('create')->assertHasFormErrors(['name' => 'required']);
        Livewire::test(CreateVoucher::class)->fillForm(array_merge($valid, ['discount_amount' => 0]))->call('create')->assertHasFormErrors(['discount_amount']);
        Livewire::test(CreateVoucher::class)->fillForm(array_merge($valid, ['discount_amount' => 1e11]))->call('create')->assertHasFormErrors(['discount_amount']);
        Livewire::test(CreateVoucher::class)->fillForm(array_merge($valid, ['total_stock' => 0]))->call('create')->assertHasFormErrors(['total_stock']);
        Livewire::test(CreateVoucher::class)->fillForm(array_merge($valid, ['total_stock' => 5000000]))->call('create')->assertHasFormErrors(['total_stock']);
        Livewire::test(CreateVoucher::class)->fillForm(array_merge($valid, ['expires_at' => '2026-10-01 10:00:00']))->call('create')->assertHasFormErrors(['expires_at']);

        $this->assertSame(0, Voucher::count());
    }

    public function test_edit_locks_stock_and_locks_the_amount_once_there_are_claims(): void
    {
        $fresh = $this->voucher('Belum Dibagi');
        $shared = $this->voucher('Sudah Dibagi', ['claimed_count' => 1]);

        $this->as($this->user('super_admin'));
        Livewire::test(EditVoucher::class, ['record' => $fresh->getRouteKey()])
            ->assertFormFieldIsDisabled('total_stock')
            ->assertFormFieldIsEnabled('discount_amount');
        Livewire::test(EditVoucher::class, ['record' => $shared->getRouteKey()])
            ->assertFormFieldIsDisabled('total_stock')
            ->assertFormFieldIsDisabled('discount_amount');

        Livewire::test(EditVoucher::class, ['record' => $shared->getRouteKey()])
            ->fillForm(['name' => 'Diganti', 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $row = $shared->fresh();
        $this->assertSame('Diganti', $row->name);
        $this->assertFalse($row->is_active);
        $this->assertEquals(500000, (float) $row->discount_amount, 'Nominal yang sudah beredar tidak berubah.');
        $this->assertSame(5, $row->total_stock);
        $this->assertNotNull(Activity::where('log_name', 'voucher')->where('subject_id', $shared->id)->where('description', 'updated')->first());
    }

    // ------------------------------------------------------------- hapus kampanye

    public function test_only_an_unclaimed_campaign_can_be_deleted_by_full_access(): void
    {
        $free = $this->voucher('Bebas');
        $used = $this->voucher('Terpakai', ['claimed_count' => 1]);

        $this->as($this->user('kasir'));
        Livewire::test(ListVouchers::class)->assertTableActionHidden('delete', $free);

        $this->as($this->user('super_admin'));
        Livewire::test(ListVouchers::class)
            ->assertTableActionHidden('delete', $used)
            ->assertTableActionVisible('delete', $free)
            ->callTableAction('delete', $free)
            ->assertNotified('Voucher dihapus');

        $this->assertNull(Voucher::find($free->id));
        $this->assertNotNull(Voucher::find($used->id));
    }

    // ------------------------------------------------------------- assign kode

    public function test_assigning_a_code_to_an_app_customer_uses_stock_and_uppercases_the_code(): void
    {
        $voucher = $this->voucher('Voucher', ['total_stock' => 2]);
        $customer = $this->customer();
        $booking = $this->booking();
        $this->as($this->user('kasir'));

        $this->manager($voucher)
            ->callTableAction('assign_code', data: ['holder_type' => 'app', 'customer_id' => $customer->id, 'code' => '  gnv-0001 ', 'booking_id' => $booking->id])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Voucher berhasil di-assign');

        $claim = VoucherClaim::firstOrFail();
        $this->assertSame('GNV-0001', $claim->code);
        $this->assertSame($customer->id, $claim->customer_id);
        $this->assertSame($booking->id, $claim->booking_id);
        $this->assertSame('active', $claim->status);
        $this->assertSame(1, $voucher->fresh()->claimed_count);
        $this->assertSame(1, $voucher->fresh()->remainingStock());
    }

    public function test_assigning_a_code_to_a_walk_in_holder(): void
    {
        $voucher = $this->voucher();
        $this->as($this->user('kasir'));

        $this->manager($voucher)
            ->callTableAction('assign_code', data: ['holder_type' => 'walk_in', 'walkin_name' => 'Pak Haji', 'walkin_phone' => '081234', 'code' => 'WALK-1'])
            ->assertHasNoTableActionErrors();

        $claim = VoucherClaim::firstOrFail();
        $this->assertNull($claim->customer_id);
        $this->assertSame('Pak Haji', $claim->holder_name);
        $this->assertSame('081234', $claim->walkin_phone);
        $this->assertSame(1, $voucher->fresh()->claimed_count);

        $this->manager($voucher)
            ->callTableAction('assign_code', data: ['holder_type' => 'walk_in', 'walkin_name' => '', 'code' => 'WALK-2'])
            ->assertHasTableActionErrors(['walkin_name' => 'required']);
    }

    public function test_a_duplicate_code_is_rejected_regardless_of_case_or_campaign(): void
    {
        $voucher = $this->voucher();
        $other = $this->voucher('Kampanye Lain');
        $this->claim($other, 'GNV-DUP');
        $this->as($this->user('kasir'));

        $this->manager($voucher)
            ->callTableAction('assign_code', data: ['holder_type' => 'app', 'customer_id' => $this->customer()->id, 'code' => 'gnv-dup'])
            ->assertNotified('Gagal assign voucher');

        $this->assertSame(0, $voucher->fresh()->claimed_count);
        $this->assertSame(1, VoucherClaim::count());
    }

    public function test_one_code_per_account_per_campaign_has_a_clear_message(): void
    {
        $voucher = $this->voucher();
        $customer = $this->customer();
        $this->claim($voucher, 'GNV-A1', ['customer_id' => $customer->id]);

        $service = app(VoucherService::class);
        try {
            $service->assignToCustomer($voucher->fresh(), 'GNV-A2', $customer->id);
            $this->fail('Harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah punya kode dari kampanye voucher ini', $e->getMessage());
        }

        $this->assertSame(1, VoucherClaim::count());
        $this->assertSame(1, $voucher->fresh()->claimed_count);
    }

    public function test_exhausted_inactive_and_expired_campaigns_reject_assignment(): void
    {
        $service = app(VoucherService::class);
        $cases = [
            'habis' => $this->voucher('Habis', ['total_stock' => 1, 'claimed_count' => 1]),
            'nonaktif' => $this->voucher('Nonaktif', ['is_active' => false]),
            'kedaluwarsa' => $this->voucher('Lewat', ['expires_at' => '2026-10-01 00:00:00']),
        ];

        foreach ($cases as $label => $voucher) {
            try {
                $service->assignToWalkin($voucher, 'KODE-' . strtoupper($label), 'Budi', null);
                $this->fail("Kampanye {$label} harus ditolak.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('habis atau sudah tidak aktif', $e->getMessage(), $label);
            }
        }

        $this->assertSame(0, VoucherClaim::count());
    }

    public function test_the_code_length_matches_the_column(): void
    {
        $voucher = $this->voucher();
        $this->as($this->user('kasir'));

        $this->manager($voucher)
            ->callTableAction('assign_code', data: ['holder_type' => 'app', 'customer_id' => $this->customer()->id, 'code' => str_repeat('A', 21)])
            ->assertHasTableActionErrors(['code' => 'max']);

        $this->manager($voucher)
            ->callTableAction('assign_code', data: ['holder_type' => 'app', 'customer_id' => $this->customer()->id, 'code' => str_repeat('A', 20)])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, VoucherClaim::count());
    }

    // ------------------------------------------------------------- tandai terpakai & hapus klaim

    public function test_mark_used_is_applied_once(): void
    {
        $voucher = $this->voucher();
        $claim = $this->claim($voucher, 'GNV-U1');
        $this->as($this->user('kasir'));

        $this->manager($voucher)
            ->callTableAction('mark_used', $claim)
            ->assertNotified('Voucher ditandai terpakai');

        $fresh = $claim->fresh();
        $this->assertSame('used', $fresh->status);
        $this->assertNotNull($fresh->used_at);

        $this->manager($voucher)->assertTableActionHidden('mark_used', $fresh);
    }

    public function test_deleting_a_claim_is_for_full_access_and_returns_the_stock(): void
    {
        $voucher = $this->voucher();
        $claim = $this->claim($voucher, 'GNV-D1');
        $this->assertSame(1, $voucher->fresh()->claimed_count);

        $this->as($this->user('kasir'));
        $this->manager($voucher)->assertTableActionHidden('delete', $claim);

        $this->as($this->user('super_admin'));
        $this->manager($voucher)
            ->assertTableActionVisible('delete', $claim)
            ->callTableAction('delete', $claim);

        $this->assertNull(VoucherClaim::find($claim->id));
        $this->assertSame(0, $voucher->fresh()->claimed_count);
    }

    public function test_the_claim_list_shows_holders_and_status(): void
    {
        $voucher = $this->voucher();
        $app = $this->claim($voucher, 'GNV-APP', ['customer_id' => $this->customer('Siti Aminah')->id]);
        $walkIn = $this->claim($voucher, 'GNV-WALK', ['customer_id' => null, 'walkin_name' => 'Pak Haji']);
        $used = $this->claim($voucher, 'GNV-USED', ['status' => 'used']);

        $this->as($this->user('kasir'));
        $this->manager($voucher)
            ->assertCanSeeTableRecords([$app, $walkIn, $used])
            ->assertTableColumnFormattedStateSet('holder_name', 'Siti Aminah', record: $app)
            ->assertTableColumnFormattedStateSet('holder_name', 'Pak Haji (Walk-in)', record: $walkIn)
            ->assertTableColumnFormattedStateSet('status', 'Aktif', record: $app)
            ->assertTableColumnFormattedStateSet('status', 'Terpakai', record: $used)
            ->searchTable('GNV-WALK')
            ->assertCanSeeTableRecords([$walkIn])
            ->assertCanNotSeeTableRecords([$app, $used]);
    }

    // ------------------------------------------------------------- pemakaian di booking

    public function test_applying_a_claim_to_a_booking_returns_the_discount_and_can_be_released(): void
    {
        $voucher = $this->voucher('Voucher', ['discount_amount' => 750000]);
        $claim = $this->claim($voucher, 'GNV-B1');
        $booking = $this->booking();
        $other = $this->booking();
        $service = app(VoucherService::class);

        $discount = \DB::transaction(fn () => $service->applyToBooking($claim->id, $booking));
        $this->assertEquals(750000.0, $discount);
        $fresh = $claim->fresh();
        $this->assertSame('used', $fresh->status);
        $this->assertSame($booking->id, $fresh->booking_id);
        $this->assertNotNull($fresh->used_at);

        $again = \DB::transaction(fn () => $service->applyToBooking($claim->id, $booking));
        $this->assertEquals(750000.0, $again, 'Booking yang sama boleh menerapkan ulang (idempoten).');

        try {
            \DB::transaction(fn () => $service->applyToBooking($claim->id, $other));
            $this->fail('Kode yang sudah dipakai booking lain harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah dipakai/dipilih di transaksi lain', $e->getMessage());
        }

        \DB::transaction(fn () => $service->releaseFromBooking($claim->id));
        $released = $claim->fresh();
        $this->assertSame('active', $released->status);
        $this->assertNull($released->booking_id);
        $this->assertNull($released->used_at);

        $this->expectException(RuntimeException::class);
        \DB::transaction(fn () => $service->applyToBooking(999999, $booking));
    }

    // ------------------------------------------------------------- ekspor

    public function test_exports_download_and_are_logged(): void
    {
        $this->voucher('Voucher Member', ['claimed_count' => 2, 'total_stock' => 10, 'expires_at' => '2026-12-31 23:59:00']);
        $this->voucher('Voucher Mati', ['is_active' => false]);

        $export = new VoucherExport();
        $rows = $export->collection()->keyBy(0);
        $this->assertSame(['Nama Kampanye', 'Potongan', 'Ter-assign', 'Total Stok', 'Kedaluwarsa', 'Aktif'], $export->headings());
        $this->assertEquals(['Voucher Member', 500000.0, 2, 10, '2026-12-31 23:59', 'Ya'], $rows['Voucher Member']);
        $this->assertSame('Tanpa batas waktu', $rows['Voucher Mati'][4]);
        $this->assertSame('Tidak', $rows['Voucher Mati'][5]);

        $admin = $this->as($this->user('super_admin'));
        Excel::fake();
        $page = Livewire::test(ListVouchers::class);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        Excel::assertDownloaded('voucher-promo-20261008-100000.xlsx');
        $page->callAction('exportPdf')->assertFileDownloaded('voucher-promo-20261008-100000.pdf');

        $logs = Activity::where('log_name', 'report_export')->orderBy('id')->get();
        $this->assertSame(['xlsx', 'pdf'], $logs->pluck('properties.format')->all());
        $this->assertSame('voucher', $logs[0]->properties['report']);
        $this->assertSame($admin->id, $logs[0]->causer_id);
    }
}
