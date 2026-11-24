<?php

namespace Tests\Feature;

use App\Filament\Pages\InvoiceSalesReport;
use App\Models\Invoice;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Laporan Penjualan dihitung dari invoice (keputusan pemilik 2026-11-23): hanya Belum Lunas dan Lunas, menurut tanggal terbit.
 */
class InvoiceSalesReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'super_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    private function user(string $role, ?array $menuAccess = null, ?Store $store = null): User
    {
        return tap(User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->storeA)->id, 'menu_access' => $menuAccess, 'is_active' => true]), fn (User $u) => $u->assignRole($role));
    }

    private function invoice(Store $store, string $status, string $issued, float $total, float $paid = 0, string $customer = 'Budi'): Invoice
    {
        static $n = 0;

        return Invoice::withoutGlobalScopes()->create([
            'invoice_number' => 'INV/T/' . ++$n, 'store_id' => $store->id, 'customer_name' => $customer, 'issue_date' => $issued,
            'status' => $status, 'subtotal' => $total, 'total' => $total, 'amount_paid' => $paid,
        ]);
    }

    public function test_only_issued_invoices_count_by_issue_date_and_draft_and_void_do_not(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->invoice($this->storeA, 'unpaid', '2026-11-10', 1000000, 300000);
        $this->invoice($this->storeA, 'paid', '2026-11-12', 500000, 500000);
        $this->invoice($this->storeA, 'draft', '2026-11-11', 9000000);
        $this->invoice($this->storeA, 'void', '2026-11-11', 8000000);
        $this->invoice($this->storeA, 'paid', '2026-10-30', 7000000, 7000000);   // di luar rentang

        $result = Livewire::withQueryParams(['from' => '2026-11-01', 'to' => '2026-11-30'])->test(InvoiceSalesReport::class)->instance()->getResult();

        $this->assertSame(2, $result['count']);
        $this->assertEquals(1500000.0, $result['total']);
        $this->assertEquals(800000.0, $result['paid']);
        $this->assertEquals(700000.0, $result['remaining']);
        $this->assertEquals(750000.0, $result['average']);
    }

    public function test_the_page_shows_totals_periods_and_links_to_the_invoices(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $inv = $this->invoice($this->storeA, 'unpaid', '2026-11-10', 1250000, 0, 'Pelanggan Contoh');

        Livewire::withQueryParams(['from' => '2026-11-01', 'to' => '2026-11-30', 'granularitas' => 'bulanan'])->test(InvoiceSalesReport::class)
            ->assertSee('Penjualan (Total Tagihan)')
            ->assertSee('Rp1.250.000')
            ->assertSee('November 2026')
            ->assertSee($inv->invoice_number)
            ->assertSee('Pelanggan Contoh')
            ->assertSee('Belum Lunas');
    }

    public function test_staff_only_see_their_own_store_and_full_access_can_filter_by_store(): void
    {
        $this->invoice($this->storeA, 'paid', '2026-11-10', 100000, 100000);
        $this->invoice($this->storeB, 'paid', '2026-11-10', 900000, 900000);
        $params = ['from' => '2026-11-01', 'to' => '2026-11-30'];

        $this->actingAs($this->user('kasir', ['InvoiceSalesReport'], $this->storeA), 'web');
        $mine = Livewire::withQueryParams($params + ['cabang' => $this->storeB->id])->test(InvoiceSalesReport::class)->instance()->getResult();
        $this->assertEquals(100000.0, $mine['total'], 'Staf toko A tidak boleh melihat toko B, dan pilihan cabang dari URL diabaikan.');

        $this->actingAs($this->user('super_admin'), 'web');
        $all = Livewire::withQueryParams($params)->test(InvoiceSalesReport::class)->instance()->getResult();
        $this->assertEquals(1000000.0, $all['total']);
        $this->assertCount(2, $all['stores']);

        $onlyB = Livewire::withQueryParams($params + ['cabang' => $this->storeB->id])->test(InvoiceSalesReport::class)->instance()->getResult();
        $this->assertEquals(900000.0, $onlyB['total']);
    }

    public function test_it_is_open_to_full_access_and_to_staff_with_the_menu_ticked_and_nobody_else(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(InvoiceSalesReport::canAccess());

        $this->actingAs($this->user('kasir', ['InvoiceSalesReport']), 'web');
        $this->assertTrue(InvoiceSalesReport::canAccess());

        $this->actingAs($this->user('kasir', []), 'web');
        $this->assertFalse(InvoiceSalesReport::canAccess());

        $this->actingAs($this->user('kasir', null), 'web');
        $this->assertFalse(InvoiceSalesReport::canAccess(), 'Menu yang belum diatur tidak otomatis membuka laporan omzet.');
    }

    public function test_it_does_not_depend_on_the_money_menus_switch(): void
    {
        config(['features.money_menus' => false]);
        $this->actingAs($this->user('super_admin'), 'web');

        $this->assertTrue(InvoiceSalesReport::canAccess());
        $this->assertFalse(\App\Filament\Pages\SalesSummaryReport::canAccess(), 'Menu uang lama tetap tersembunyi.');
    }

    public function test_granularity_and_bad_dates_fall_back_safely(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->invoice($this->storeA, 'paid', '2026-11-03', 100000, 100000);
        $this->invoice($this->storeA, 'paid', '2026-11-05', 200000, 200000);

        $weekly = Livewire::withQueryParams(['from' => '2026-11-01', 'to' => '2026-11-30', 'granularitas' => 'mingguan'])->test(InvoiceSalesReport::class)->instance()->getResult();
        $this->assertCount(1, $weekly['periods']);
        $this->assertSame(2, $weekly['periods'][0]['count']);

        $daily = Livewire::withQueryParams(['from' => 'bukan-tanggal', 'to' => '2026-11-30', 'granularitas' => 'ngawur'])->test(InvoiceSalesReport::class);
        $this->assertSame('harian', $daily->get('granularity'));
        $daily->assertSuccessful();
    }
}
