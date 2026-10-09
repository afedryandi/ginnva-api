<?php

namespace Tests\Feature;

use App\Filament\Pages\StockCardReport;
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
 * Daftar Stok (Kartu Stok): per bahan baku / barang habis pakai -- Awal, Masuk, Keluar, Akhir untuk satu rentang. Akhir
 * dan Awal direkonstruksi mundur dari stok sekarang + riwayat pergerakan; Masuk = masuk + opname naik, Keluar = keluar +
 * opname turun. Filter jenis, sanitasi URL, periode cepat, akses lewat kotak menu, Excel/PDF + log. "Hari ini" dibekukan
 * di 8 Oktober 2026.
 */
class StockCardReportTest extends TestCase
{
    use RefreshDatabase;

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
     * Rentang 1-15 Okt. Film (meter, stok sekarang 60): masuk 100 (20 Sep), keluar 30 (5 Okt), masuk 20 (10 Okt), keluar 20
     * (15 Okt 23:00 -- hari terakhir ikut), opname -10 (20 Okt, SETELAH rentang) => awal 100, masuk 20, keluar 50, akhir 70.
     * Lap (pcs, stok sekarang 8): masuk 20 (25 Sep), keluar 15 (3 Okt 08:00), opname +3 (6 Okt), opname -2 (7 Okt)
     * => awal 20, masuk 3, keluar 17, akhir 6... stok sekarang 6 (tidak ada kejadian setelah rentang). Diam (stok 40): tanpa
     * pergerakan di rentang.
     */
    private function stock(): void
    {
        $film = RawMaterial::create(['name' => 'Film', 'code' => 'RM-FILM', 'unit' => 'meter', 'current_stock' => 60]);
        $this->rawMove($film, 'in', 100, '2026-09-20 09:00:00');
        $this->rawMove($film, 'out', 30, '2026-10-05 10:00:00');
        $this->rawMove($film, 'in', 20, '2026-10-10 10:00:00');
        $this->rawMove($film, 'out', 20, '2026-10-15 23:00:00');
        $this->rawMove($film, 'adjustment', -10, '2026-10-20 10:00:00');

        $lap = ConsumableItem::create(['name' => 'Lap', 'code' => 'CI-LAP', 'unit' => 'pcs', 'current_stock' => 6]);
        $this->consumableMove($lap, 'in', 20, '2026-09-25 09:00:00');
        $this->consumableMove($lap, 'out', 15, '2026-10-03 08:00:00');
        $this->consumableMove($lap, 'adjustment', 3, '2026-10-06 09:00:00');
        $this->consumableMove($lap, 'adjustment', -2, '2026-10-07 09:00:00');

        RawMaterial::create(['name' => 'Diam', 'code' => 'RM-DIAM', 'unit' => 'pcs', 'current_stock' => 40]);
    }

    private const RANGE = ['from' => '2026-10-01', 'to' => '2026-10-15'];

    private function page(array $data = self::RANGE, ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test(StockCardReport::class);
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
        return collect($result['rows'])->firstWhere('name', $name);
    }

    // ------------------------------------------------------------- perhitungan

    public function test_the_card_is_rebuilt_backwards_from_current_stock(): void
    {
        $this->stock();

        $film = $this->row($this->report(), 'Film');

        $this->assertEqualsWithDelta(100.0, $film['awal'], 0.001, 'Mundur dari 60: batalkan opname -10 setelah rentang, lalu seluruh pergerakan dalam rentang.');
        $this->assertEqualsWithDelta(20.0, $film['masuk'], 0.001);
        $this->assertEqualsWithDelta(50.0, $film['keluar'], 0.001, 'Pergerakan 15 Okt 23:00 (hari terakhir) ikut.');
        $this->assertEqualsWithDelta(70.0, $film['akhir'], 0.001);
        $this->assertEqualsWithDelta($film['akhir'], $film['awal'] + $film['masuk'] - $film['keluar'], 0.001, 'Awal + Masuk - Keluar = Akhir.');
        $this->assertTrue($film['hasMovement']);
        $this->assertSame('Bahan Baku', $film['jenis']);
        $this->assertSame('meter', $film['unit']);
    }

