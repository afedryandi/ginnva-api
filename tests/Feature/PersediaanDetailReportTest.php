<?php

namespace Tests\Feature;

use App\Exports\PersediaanDetailReportExport;
use App\Filament\Pages\PersediaanDetailReport;
use App\Models\ConsumableItem;
use App\Models\ConsumableItemMovement;
use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lap. Detail Persediaan: rincian pergerakan stok (masuk / keluar / penyesuaian) Bahan Baku & Barang Habis Pakai dalam
 * satu rentang tanggal, terbaru dulu, dengan hitungan masuk/keluar per jenis; stok bersifat nasional (tanpa filter
 * toko); akses lewat kotak menu; sanitasi URL; Excel/PDF + log. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class PersediaanDetailReportTest extends TestCase
{
    use RefreshDatabase;

    private RawMaterial $film;
    private ConsumableItem $lap;
    private User $gudang;

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
        $this->gudang = $this->user('kasir', null, ['name' => 'Petugas Gudang']);
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

    private function rawMove(string $type, float $qty, string $at, ?float $cost = null, ?string $note = null): void
    {
        $m = RawMaterialMovement::create(['raw_material_id' => $this->film->id, 'type' => $type, 'quantity' => $qty, 'unit_cost' => $cost, 'note' => $note, 'user_id' => $this->gudang->id]);
        DB::table('raw_material_movements')->where('id', $m->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    private function consumableMove(string $type, float $qty, string $at, ?float $cost = null, ?string $note = null): void
    {
        $m = ConsumableItemMovement::create(['consumable_item_id' => $this->lap->id, 'type' => $type, 'quantity' => $qty, 'unit_cost' => $cost, 'note' => $note, 'user_id' => $this->gudang->id]);
        DB::table('consumable_item_movements')->where('id', $m->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    /**
     * Film: masuk 1 (1 Okt 08:00), masuk 100 @50.000 "Pembelian roll" (2 Okt), keluar 20 (3 Okt), penyesuaian -5 (4 Okt);
     * di luar rentang: masuk 10 (30 Sep 23:59). Lap: masuk 50 @12.500 (2 Okt), keluar 10 (5 Okt), penyesuaian +2 (6 Okt).
     */
    private function october(): void
    {
        $this->film = RawMaterial::create(['name' => 'Film PPF Roll', 'unit' => 'meter', 'current_stock' => 0]);
        $this->lap = ConsumableItem::create(['name' => 'Lap Microfiber', 'unit' => 'pcs', 'current_stock' => 0]);

        $this->rawMove('in', 10, '2026-09-30 23:59:00');
        $this->rawMove('in', 1, '2026-10-01 08:00:00');
        $this->rawMove('in', 100, '2026-10-02 09:00:00', 50000, 'Pembelian roll');
        $this->rawMove('out', 20, '2026-10-03 10:00:00');
        $this->rawMove('adjustment', -5, '2026-10-04 11:00:00', null, 'Opname');
        $this->consumableMove('in', 50, '2026-10-02 09:30:00', 12500);
        $this->consumableMove('out', 10, '2026-10-05 10:00:00');
        $this->consumableMove('adjustment', 2, '2026-10-06 10:00:00');
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test(PersediaanDetailReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    public function test_movements_in_range_are_listed_newest_first_with_in_and_out_counts(): void
    {
        $this->october();

        $result = $this->report();

        $this->assertSame(['adjustment', 'out', 'in', 'in'], $result['materialMovements']->pluck('type')->all(), 'Pergerakan 30 Sep tidak ikut.');
        $this->assertSame(['adjustment', 'out', 'in'], $result['consumableMovements']->pluck('type')->all());
        $this->assertSame([2, 1, 1, 1], [$result['materialInCount'], $result['materialOutCount'], $result['consumableInCount'], $result['consumableOutCount']], 'Penyesuaian tidak dihitung masuk/keluar.');
    }

    public function test_the_whole_first_day_counts_when_the_start_date_has_a_time(): void
    {
        $this->october();

        $result = $this->report(['from' => '2026-10-01 10:00:00', 'to' => '2026-10-31']);

        $this->assertSame(2, $result['materialInCount'], 'Pergerakan 08:00 di hari pertama ikut walau "Dari" berjam 10:00.');
    }

    public function test_range_edges_are_inclusive(): void
    {
        $this->october();

        $this->assertCount(1, $this->report(['from' => '2026-09-30', 'to' => '2026-09-30'])['materialMovements']);
        $this->assertCount(5, $this->report(['from' => '2026-09-30', 'to' => '2026-10-04'])['materialMovements'], '30 Sep, 1, 2, 3, dan 4 Okt.');
        $this->assertCount(0, $this->report(['from' => '2026-01-01', 'to' => '2026-01-31'])['materialMovements']);
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox_and_stock_is_national(): void
    {
        $this->october();
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(PersediaanDetailReport::canAccess());

        $staff = $this->user('kasir', $store, ['menu_access' => ['PersediaanDetailReport']]);
        $this->actingAs($staff, 'web');
        $this->assertTrue(PersediaanDetailReport::canAccess());
        $this->assertCount(4, $this->report([], $staff)['materialMovements'], 'Stok nasional: staf toko melihat pergerakan yang sama.');

        $this->actingAs($this->user('kasir', $store, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(PersediaanDetailReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => ''])->test(PersediaanDetailReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$bad->get('from'), $bad->get('to')]);

        $reversed = Livewire::withQueryParams(['from' => '2026-10-20', 'to' => '2026-10-10'])->test(PersediaanDetailReport::class);
        $this->assertSame('2026-10-20', $reversed->get('to'));

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31']] as $preset => $range) {
            $page->set('data.preset', $preset);
            $this->assertSame($range, [$page->get('data.from'), $page->get('data.to')], $preset);
        }
    }

    public function test_page_shows_cards_both_tables_links_and_the_empty_state(): void
    {
        $this->october();

        $page = $this->page();
        $page->assertSuccessful()
            ->assertSee('Bahan Baku Masuk')
            ->assertSee('Barang Habis Pakai Keluar')
            ->assertSee('Film PPF Roll')
            ->assertSee('Lap Microfiber')
            ->assertSee('Penyesuaian')
            ->assertSee('Pembelian roll')
            ->assertSee('Petugas Gudang')
            ->assertSee('-5.00 meter')
            ->assertSee('Rp50.000', false)
            ->assertSee('02 Oct 2026 09:00');

        $this->assertStringContainsString((string) $this->film->id, $page->instance()->materialUrl($this->film->id));
        $this->assertStringContainsString((string) $this->lap->id, $page->instance()->consumableUrl($this->lap->id));

        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])
            ->assertSee('Tidak ada pergerakan bahan baku pada rentang ini.')
            ->assertSee('Tidak ada pergerakan barang habis pakai pada rentang ini.');
    }

    public function test_a_raw_material_adjustment_is_not_styled_like_an_outgoing_movement(): void
    {
        $this->october();

        $html = $this->page()->html();

        $this->assertDoesNotMatchRegularExpression('/bg-danger-100[^>]*>\s*Penyesuaian/', $html, 'Penyesuaian = abu-abu, bukan merah seperti "Keluar".');
        $this->assertMatchesRegularExpression('/bg-gray-100[^>]*>\s*Penyesuaian/', $html);
        $this->assertMatchesRegularExpression('/bg-danger-100[^>]*>\s*Keluar/', $html);
    }

    public function test_excel_rows_are_aligned_with_headings_and_quantities_are_numbers(): void
    {
        $this->october();

        $export = new PersediaanDetailReportExport($this->report());
        $rows = $export->array();

        $this->assertSame(['Tanggal', 'Jenis Item', 'Nama', 'Pergerakan', 'Jumlah', 'Satuan', 'Harga Beli', 'Oleh', 'Catatan'], $export->headings());
        $this->assertCount(7, $rows);
        foreach ($rows as $row) {
            $this->assertCount(9, $row);
        }
        $this->assertSame(['2026-10-04 11:00', 'Bahan Baku', 'Film PPF Roll', 'Penyesuaian', -5.0, 'meter', 0.0, 'Petugas Gudang', 'Opname'], $rows[0]);
        $this->assertSame(['2026-10-02 09:00', 'Bahan Baku', 'Film PPF Roll', 'Masuk', 100.0, 'meter', 50000.0, 'Petugas Gudang', 'Pembelian roll'], $rows[2]);
        $this->assertSame(['2026-10-06 10:00', 'Barang Habis Pakai', 'Lap Microfiber', 'Penyesuaian', 2.0, 'pcs', 0.0, 'Petugas Gudang', '-'], $rows[4]);
        $this->assertSame('#,##0.00;-#,##0.00', $export->columnFormats()['E']);
    }

    public function test_exports_download_and_are_logged(): void
    {
        $this->october();
        $admin = $this->user('super_admin');
        Excel::fake();

        $page = $this->page([], $admin);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('detail-persediaan-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $admin->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame('persediaan_detail', $logs->first()->properties['report']);
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])->callAction('exportPdf')->assertHasNoActionErrors();

        $this->october();
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
