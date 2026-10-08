<?php

namespace Tests\Feature;

use App\Exports\SerialNumberReportExport;
use App\Filament\Pages\SerialNumberReport;
use App\Models\FilmProduct;
use App\Models\ScrollCode;
use App\Models\ScrollCodeUsage;
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
 * Laporan Serial Number (roll film): daftar roll menurut tanggal alokasi (roll "Belum Dialokasikan" tanpa filter
 * tanggal), status, toko; riwayat pemakaian meter per roll; ringkasan jumlah/sisa/meter dipakai; cakupan toko (ScrollCode
 * tanpa global scope, jadi laporan sendiri yang membatasi); sanitasi URL; Excel/PDF + log.
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class SerialNumberReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;
    private FilmProduct $product;
    private User $rina;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->product = FilmProduct::create(['sku' => 'PPF-01', 'name' => 'Film Premium', 'product_type' => 'window_film', 'position' => 'front', 'base_price' => 100000, 'is_active' => true]);
        $this->rina = $this->user('kasir', $this->storeA, ['name' => 'Teknisi Rina']);
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

    private function roll(string $code, ?Store $store, string $status, float $total, float $remaining, ?string $allocatedAt, ?string $usedAt = null): ScrollCode
    {
        return ScrollCode::create([
            'code' => $code, 'film_product_id' => $this->product->id, 'store_id' => $store?->id, 'status' => $status, 'usage_count' => 0,
            'total_length_meters' => $total, 'remaining_length_meters' => $remaining, 'allocated_at' => $allocatedAt, 'used_at' => $usedAt,
        ]);
    }

    private function usage(ScrollCode $roll, float $meters, string $at, ?string $note = null): void
    {
        $usage = ScrollCodeUsage::create(['scroll_code_id' => $roll->id, 'meters' => $meters, 'note' => $note, 'user_id' => $this->rina->id]);
        DB::table('scroll_code_usages')->where('id', $usage->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    /**
     * Dalam rentang Oktober: A1 (A, dialokasikan 2 Okt, sisa 12,5), A2 (A, habis dipakai, 3 Okt), B1 (B, 5 Okt, sisa 25),
     * A0 (A, 1 Okt 08:00, sisa 10). Di luar: OLD (A, 15 Sep). Belum dialokasikan: NEW (tanpa toko & tanggal).
     */
    private function rolls(): array
    {
        $r = [
            'a1' => $this->roll('ROLL-A1', $this->storeA, 'allocated', 30, 12.5, '2026-10-02 09:00:00'),
            'a2' => $this->roll('ROLL-A2', $this->storeA, 'used', 20, 0, '2026-10-03 09:00:00', '2026-10-06 15:00:00'),
            'b1' => $this->roll('ROLL-B1', $this->storeB, 'allocated', 25, 25, '2026-10-05 09:00:00'),
            'a0' => $this->roll('ROLL-A0', $this->storeA, 'allocated', 12, 10, '2026-10-01 08:00:00'),
            'old' => $this->roll('ROLL-OLD', $this->storeA, 'allocated', 15, 15, '2026-09-15 09:00:00'),
            'new' => $this->roll('ROLL-NEW', null, 'unallocated', 40, 40, null),
        ];
        $this->usage($r['a1'], 2.5, '2026-10-02 10:00:00', 'Mobil Avanza');
        $this->usage($r['a2'], 20, '2026-10-03 11:00:00');
        $this->usage($r['b1'], 5, '2026-10-06 09:00:00');
        $this->usage($r['old'], 3, '2026-09-20 09:00:00');

        return $r;
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test(SerialNumberReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    public function test_codes_in_range_are_listed_by_allocation_date_with_totals(): void
    {
        $r = $this->rolls();

        $result = $this->report();

        $this->assertSame([$r['b1']->id, $r['a2']->id, $r['a1']->id, $r['a0']->id], $result['codes']->pluck('id')->all(), 'Terbaru dialokasikan dulu; ROLL-OLD (Sep) dan ROLL-NEW (belum dialokasikan) tidak ikut.');
        $this->assertSame(4, $result['totalCount']);
        $this->assertSame(1, $result['usedCount']);
        $this->assertEqualsWithDelta(47.5, $result['totalRemainingMeters'], 0.001);
        $this->assertCount(3, $result['usages'], 'Pemakaian 20 Sep tidak ikut.');
        $this->assertEqualsWithDelta(27.5, $result['totalMetersUsed'], 0.001);
    }

    public function test_the_whole_first_day_counts_when_the_start_date_has_a_time(): void
    {
        $r = $this->rolls();

        $ids = $this->report(['from' => '2026-10-01 10:00:00', 'to' => '2026-10-31'])['codes']->pluck('id')->all();

        $this->assertContains($r['a0']->id, $ids, 'Roll yang dialokasikan 08:00 di hari pertama ikut walau "Dari" berjam 10:00.');
    }

    public function test_status_filter_and_unallocated_rolls_ignore_the_date_range(): void
    {
        $r = $this->rolls();

        $this->assertSame([$r['a2']->id], $this->report(['status' => 'used'])['codes']->pluck('id')->all());
        $this->assertSame([$r['new']->id], $this->report(['status' => 'unallocated', 'from' => '2026-01-01', 'to' => '2026-01-02'])['codes']->pluck('id')->all(), 'Roll belum dialokasikan tidak punya tanggal alokasi: filter tanggal tidak berlaku.');
        $this->assertEqualsCanonicalizing([$r['a1']->id, $r['b1']->id, $r['a0']->id], $this->report(['status' => 'allocated'])['codes']->pluck('id')->all());
    }

    public function test_admin_store_filter_and_staff_lock(): void
    {
        $r = $this->rolls();

        $b = $this->report(['store_id' => $this->storeB->id]);
        $this->assertSame([$r['b1']->id], $b['codes']->pluck('id')->all());
        $this->assertEqualsWithDelta(5.0, $b['totalMetersUsed'], 0.001);

        $staff = $this->user('kasir', $this->storeA);
        $own = $this->report(['store_id' => $this->storeB->id], $staff);
        $this->assertEqualsCanonicalizing([$r['a1']->id, $r['a2']->id, $r['a0']->id], $own['codes']->pluck('id')->all(), 'Staf tidak bisa melihat toko lain.');
        $this->assertEqualsWithDelta(22.5, $own['totalMetersUsed'], 0.001);
        $this->assertCount(0, $this->report(['status' => 'unallocated'], $staff)['codes'], 'Roll belum dialokasikan tidak bertoko: tidak tampil untuk staf toko.');
    }

    public function test_an_account_without_a_store_sees_nothing(): void
    {
        $this->rolls();

        $result = $this->report([], $this->user('kasir', null));

        $this->assertCount(0, $result['codes']);
        $this->assertCount(0, $result['usages']);
        $this->assertCount(0, $this->report(['status' => 'unallocated'], $this->user('kasir', null))['codes']);
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(SerialNumberReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['SerialNumberReport']]), 'web');
        $this->assertTrue(SerialNumberReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(SerialNumberReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '', 'status' => 'hilang', 'cabang' => (string) $this->storeB->id])->test(SerialNumberReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$bad->get('from'), $bad->get('to')]);
        $this->assertNull($bad->get('statusFilter'), 'Status di luar unallocated/allocated/used diabaikan.');
        $this->assertSame($this->storeB->id, $bad->get('storeId'));

        $this->assertSame('used', Livewire::withQueryParams(['status' => 'used'])->test(SerialNumberReport::class)->get('statusFilter'));

        $reversed = Livewire::withQueryParams(['from' => '2026-10-20', 'to' => '2026-10-10'])->test(SerialNumberReport::class);
        $this->assertSame('2026-10-20', $reversed->get('to'));

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertNull(Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test(SerialNumberReport::class)->get('storeId'));

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31']] as $preset => $range) {
            $page->set('data.preset', $preset);
            $this->assertSame($range, [$page->get('data.from'), $page->get('data.to')], $preset);
        }
    }

    public function test_page_shows_cards_both_tables_links_and_empty_states(): void
    {
        $r = $this->rolls();

        $page = $this->page();
        $page->assertSuccessful()
            ->assertSee('Total Roll')
            ->assertSee('47.50 m')
            ->assertSee('27.50 m')
            ->assertSee('ROLL-A1')
            ->assertSee('PPF-01 — Film Premium')
            ->assertSee('Toko B')
            ->assertSee('Dialokasikan')
            ->assertSee('Habis Dipakai')
            ->assertSee('Teknisi Rina')
            ->assertSee('Mobil Avanza')
            ->assertSee('02 Oct 2026 10:00')
            ->assertDontSee('ROLL-OLD')
            ->assertDontSee('ROLL-NEW');

        $this->assertStringContainsString((string) $r['a1']->id, $page->instance()->scrollCodeUrl($r['a1']->id));

        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])
            ->assertSee('Tidak ada serial number pada rentang/filter ini.')
            ->assertSee('Tidak ada pemakaian roll pada rentang ini.');

        $this->page(['status' => 'unallocated'])->assertSee('ROLL-NEW')->assertSee('Belum Dialokasikan');
    }

    public function test_excel_rows_are_aligned_with_both_sections(): void
    {
        $this->rolls();

        $export = new SerialNumberReportExport($this->report());
        $rows = $export->array();

        $this->assertSame(['DAFTAR SERIAL NUMBER (ROLL)'], $rows[0]);
        $this->assertSame(['Kode Serial', 'Produk', 'Toko', 'Panjang Total (m)', 'Sisa Panjang (m)', 'Tgl Alokasi', 'Tgl Habis Dipakai', 'Status'], $rows[1]);
        $this->assertSame(['ROLL-B1', 'PPF-01 - Film Premium', 'Toko B', 25.0, 25.0, '2026-10-05', '-', 'Dialokasikan'], $rows[2]);
        $this->assertSame(['ROLL-A2', 'PPF-01 - Film Premium', 'Toko A', 20.0, 0.0, '2026-10-03', '2026-10-06', 'Habis Dipakai'], $rows[3]);
        $this->assertSame([], $rows[6]);
        $this->assertSame(['RIWAYAT PEMAKAIAN'], $rows[7]);
        $this->assertSame(['Tanggal', 'Kode Serial', 'Toko', 'Meter Dipakai', 'Oleh', 'Catatan'], $rows[8]);
        $this->assertSame(['2026-10-06 09:00', 'ROLL-B1', 'Toko B', 5.0, 'Teknisi Rina', '-'], $rows[9]);
        $this->assertSame(['2026-10-02 10:00', 'ROLL-A1', 'Toko A', 2.5, 'Teknisi Rina', 'Mobil Avanza'], $rows[11]);
        $this->assertSame(['D' => '#,##0.00', 'E' => '#,##0.00'], $export->columnFormats());
    }

    public function test_exports_download_and_the_log_records_the_effective_store(): void
    {
        $this->rolls();
        $staff = $this->user('kasir', $this->storeA);
        Excel::fake();

        $page = $this->page(['store_id' => $this->storeB->id, 'status' => 'allocated'], $staff);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('laporan-serial-number-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $staff->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame('serial_number', $logs->first()->properties['report']);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());
        $this->assertSame(['allocated', 'allocated'], $logs->map(fn ($l) => $l->properties['status'])->all());
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])->callAction('exportPdf')->assertHasNoActionErrors();

        $this->rolls();
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