    public function test_adjustments_count_as_in_when_positive_and_out_when_negative(): void
    {
        $this->stock();

        $lap = $this->row($this->report(), 'Lap');

        $this->assertEqualsWithDelta(3.0, $lap['masuk'], 0.001, 'Opname +3 masuk ke Masuk.');
        $this->assertEqualsWithDelta(17.0, $lap['keluar'], 0.001, 'Keluar 15 + |opname -2|.');
        $this->assertEqualsWithDelta(20.0, $lap['awal'], 0.001);
        $this->assertEqualsWithDelta(6.0, $lap['akhir'], 0.001);
        $this->assertSame('Barang Habis Pakai', $lap['jenis']);
    }

    public function test_an_item_without_movement_keeps_its_stock_and_is_flagged(): void
    {
        $this->stock();

        $diam = $this->row($this->report(), 'Diam');

        $this->assertEqualsWithDelta(40.0, $diam['awal'], 0.001);
        $this->assertEqualsWithDelta(40.0, $diam['akhir'], 0.001);
        $this->assertEquals(0, $diam['masuk']);
        $this->assertEquals(0, $diam['keluar']);
        $this->assertFalse($diam['hasMovement']);
    }

    public function test_the_whole_first_day_counts_when_the_start_date_has_a_time(): void
    {
        $this->stock();

        $lap = $this->row($this->report(['from' => '2026-10-03 12:00:00', 'to' => '2026-10-15']), 'Lap');

        $this->assertEqualsWithDelta(17.0, $lap['keluar'], 0.001, 'Pemakaian 08:00 di hari pertama ikut walau "Dari" berjam 12:00.');
    }

    public function test_a_period_in_the_past_before_any_movement_shows_zero_stock(): void
    {
        $this->stock();

        $film = $this->row($this->report(['from' => '2026-08-01', 'to' => '2026-08-31']), 'Film');

        $this->assertEquals(0, $film['awal']);
        $this->assertEquals(0, $film['akhir']);
        $this->assertFalse($film['hasMovement']);
    }

    public function test_the_type_filter_limits_the_rows_and_totals_sum_them(): void
    {
        $this->stock();

        $raw = $this->report(self::RANGE + ['jenis' => 'raw_material']);
        $this->assertSame(['Diam', 'Film'], collect($raw['rows'])->pluck('name')->all());

        $consumable = $this->report(self::RANGE + ['jenis' => 'consumable_item']);
        $this->assertSame(['Lap'], collect($consumable['rows'])->pluck('name')->all());

        $all = $this->report(self::RANGE + ['jenis' => 'all']);
        $this->assertCount(3, $all['rows']);
        $this->assertEqualsWithDelta(23.0, $all['totals']['masuk'], 0.001);
        $this->assertEqualsWithDelta(67.0, $all['totals']['keluar'], 0.001);
    }

    public function test_the_page_shows_the_rows_with_drill_down_links(): void
    {
        $this->stock();

        $this->page()
            ->assertSuccessful()
            ->assertSee('Kartu Stok')
            ->assertSee('Film')
            ->assertSee('RM-FILM')
            ->assertSee('Barang Habis Pakai');

        $this->page(['from' => '2026-10-01', 'to' => '2026-10-15', 'jenis' => 'consumable_item'])->assertDontSee('RM-FILM');
    }

    public function test_an_empty_catalogue_shows_the_empty_message(): void
    {
        $this->page()->assertSee('Tidak ada bahan pada jenis ini.');
    }

    // ------------------------------------------------------------- filter & URL

    public function test_defaults_to_the_current_month_and_all_types(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $page = Livewire::test(StockCardReport::class);

        $this->assertSame('2026-10-01', $page->get('from'));
        $this->assertSame('2026-10-31', $page->get('to'));
        $this->assertSame('all', $page->get('jenisFilter'));
    }

