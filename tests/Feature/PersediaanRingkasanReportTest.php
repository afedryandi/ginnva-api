<?php

namespace Tests\Feature;

use App\Exports\PersediaanRingkasanReportExport;
use App\Filament\Pages\PersediaanRingkasanReport;
use App\Models\ConsumableItem;
use App\Models\RawMaterial;
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
 * Lap. Ringkasan Persediaan: valuasi per item (Bahan Baku + Barang Habis Pakai) = stok saat ini x harga modal saat ini,
 * diurutkan dari nilai tertinggi, plus total; snapshot terkini (tanpa filter tanggal/toko -- stok bersifat nasional);
 * akses lewat kotak menu; Excel/PDF + log. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class PersediaanRingkasanReportTest extends TestCase
{
    use RefreshDatabase;

    private RawMaterial $film;
    private ConsumableItem $lap;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        // Migrasi sudah menanam item persediaan awal; hapus supaya tes menghitung hanya data miliknya.
        RawMaterial::query()->delete();
        ConsumableItem::query()->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store?->id], $extra)), fn (User $u) => $u->assignRole($role));
    }

    /** Total nilai = 5.000.000 + 15.000 + 0 + 500.000 + 26.252,625 = 5.541.252,625. */
    private function stock(): void
    {
        $this->film = RawMaterial::create(['name' => 'Film PPF Roll', 'code' => 'FPR-1', 'category' => 'Film', 'unit' => 'meter', 'current_stock' => 100, 'unit_cost' => 50000]);
        RawMaterial::create(['name' => 'Cairan Pembersih', 'unit' => 'ml', 'current_stock' => 2000, 'unit_cost' => 7.5]);
        RawMaterial::create(['name' => 'Bahan Kosong', 'unit' => 'pcs', 'current_stock' => 0, 'unit_cost' => null]);
        $this->lap = ConsumableItem::create(['name' => 'Lap Microfiber', 'code' => 'LM-1', 'category' => 'Aksesori', 'unit' => 'pcs', 'current_stock' => 40, 'unit_cost' => 12500]);
        ConsumableItem::create(['name' => 'Sarung Tangan', 'unit' => 'pasang', 'current_stock' => 10.5, 'unit_cost' => 2500.25]);
    }

    private function page(?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');

        return Livewire::test(PersediaanRingkasanReport::class);
    }

    private function report(?User $as = null): array
    {
        return $this->page($as)->instance()->getResult();
    }

    public function test_each_item_is_valued_at_stock_times_current_cost_and_sorted_by_value(): void
    {
        $this->stock();

        $result = $this->report();
        $rows = $result['rows'];

        $this->assertSame(['Film PPF Roll', 'Lap Microfiber', 'Sarung Tangan', 'Cairan Pembersih', 'Bahan Kosong'], $rows->pluck('name')->all());
        $this->assertEqualsWithDelta(5000000.0, $rows[0]['totalValue'], 0.001);
        $this->assertEqualsWithDelta(500000.0, $rows[1]['totalValue'], 0.001);
        $this->assertEqualsWithDelta(26252.625, $rows[2]['totalValue'], 0.001);
        $this->assertEqualsWithDelta(15000.0, $rows[3]['totalValue'], 0.001, '2.000 ml x Rp7,50.');
        $this->assertEqualsWithDelta(0.0, $rows[4]['totalValue'], 0.001);
        $this->assertEqualsWithDelta(5541252.625, $result['totalValue'], 0.001);
    }

    public function test_row_fields_type_sku_category_and_fallbacks(): void
    {
        $this->stock();

        $byName = $this->report()['rows']->keyBy('name');

        $this->assertSame(['raw_material', 'Bahan Baku', 'FPR-1', 'Film', 'meter', 100.0, 50000.0], [$byName['Film PPF Roll']['source'], $byName['Film PPF Roll']['type'], $byName['Film PPF Roll']['sku'], $byName['Film PPF Roll']['category'], $byName['Film PPF Roll']['unit'], $byName['Film PPF Roll']['quantity'], $byName['Film PPF Roll']['unitCost']]);
        $this->assertSame(['consumable', 'Barang Habis Pakai', 'LM-1', 'Aksesori'], [$byName['Lap Microfiber']['source'], $byName['Lap Microfiber']['type'], $byName['Lap Microfiber']['sku'], $byName['Lap Microfiber']['category']]);
        $this->assertSame(['—', '—'], [$byName['Cairan Pembersih']['sku'], $byName['Cairan Pembersih']['category']], 'Tanpa kode/kategori tampil strip.');
        $this->assertSame(0.0, $byName['Bahan Kosong']['unitCost'], 'Harga modal kosong dihitung 0.');
    }

    public function test_an_empty_inventory_has_zero_total(): void
    {
        $result = $this->report();

        $this->assertCount(0, $result['rows']);
        $this->assertEquals(0, $result['totalValue']);

        $this->page()->assertSee('Belum ada item persediaan.');
    }

    public function test_the_valuation_follows_stock_changes(): void
    {
        $this->stock();
        $this->film->update(['current_stock' => 40, 'unit_cost' => 60000]);

        $this->assertEqualsWithDelta(2400000.0, $this->report()['rows']->firstWhere('name', 'Film PPF Roll')['totalValue'], 0.001);
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox_and_every_store_sees_the_same_national_stock(): void
    {
        $this->stock();
        $storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(PersediaanRingkasanReport::canAccess());

        $staff = $this->user('kasir', $storeA, ['menu_access' => ['PersediaanRingkasanReport']]);
        $this->actingAs($staff, 'web');
        $this->assertTrue(PersediaanRingkasanReport::canAccess());
        $this->assertEqualsWithDelta(5541252.625, $this->report($staff)['totalValue'], 0.001, 'Stok nasional: staf toko melihat total yang sama.');

        $this->actingAs($this->user('kasir', $storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(PersediaanRingkasanReport::canAccess());
    }

    public function test_page_shows_total_items_fractional_costs_and_edit_links(): void
    {
        $this->stock();

        $page = $this->page();
        $page->assertSuccessful()
            ->assertSee('Total Nilai Persediaan')
            ->assertSee('Rp5.541.253', false)
            ->assertSee('Film PPF Roll')
            ->assertSee('FPR-1')
            ->assertSee('Bahan Baku')
            ->assertSee('Barang Habis Pakai')
            ->assertSee('Rp50.000', false)
            ->assertSee('Rp7,5', false)
            ->assertSee('Rp2.500,25', false)
            ->assertSee('Rp5.000.000', false);

        $this->assertStringContainsString((string) $this->film->id, $page->instance()->itemUrl($this->film->id, 'raw_material'));
        $this->assertStringContainsString((string) $this->lap->id, $page->instance()->itemUrl($this->lap->id, 'consumable'));
        $this->assertNotSame($page->instance()->itemUrl($this->film->id, 'raw_material'), $page->instance()->itemUrl($this->film->id, 'consumable'));
    }

    public function test_excel_rows_are_aligned_with_headings_and_values_are_numbers(): void
    {
        $this->stock();

        $export = new PersediaanRingkasanReportExport($this->report());
        $rows = $export->array();

        $this->assertSame(['Nama Produk', 'SKU', 'Jenis', 'Kategori', 'Kuantitas', 'Satuan', 'Harga Modal', 'Total Nilai Persediaan'], $export->headings());
        $this->assertCount(5, $rows);
        $this->assertSame(['Film PPF Roll', 'FPR-1', 'Bahan Baku', 'Film', 100.0, 'meter', 50000.0, 5000000.0], $rows[0]);
        $this->assertSame(['Cairan Pembersih', '—', 'Bahan Baku', '—', 2000.0, 'ml', 7.5, 15000.0], $rows[3]);
        $this->assertSame(['E' => '#,##0.00', 'G' => '#,##0.00', 'H' => '#,##0;(#,##0);"-"'], $export->columnFormats());
    }

    public function test_exports_download_and_are_logged(): void
    {
        $this->stock();
        $admin = $this->user('super_admin');
        Excel::fake();

        $page = $this->page($admin);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('ringkasan-persediaan-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $admin->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['xlsx', 'pdf'], $logs->map(fn ($l) => $l->properties['format'])->all());
        $this->assertSame('persediaan_ringkasan', $logs->first()->properties['report']);
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();

        $this->stock();
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
