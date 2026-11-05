<?php

namespace Tests\Feature;

use App\Exports\StockTurnoverReportExport;
use App\Filament\Pages\StockTurnoverReport;
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
 * Perputaran Stok: untuk tiap bahan baku / barang habis pakai yang ADA pemakaian (keluar) di rentang -- qty terpakai,
 * stok awal & akhir rentang direkonstruksi dari riwayat pergerakan (mundur dari stok sekarang), rata-rata stok, rasio
 * perputaran = terpakai / rata-rata stok, hari terjual, konsumsi harian dan estimasi hari habis (dari stok HARI INI).
 * Stok nasional; akses lewat kotak menu; sanitasi URL; Excel/PDF + log. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class StockTurnoverReportTest extends TestCase
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

    private function rawMove(RawMaterial $m, string $type, float $qty, string $at): void
    {
        $row = RawMaterialMovement::create(['raw_material_id' => $m->id, 'type' => $type, 'quantity' => $qty]);
        DB::table('raw_material_movements')->where('id', $row->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    private function consumableMove(ConsumableItem $c, string $type, float $qty, string $at): void
    {
        $row = ConsumableItemMovement::create(['consumable_item_id' => $c->id, 'type' => $type, 'quantity' => $qty]);
        DB::table('consumable_item_movements')->where('id', $row->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    /**
     * Rentang 1–15 Okt. Film (meter, stok sekarang 60): masuk 100 (20 Sep), keluar 30 (5 Okt), masuk 20 (10 Okt), keluar 20
     * (15 Okt 12:00), penyesuaian -10 (20 Okt, SETELAH rentang) => awal 100, akhir 70, rata-rata 85, terpakai 50.
     * Lap (pcs, stok sekarang 5): masuk 20 (25 Sep), keluar 15 (3 Okt 08:00) => awal 20, akhir 5, rata-rata 12,5, terpakai 15.
     * Habis: masuk 10 (2 Okt), keluar 10 (3 Okt) => rata-rata 0. Diam: hanya masuk (tidak tampil).
     */
    private function stock(): void
    {
        $this->film = RawMaterial::create(['name' => 'Film', 'unit' => 'meter', 'current_stock' => 60]);
        $this->rawMove($this->film, 'in', 100, '2026-09-20 09:00:00');
        $this->rawMove($this->film, 'out', 30, '2026-10-05 10:00:00');
        $this->rawMove($this->film, 'in', 20, '2026-10-10 10:00:00');
        $this->rawMove($this->film, 'out', 20, '2026-10-15 12:00:00');
        $this->rawMove($this->film, 'adjustment', -10, '2026-10-20 10:00:00');

        $this->lap = ConsumableItem::create(['name' => 'Lap', 'unit' => 'pcs', 'current_stock' => 5]);
        $this->consumableMove($this->lap, 'in', 20, '2026-09-25 09:00:00');
        $this->consumableMove($this->lap, 'out', 15, '2026-10-03 08:00:00');

        $habis = RawMaterial::create(['name' => 'Habis', 'unit' => 'pcs', 'current_stock' => 0]);
        $this->rawMove($habis, 'in', 10, '2026-10-02 09:00:00');
        $this->rawMove($habis, 'out', 10, '2026-10-03 09:00:00');

        $diam = RawMaterial::create(['name' => 'Diam', 'unit' => 'pcs', 'current_stock' => 40]);
        $this->rawMove($diam, 'in', 40, '2026-10-04 09:00:00');
    }

    private const RANGE = ['from' => '2026-10-01', 'to' => '2026-10-15'];

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test(StockTurnoverReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = self::RANGE, ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    private function row(array $result, string $name): array
    {
        return collect($result['rows'])->first(fn ($r) => $r['item']->name === $name);
    }

    public function test_stock_is_rebuilt_backwards_from_current_stock_including_later_adjustments(): void
    {
        $this->stock();

        $film = $this->row($this->report(), 'Film');

        $this->assertEqualsWithDelta(50.0, $film['qtyOut'], 0.001);
        $this->assertEqualsWithDelta(100.0, $film['stockAtFrom'], 0.001, 'Mundur dari 60: batalkan penyesuaian -10 (setelah rentang) lalu seluruh pergerakan dalam rentang.');
        $this->assertEqualsWithDelta(70.0, $film['stockAtTo'], 0.001);
        $this->assertEqualsWithDelta(85.0, $film['avgStock'], 0.001);
        $this->assertEqualsWithDelta(50 / 85, $film['turnoverRatio'], 0.0001);
        $this->assertSame(2, $film['daysSold']);
        $this->assertEqualsWithDelta(60.0, $film['currentStock'], 0.001);
    }

    public function test_consumption_rate_and_days_until_stockout_use_todays_stock(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');   // setelah akhir rentang, supaya seluruh 15 hari sudah berjalan
        $this->stock();

        $result = $this->report();
        $film = $this->row($result, 'Film');
        $lap = $this->row($result, 'Lap');

        $this->assertEqualsWithDelta(50 / 15, $film['avgDailyConsumption'], 0.0001, '15 hari kalender, bukan hanya hari yang ada pergerakan.');
        $this->assertEqualsWithDelta(18.0, $film['daysUntilStockout'], 0.001, 'Stok HARI INI 60 / 3,33 per hari.');
        $this->assertEqualsWithDelta(1.2, $lap['turnoverRatio'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $lap['avgDailyConsumption'], 0.0001);
        $this->assertEqualsWithDelta(5.0, $lap['daysUntilStockout'], 0.001);
        $this->assertSame('Barang Habis Pakai', $lap['type']);
    }

    public function test_items_without_usage_are_hidden_and_zero_average_stock_has_no_ratio(): void
    {
        $this->stock();

        $result = $this->report();
        $names = collect($result['rows'])->map(fn ($r) => $r['item']->name)->all();

        $this->assertSame(['Lap', 'Film', 'Habis'], $names, 'Rasio tertinggi dulu, rasio tak terhitung terakhir; "Diam" (hanya masuk) tidak tampil.');
        $habis = $this->row($result, 'Habis');
        $this->assertNull($habis['turnoverRatio']);
        $this->assertEquals(0, $habis['avgStock']);
        $this->assertEquals(0, $habis['daysUntilStockout'], 'Stok sekarang 0 = habis hari ini.');
    }

    public function test_an_item_with_no_consumption_in_a_period_is_not_listed(): void
    {
        $this->stock();

        $this->assertCount(0, $this->report(['from' => '2026-12-01', 'to' => '2026-12-31'])['rows']);
        $this->page(['from' => '2026-12-01', 'to' => '2026-12-31'])->assertSee('Tidak ada pergerakan stok pada rentang ini.');
    }

    public function test_the_whole_first_day_counts_when_the_start_date_has_a_time(): void
    {
        $this->stock();

        $lap = $this->row($this->report(['from' => '2026-10-03 12:00:00', 'to' => '2026-10-15']), 'Lap');

        $this->assertEqualsWithDelta(15.0, $lap['qtyOut'], 0.001, 'Pemakaian 08:00 di hari pertama ikut walau "Dari" berjam 12:00.');
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox_and_stock_is_national(): void
    {
        $this->stock();
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(StockTurnoverReport::canAccess());

        $staff = $this->user('kasir', $store, ['menu_access' => ['StockTurnoverReport']]);
        $this->actingAs($staff, 'web');
        $this->assertTrue(StockTurnoverReport::canAccess());
        $this->assertCount(3, $this->report(self::RANGE, $staff)['rows'], 'Stok nasional: staff toko melihat baris yang sama.');

        $this->actingAs($this->user('kasir', $store, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(StockTurnoverReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => ''])->test(StockTurnoverReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$bad->get('from'), $bad->get('to')]);

        $reversed = Livewire::withQueryParams(['from' => '2026-10-20', 'to' => '2026-10-10'])->test(StockTurnoverReport::class);
        $this->assertSame('2026-10-20', $reversed->get('to'));

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31']] as $preset => $range) {
            $page->set('data.preset', $preset);
            $this->assertSame($range, [$page->get('data.from'), $page->get('data.to')], $preset);
        }
    }

    public function test_page_shows_ratios_estimates_and_links(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->stock();

        $page = $this->page(self::RANGE);
        $page->assertSuccessful()
            ->assertSee('Film')
            ->assertSee('Lap')
            ->assertSee('Bahan Baku')
            ->assertSee('Barang Habis Pakai')
            ->assertSee('1.20x', false)
            ->assertSee('0.59x', false)
            ->assertSee('18 Hari')
            ->assertSee('5 Hari')
            ->assertSee('2 Hari')
            ->assertSee('85.00 meter');

        $this->assertStringContainsString((string) $this->film->id, $page->instance()->itemUrl('Bahan Baku', $this->film->id));
        $this->assertStringContainsString((string) $this->lap->id, $page->instance()->itemUrl('Barang Habis Pakai', $this->lap->id));
    }

    public function test_excel_rows_are_aligned_with_headings(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->stock();

        $export = new StockTurnoverReportExport($this->report());
        $rows = $export->array();

        $this->assertSame(['Nama', 'Jenis', 'Terpakai', 'Dikembalikan (memo)', 'Stok Akhir', 'Rata-rata Stok', 'Perputaran', 'Hari Terpakai', 'Stok Hari Ini', 'Konsumsi/Hari', 'Estimasi Habis (Hari)'], $export->headings());
        $this->assertCount(3, $rows);
        $this->assertEquals(['Lap (pcs)', 'Barang Habis Pakai', 15.0, 0.0, 5.0, 12.5, 1.2, 1, 5.0, 1.0, 5.0], $rows[0]);
        $this->assertEquals(['Film (meter)', 'Bahan Baku', 50.0, 0.0, 70.0, 85.0, 0.59, 2, 60.0, 3.33, 18.0], $rows[1]);
        $this->assertSame('-', $rows[2][6], 'Rasio tak terhitung tampil "-".');
    }

    public function test_exports_download_and_are_logged(): void
    {
        $this->stock();
        $admin = $this->user('super_admin');
        Excel::fake();

        $page = $this->page(self::RANGE, $admin);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('perputaran-stok-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $admin->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame('stock_turnover', $logs->first()->properties['report']);
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page(['from' => '2026-12-01', 'to' => '2026-12-31'])->callAction('exportPdf')->assertHasNoActionErrors();

        $this->stock();
        $this->page(self::RANGE)->callAction('exportPdf')->assertHasNoActionErrors();
    }

    // ------------------------------------------------------------- pemakaian bersih, hari berjalan, stok mati, filter

    private function memoReturn(RawMaterial $m, float $qty, string $at): void
    {
        $row = RawMaterialMovement::create(['raw_material_id' => $m->id, 'type' => 'in', 'quantity' => $qty, 'source_type' => 'memo', 'source_id' => 1]);
        DB::table('raw_material_movements')->where('id', $row->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    public function test_materials_returned_through_a_memo_reduce_the_usage(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $film = RawMaterial::create(['name' => 'Film', 'unit' => 'meter', 'current_stock' => 80]);
        $this->rawMove($film, 'in', 100, '2026-09-20 09:00:00');
        $this->rawMove($film, 'out', 30, '2026-10-05 10:00:00');
        $this->memoReturn($film, 10, '2026-10-06 10:00:00');
        // Pembelian biasa (bukan memo) tidak mengurangi pemakaian.
        $this->rawMove($film, 'in', 5, '2026-10-07 10:00:00');

        $row = $this->row($this->report(), 'Film');

        $this->assertEqualsWithDelta(20.0, $row['qtyOut'], 0.001, '30 keluar - 10 dikembalikan lewat memo.');
        $this->assertEqualsWithDelta(10.0, $row['returned'], 0.001);
    }

    public function test_consumption_per_day_uses_only_the_days_already_run(): void
    {
        // Hari ini 8 Okt; rentang 1-31 Okt. Pemakaian 16 dalam 8 hari berjalan = 2 per hari (bukan 16/31).
        $lap = ConsumableItem::create(['name' => 'Lap', 'unit' => 'pcs', 'current_stock' => 20]);
        $this->consumableMove($lap, 'in', 40, '2026-09-25 09:00:00');
        $this->consumableMove($lap, 'out', 16, '2026-10-03 08:00:00');

        $row = $this->row($this->report(['from' => '2026-10-01', 'to' => '2026-10-31']), 'Lap');

        $this->assertEqualsWithDelta(2.0, $row['avgDailyConsumption'], 0.0001);
        $this->assertEqualsWithDelta(10.0, $row['daysUntilStockout'], 0.001);
    }

    public function test_idle_stock_is_listed_only_when_asked_and_counted_either_way(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->stock();

        $default = $this->report();
        $this->assertSame(['Lap', 'Film', 'Habis'], collect($default['rows'])->map(fn ($r) => $r['item']->name)->all());
        $this->assertSame(1, $default['idleCount'], '"Diam" bersisa stok 40 tanpa pemakaian.');

        $withIdle = $this->report(self::RANGE + ['show_idle' => true]);
        $this->assertSame(['Lap', 'Film', 'Diam', 'Habis'], collect($withIdle['rows'])->map(fn ($r) => $r['item']->name)->all(), 'Diam (rasio 0) di atas Habis (rasio tak terhitung).');
        $diam = $this->row($withIdle, 'Diam');
        $this->assertEquals(0.0, $diam['qtyOut']);
        $this->assertEquals(0.0, $diam['turnoverRatio']);
        $this->assertNull($diam['daysUntilStockout']);
    }

    public function test_summary_and_the_type_and_low_stock_filters(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->stock();

        $all = $this->report();
        $this->assertSame(3, $all['usedCount']);
        $this->assertSame(2, $all['lowCount'], 'Lap (5 hari) dan Habis (0 hari); Film 18 hari tidak.');
        $this->assertGreaterThan(0, $all['avgRatio']);

        $lowOnly = $this->report(self::RANGE + ['only_low' => true]);
        $this->assertSame(['Lap', 'Habis'], collect($lowOnly['rows'])->map(fn ($r) => $r['item']->name)->all(), 'Habis (stok 0) ikut karena estimasinya 0 hari.');

        $raw = $this->report(self::RANGE + ['type' => 'raw']);
        $this->assertSame(['Film', 'Habis'], collect($raw['rows'])->map(fn ($r) => $r['item']->name)->all());
        $consumable = $this->report(self::RANGE + ['type' => 'consumable']);
        $this->assertSame(['Lap'], collect($consumable['rows'])->map(fn ($r) => $r['item']->name)->all());
    }

    public function test_the_page_shows_the_summary_badges_and_the_returned_note(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->stock();
        $film = $this->film;
        $this->memoReturn($film, 4, '2026-10-06 10:00:00');
        DB::table('raw_materials')->where('id', $film->id)->update(['current_stock' => 64]);

        $this->page(self::RANGE + ['show_idle' => true])
            ->assertSee('Barang Dipakai')
            ->assertSee('Hampir Habis')
            ->assertSee('Stok Tidak Terpakai')
            ->assertSee('segera beli')
            ->assertSee('tidak terpakai')
            ->assertSee('dikembalikan')
            ->assertSee('Hari Terpakai')
            ->assertSee('Stok Akhir')
            ->assertDontSee('Hari Terjual');
    }
}