    public function test_url_parameters_are_sanitised(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $swapped = Livewire::withQueryParams(['from' => '2026-10-15', 'to' => '2026-10-01', 'jenis' => 'ngawur'])->test(StockCardReport::class);
        $this->assertSame('2026-10-15', $swapped->get('from'));
        $this->assertSame('2026-10-15', $swapped->get('to'), '"Sampai" sebelum "Dari" dikoreksi.');
        $this->assertSame('all', $swapped->get('jenisFilter'));

        $garbage = Livewire::withQueryParams(['from' => 'bukan-tanggal', 'to' => ''])->test(StockCardReport::class);
        $this->assertSame('2026-10-01', $garbage->get('from'));
        $this->assertSame('2026-10-31', $garbage->get('to'));

        $valid = Livewire::withQueryParams(['from' => '2026-09-01', 'to' => '2026-09-30', 'jenis' => 'raw_material'])->test(StockCardReport::class);
        $this->assertSame('2026-09-01', $valid->get('from'));
        $this->assertSame('raw_material', $valid->get('jenisFilter'));
    }

    public function test_an_end_date_before_the_start_is_corrected_with_a_warning(): void
    {
        $page = $this->page(['from' => '2026-10-10', 'to' => '2026-10-20']);

        $page->set('data.to', '2026-10-01')
            ->assertSet('data.to', '2026-10-10')
            ->assertNotified('Tanggal "Sampai" tidak boleh sebelum "Dari"');
    }

    public function test_quick_periods_fill_the_dates(): void
    {
        $page = $this->page();

        $page->set('data.preset', 'last_month')->assertSet('data.from', '2026-09-01')->assertSet('data.to', '2026-09-30');
        $page->set('data.preset', 'this_quarter')->assertSet('data.from', '2026-10-01')->assertSet('data.to', '2026-12-31');
        $page->set('data.preset', 'ytd')->assertSet('data.from', '2026-01-01')->assertSet('data.to', '2026-10-08');
        $page->set('data.preset', 'last_year')->assertSet('data.from', '2025-01-01')->assertSet('data.to', '2025-12-31');
    }

    // ------------------------------------------------------------- akses

    public function test_access_follows_staff_area_and_the_menu_checkbox_and_stock_is_national(): void
    {
        $this->stock();
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(StockCardReport::canAccess());

        $staff = $this->user('kasir', $store, ['menu_access' => ['StockCardReport']]);
        $this->actingAs($staff, 'web');
        $this->assertTrue(StockCardReport::canAccess());
        $this->assertCount(3, $this->report(self::RANGE, $staff)['rows'], 'Stok nasional: staf toko melihat baris yang sama.');

        $this->actingAs($this->user('kasir', $store, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(StockCardReport::canAccess());
    }

    // ------------------------------------------------------------- ekspor

    public function test_excel_and_pdf_exports_are_downloaded_and_logged(): void
    {
        $this->stock();
        $admin = $this->user('super_admin');

        Excel::fake();
        $page = $this->page(self::RANGE + ['jenis' => 'all'], $admin);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        Excel::assertDownloaded('daftar-stok-20261008-100000.xlsx', function ($export) {
            $rows = $export->array();

            return $export->headings() === ['Kode', 'Nama', 'Jenis', 'Awal', 'Masuk', 'Keluar', 'Akhir', 'Satuan']
                && count($rows) === 3
                && in_array(['RM-FILM', 'Film', 'Bahan Baku', 100.0, 20.0, 50.0, 70.0, 'meter'], $rows, true);
        });

        $page->callAction('exportPdf')->assertFileDownloaded('daftar-stok-20261008-100000.pdf');

        $logs = Activity::where('log_name', 'report_export')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['xlsx', 'pdf'], $logs->pluck('properties.format')->all());
        $this->assertSame('stock_card', $logs[0]->properties['report']);
        $this->assertSame('2026-10-01', $logs[0]->properties['from']);
        $this->assertSame($admin->id, $logs[0]->causer_id);
    }
}
