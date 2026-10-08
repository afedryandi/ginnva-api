<?php

namespace Tests\Feature;

use App\Exports\ExpiringStockReportExport;
use App\Filament\Pages\ExpiringStockReport;
use App\Models\RawMaterial;
use App\Models\RawMaterialBatch;
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
 * Laporan Stok Kedaluwarsa: batch bahan baku yang MASIH ADA STOK dan tanggal kedaluwarsanya jatuh di rentang (bebas,
 * default hari ini s.d. 30 hari ke depan), urut tanggal kedaluwarsa; status "Kedaluwarsa (n hari lalu)" / "dalam n
 * hari"; nilai = sisa x harga batch (jatuh ke harga bahan bila batch tanpa harga); stok nasional (tanpa filter
 * toko); akses lewat kotak menu; sanitasi URL; Excel/PDF + log. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class ExpiringStockReportTest extends TestCase
{
    use RefreshDatabase;

    private RawMaterial $film;
    private RawMaterial $cairan;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        // Migrasi sudah menanam item persediaan awal; hapus supaya tes menghitung hanya data miliknya.
        RawMaterialBatch::query()->delete();
        RawMaterial::query()->delete();
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

    private function batch(RawMaterial $material, float $qty, ?float $cost, ?string $received, ?string $expiry): RawMaterialBatch
    {
        return RawMaterialBatch::create(['raw_material_id' => $material->id, 'quantity' => $qty, 'unit_cost' => $cost, 'received_date' => $received, 'expiry_date' => $expiry]);
    }

    /**
     * Film A (harga bahan 40.000): sudah lewat 5 hari (10 x 50.000), kedaluwarsa hari ini (5, tanpa harga batch), 12 hari
     * lagi (20 x 30.000), habis (qty 0, tidak ikut), tanpa kedaluwarsa (tidak ikut), jauh di depan (31 Des).
     * Cairan B: 7 hari lagi (2 x 5.000).
     */
    private function stock(): array
    {
        $this->film = RawMaterial::create(['name' => 'Film A', 'code' => 'FA-1', 'unit' => 'meter', 'current_stock' => 0, 'unit_cost' => 40000]);
        $this->cairan = RawMaterial::create(['name' => 'Cairan B', 'unit' => 'ml', 'current_stock' => 0]);

        return [
            'expired' => $this->batch($this->film, 10, 50000, '2026-06-01', '2026-10-03'),
            'today' => $this->batch($this->film, 5, null, '2026-07-01', '2026-10-08'),
            'soon' => $this->batch($this->film, 20, 30000, '2026-08-01', '2026-10-20'),
            'empty' => $this->batch($this->film, 0, 30000, '2026-08-01', '2026-10-10'),
            'noExpiry' => $this->batch($this->film, 8, 30000, '2026-08-01', null),
            'far' => $this->batch($this->film, 4, 10000, '2026-08-01', '2026-12-31'),
            'liquid' => $this->batch($this->cairan, 2, 5000, '2026-09-01', '2026-10-15'),
        ];
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test(ExpiringStockReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    public function test_default_range_starts_90_days_back_and_ends_30_days_ahead_with_stocked_batches_only_sorted_by_expiry(): void
    {
        $b = $this->stock();

        $result = $this->report();

        $this->assertSame([$b['expired']->id, $b['today']->id, $b['liquid']->id, $b['soon']->id], $result['batches']->pluck('id')->all(), 'Batch yang sudah lewat ikut tampil secara default; habis, tanpa kedaluwarsa, dan >30 hari ke depan tidak.');
        $this->assertSame('2026-07-10', $result['from']->toDateString(), '90 hari sebelum hari ini.');
        $this->assertSame('2026-11-07', $result['to']->toDateString());
        $this->assertSame([1, 3], [$result['expiredCount'], $result['nearExpiryCount']], 'Yang kedaluwarsa hari ini belum dihitung "sudah kedaluwarsa".');
    }

    public function test_a_wider_range_includes_expired_batches_and_values_fall_back_to_the_material_cost(): void
    {
        $b = $this->stock();

        $result = $this->report(['from' => '2026-09-01']);

        $this->assertSame([$b['expired']->id, $b['today']->id, $b['liquid']->id, $b['soon']->id], $result['batches']->pluck('id')->all());
        $this->assertSame([1, 3], [$result['expiredCount'], $result['nearExpiryCount']]);
        // 10 x 50.000 + 5 x 40.000 (harga bahan, batch tanpa harga) + 2 x 5.000 + 20 x 30.000
        $this->assertEqualsWithDelta(1310000.0, $result['totalValue'], 0.001);
    }

    public function test_range_edges_are_inclusive_and_the_start_date_time_is_ignored(): void
    {
        $b = $this->stock();

        $this->assertSame([$b['soon']->id], $this->report(['from' => '2026-10-20', 'to' => '2026-10-20'])['batches']->pluck('id')->all());
        $this->assertContains($b['today']->id, $this->report(['from' => '2026-10-08 15:00:00', 'to' => '2026-10-09'])['batches']->pluck('id')->all(), 'Batch yang kedaluwarsa hari ini tetap ikut walau "Dari" berjam.');
        $this->assertCount(0, $this->report(['from' => '2027-01-01', 'to' => '2027-01-31'])['batches']);
    }

    public function test_batch_helpers_value_and_days_until_expiry(): void
    {
        $b = $this->stock();

        $this->assertEqualsWithDelta(200000.0, $b['today']->fresh()->stockValue(), 0.001);
        $this->assertEqualsWithDelta(500000.0, $b['expired']->fresh()->stockValue(), 0.001);
        $this->assertSame([-5, 0, 12], [$b['expired']->daysUntilExpiry(), $b['today']->daysUntilExpiry(), $b['soon']->daysUntilExpiry()]);
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox_and_stock_is_national(): void
    {
        $this->stock();
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(ExpiringStockReport::canAccess());

        $staff = $this->user('kasir', $store, ['menu_access' => ['ExpiringStockReport']]);
        $this->actingAs($staff, 'web');
        $this->assertTrue(ExpiringStockReport::canAccess());
        $this->assertCount(4, $this->report([], $staff)['batches'], 'Stok nasional: staf toko melihat batch yang sama.');

        $this->actingAs($this->user('kasir', $store, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(ExpiringStockReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => ''])->test(ExpiringStockReport::class);
        $this->assertSame(['2026-07-10', '2026-11-07'], [$bad->get('from'), $bad->get('to')]);

        $reversed = Livewire::withQueryParams(['from' => '2026-10-20', 'to' => '2026-10-10'])->test(ExpiringStockReport::class);
        $this->assertSame('2026-10-20', $reversed->get('to'));

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());
    }

    public function test_page_shows_cards_status_labels_values_links_and_the_empty_state(): void
    {
        $this->stock();

        $page = $this->page(['from' => '2026-09-01']);
        $page->assertSuccessful()
            ->assertSee('Sudah Kedaluwarsa')
            ->assertSee('Total Nilai Stok Terdampak')
            ->assertSee('Rp1.310.000', false)
            ->assertSee('FA-1')
            ->assertSee('Film A')
            ->assertSee('Cairan B')
            ->assertSee('Kedaluwarsa (5 hari lalu)')
            ->assertSee('Kedaluwarsa dalam 0 hari')
            ->assertSee('Kedaluwarsa dalam 7 hari')
            ->assertSee('Kedaluwarsa dalam 12 hari')
            ->assertSee('Rp500.000', false)
            ->assertSee('Rp200.000', false)
            ->assertDontSee('-5 hari');

        $this->assertStringContainsString((string) $this->film->id, $page->instance()->materialUrl($this->film->id));

        $this->page(['from' => '2027-01-01', 'to' => '2027-01-31'])->assertSee('Tidak ada batch kedaluwarsa pada rentang ini.');
    }

    public function test_excel_rows_are_aligned_with_headings(): void
    {
        $this->stock();

        $export = new ExpiringStockReportExport($this->report(['from' => '2026-09-01']));
        $rows = $export->array();

        $this->assertSame(['SKU', 'Bahan Baku', 'Tanggal Terima', 'Tanggal Kedaluwarsa', 'Sisa Qty', 'Nilai', 'Status'], $export->headings());
        $this->assertCount(4, $rows);
        $this->assertSame(['FA-1', 'Film A', '2026-06-01', '2026-10-03', 10.0, 500000.0, 'Kedaluwarsa (5 hari lalu)'], $rows[0]);
        $this->assertSame(['FA-1', 'Film A', '2026-07-01', '2026-10-08', 5.0, 200000.0, 'Kedaluwarsa dalam 0 hari'], $rows[1]);
        $this->assertSame(['-', 'Cairan B', '2026-09-01', '2026-10-15', 2.0, 10000.0, 'Kedaluwarsa dalam 7 hari'], $rows[2]);
    }

    public function test_exports_download_and_are_logged(): void
    {
        $this->stock();
        $admin = $this->user('super_admin');
        Excel::fake();

        $page = $this->page([], $admin);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('stok-kedaluwarsa-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $admin->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame('expiring_stock', $logs->first()->properties['report']);
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();

        $this->stock();
        $this->page(['from' => '2026-09-01'])->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
