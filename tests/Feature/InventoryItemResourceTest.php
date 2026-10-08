<?php

namespace Tests\Feature;

use App\Exports\InventoryItemExport;
use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\InventoryItemResource\Pages\CreateInventoryItem;
use App\Filament\Resources\InventoryItemResource\Pages\EditInventoryItem;
use App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems;
use App\Models\FilmProduct;
use App\Models\InventoryItem;
use App\Models\ScrollCode;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryTestRowsExport implements FromArray
{
    public function __construct(private array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }
}

/**
 * Produk PPF/WF (unit fisik kardus/gulungan dengan QR): hak akses (hapus hanya full-access kecuali dibuka di hak detail),
 * daftar + pencarian + filter, tambah (kode otomatis, status "Ada Stok", riwayat masuk pertama), ubah, hapus, Catat Pemakaian
 * meter, Tandai Habis, Edit Panjang Gulungan (sisa <= total), batasan roll milik toko lain, ekspor, QR, dan Import Excel.
 * Migrasi sudah menanam data awal, jadi tes memakai kode/nama sendiri dan tidak menghitung seluruh tabel.
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class InventoryItemResourceTest extends TestCase
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

    private function user(string $role, ?array $menuAccess = null, ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->storeA)->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function item(string $name, string $category = 'PPF', string $status = 'in_stock', ?ScrollCode $roll = null): InventoryItem
    {
        return InventoryItem::create([
            'code' => InventoryItem::generateCode(), 'name' => $name, 'category' => $category, 'received_date' => '2026-10-01',
            'status' => $status, 'scroll_code_id' => $roll?->id,
        ]);
    }

    private function roll(string $code, ?Store $store = null, string $status = 'allocated', ?float $total = 15, ?float $remaining = 15): ScrollCode
    {
        return ScrollCode::create([
            'code' => $code, 'store_id' => $store?->id, 'status' => $status, 'usage_count' => 0,
            'total_length_meters' => $total, 'remaining_length_meters' => $remaining,
            'allocated_at' => $status === 'unallocated' ? null : now(),
        ]);
    }

    // ------------------------------------------------------------- akses

    public function test_access_and_the_stricter_delete_rule(): void
    {
        $record = new InventoryItem();

        $this->as($this->user('super_admin'));
        $this->assertTrue(InventoryItemResource::canViewAny());
        $this->assertTrue(InventoryItemResource::canCreate());
        $this->assertTrue(InventoryItemResource::canEdit($record));
        $this->assertTrue(InventoryItemResource::canDelete($record));
        $this->assertTrue(InventoryItemResource::canDeleteAny());

        $this->as($this->user('kasir'));
        $this->assertTrue(InventoryItemResource::canViewAny());
        $this->assertTrue(InventoryItemResource::canCreate());
        $this->assertTrue(InventoryItemResource::canEdit($record));
        $this->assertFalse(InventoryItemResource::canDelete($record), 'Hapus butuh full-access (default tertutup).');
        $this->assertFalse(InventoryItemResource::canDeleteAny());

        $this->as($this->user('kasir', null, null, ['menu_permissions' => ['InventoryItemResource' => ['delete']]]));
        $this->assertTrue(InventoryItemResource::canDelete($record), 'Admin bisa membuka hak hapus lewat Hak Akses Detail.');

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(InventoryItemResource::canViewAny());
        $this->assertFalse(InventoryItemResource::canCreate());
        $this->assertFalse(InventoryItemResource::canEdit($record));
    }

    // ------------------------------------------------------------- daftar

    public function test_list_search_filters_and_column_labels(): void
    {
        $this->as($this->user('super_admin'));
        $roll = $this->roll('GLN-LIST-1', $this->storeA, 'allocated', 15, 5);
        $ppf = $this->item('Zeta PPF Premium', 'PPF', 'in_stock', $roll);
        $wf = $this->item('Zeta Window Film', 'Window Film', 'out');

        Livewire::test(ListInventoryItems::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$ppf, $wf])
            ->assertTableColumnFormattedStateSet('status', 'Ada Stok', $ppf)
            ->assertTableColumnFormattedStateSet('status', 'Sudah Keluar', $wf)
            ->assertTableColumnFormattedStateSet('scrollCode.remaining_length_meters', '5.00 / 15.00 m', $ppf)
            ->searchTable($ppf->code)
            ->assertCanSeeTableRecords([$ppf])
            ->assertCanNotSeeTableRecords([$wf])
            ->searchTable('GLN-LIST-1')
            ->assertCanSeeTableRecords([$ppf])
            ->assertCanNotSeeTableRecords([$wf]);

        Livewire::test(ListInventoryItems::class)
            ->searchTable('Zeta')
            ->filterTable('status', 'out')
            ->assertCanSeeTableRecords([$wf])
            ->assertCanNotSeeTableRecords([$ppf]);

        Livewire::test(ListInventoryItems::class)
            ->searchTable('Zeta')
            ->filterTable('category', 'PPF')
            ->assertCanSeeTableRecords([$ppf])
            ->assertCanNotSeeTableRecords([$wf]);
    }

    // ------------------------------------------------------------- tambah / ubah / hapus

    public function test_create_generates_the_code_sets_stock_status_and_records_the_first_movement(): void
    {
        $admin = $this->as($this->user('super_admin'));

        Livewire::test(CreateInventoryItem::class)
            ->fillForm(['name' => 'PPF Baru', 'category' => 'PPF', 'received_date' => '2026-10-07', 'notes' => 'dari gudang pusat'])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = InventoryItem::where('name', 'PPF Baru')->firstOrFail();
        $this->assertMatchesRegularExpression('/^INV-[A-Z0-9]{8}$/', $item->code);
        $this->assertSame(['in_stock', $admin->id, '2026-10-07'], [$item->status, $item->created_by, $item->received_date->toDateString()]);

        $movement = $item->movements()->first();
        $this->assertSame(['in', $admin->id], [$movement->type, $movement->user_id], 'Riwayat masuk pertama tercatat.');
        $this->assertNotNull(Activity::where('log_name', 'inventory_item')->where('subject_id', $item->id)->where('description', 'like', '%didaftarkan%')->first());
    }

    public function test_create_validation(): void
    {
        $this->as($this->user('super_admin'));

        Livewire::test(CreateInventoryItem::class)
            ->fillForm(['name' => '', 'category' => null, 'received_date' => null])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'category' => 'required', 'received_date' => 'required']);

        Livewire::test(CreateInventoryItem::class)
            ->fillForm(['name' => 'Masa Depan', 'category' => 'PPF', 'received_date' => '2026-10-20'])
            ->call('create')
            ->assertHasFormErrors(['received_date']);

        Livewire::test(CreateInventoryItem::class)
            ->fillForm(['name' => 'Kategori Liar', 'category' => 'Lainnya', 'received_date' => '2026-10-07'])
            ->call('create')
            ->assertHasFormErrors(['category']);

        $this->assertDatabaseMissing('inventory_items', ['name' => 'Masa Depan']);
    }

    public function test_edit_keeps_the_code_and_updates_the_rest(): void
    {
        $this->as($this->user('kasir'));
        $item = $this->item('Nama Lama');
        $code = $item->code;

        Livewire::test(EditInventoryItem::class, ['record' => $item->getKey()])
            ->assertFormSet(['name' => 'Nama Lama', 'code' => $code])
            ->fillForm(['name' => 'Nama Baru', 'category' => 'Window Film', 'code' => 'INV-HACKED1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $item->refresh();
        $this->assertSame([$code, 'Nama Baru', 'Window Film'], [$item->code, $item->name, $item->category]);
    }

    public function test_delete_is_offered_only_to_full_access(): void
    {
        $item = $this->item('Untuk Dihapus');

        $this->as($this->user('kasir'));
        Livewire::test(EditInventoryItem::class, ['record' => $item->getKey()])->assertActionHidden('delete');

        $this->as($this->user('super_admin'));
        Livewire::test(EditInventoryItem::class, ['record' => $item->getKey()])
            ->assertActionVisible('delete')
            ->callAction('delete');

        $this->assertDatabaseMissing('inventory_items', ['id' => $item->id]);
    }

    public function test_bulk_delete_is_for_full_access_and_qr_bulk_is_for_everyone(): void
    {
        $a = $this->item('Bulk A');
        $b = $this->item('Bulk B');

        $this->as($this->user('kasir'));
        Livewire::test(ListInventoryItems::class)
            ->assertTableBulkActionHidden('delete')
            ->assertTableBulkActionVisible('download_qr_bulk');

        $this->as($this->user('super_admin'));
        Livewire::test(ListInventoryItems::class)
            ->callTableBulkAction('delete', [$a, $b])
            ->assertHasNoTableBulkActionErrors();

        $this->assertDatabaseMissing('inventory_items', ['id' => $a->id]);
        $this->assertDatabaseMissing('inventory_items', ['id' => $b->id]);
    }

    // ------------------------------------------------------------- Catat Pemakaian / Tandai Habis

    public function test_recording_usage_reduces_the_roll_and_logs_it(): void
    {
        $user = $this->as($this->user('kasir'));
        $roll = $this->roll('GLN-USE-1', $this->storeA, 'allocated', 15, 15);
        $item = $this->item('PPF Pakai', 'PPF', 'out', $roll);

        Livewire::test(ListInventoryItems::class)
            ->assertTableActionVisible('record_scroll_code_usage', $item)
            ->callTableAction('record_scroll_code_usage', $item, data: ['meters' => 3.5, 'note' => 'Avanza B 1234 XY'])
            ->assertHasNoTableActionErrors();

        $roll->refresh();
        $this->assertEqualsWithDelta(11.5, (float) $roll->remaining_length_meters, 0.001);
        $this->assertSame(1, $roll->usage_count);
        $usage = $roll->usages()->first();
        $this->assertSame([3.5, 'Avanza B 1234 XY', $user->id], [(float) $usage->meters, $usage->note, $usage->user_id]);
    }

    public function test_using_more_than_the_remaining_length_is_refused_and_the_last_meters_finish_the_roll(): void
    {
        $this->as($this->user('kasir'));
        $roll = $this->roll('GLN-USE-2', $this->storeA, 'allocated', 15, 2);
        $item = $this->item('PPF Sisa Sedikit', 'PPF', 'out', $roll);

        Livewire::test(ListInventoryItems::class)
            ->callTableAction('record_scroll_code_usage', $item, data: ['meters' => 5]);
        $this->assertEqualsWithDelta(2.0, (float) $roll->fresh()->remaining_length_meters, 0.001, 'Meter melebihi sisa ditolak.');
        $this->assertSame(0, $roll->usages()->count());

        Livewire::test(ListInventoryItems::class)
            ->callTableAction('record_scroll_code_usage', $item, data: ['meters' => 2]);
        $roll->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $roll->remaining_length_meters, 0.001);
        $this->assertSame('used', $roll->status);
        $this->assertNotNull($roll->used_at);
    }

    public function test_usage_and_mark_used_follow_roll_state_and_store_ownership(): void
    {
        $mine = $this->item('Roll Toko A', 'PPF', 'out', $this->roll('GLN-A', $this->storeA, 'allocated'));
        $theirs = $this->item('Roll Toko B', 'PPF', 'out', $this->roll('GLN-B', $this->storeB, 'allocated'));
        $free = $this->item('Roll Belum Dialokasikan', 'PPF', 'in_stock', $this->roll('GLN-FREE', null, 'unallocated'));
        $finished = $this->item('Roll Habis', 'PPF', 'out', $this->roll('GLN-DONE', $this->storeA, 'used', 15, 0));
        $noRoll = $this->item('Tanpa Roll');
        $noLength = $this->item('Roll Tanpa Panjang', 'PPF', 'out', $this->roll('GLN-NOLEN', $this->storeA, 'allocated', null, null));

        $this->as($this->user('kasir', null, $this->storeA));
        $page = Livewire::test(ListInventoryItems::class);
        $page->assertTableActionVisible('record_scroll_code_usage', $mine)
            ->assertTableActionHidden('record_scroll_code_usage', $theirs)
            ->assertTableActionVisible('record_scroll_code_usage', $free)
            ->assertTableActionHidden('record_scroll_code_usage', $finished)
            ->assertTableActionHidden('record_scroll_code_usage', $noRoll)
            ->assertTableActionHidden('record_scroll_code_usage', $noLength)
            ->assertTableActionVisible('mark_scroll_code_used', $mine)
            ->assertTableActionHidden('mark_scroll_code_used', $theirs)
            ->assertTableActionHidden('mark_scroll_code_used', $free, 'Hanya roll berstatus dialokasikan yang bisa ditandai habis.')
            ->assertTableActionHidden('mark_scroll_code_used', $finished);

        $this->as($this->user('super_admin'));
        Livewire::test(ListInventoryItems::class)->assertTableActionVisible('record_scroll_code_usage', $theirs);
    }

    public function test_mark_used_closes_the_roll(): void
    {
        $this->as($this->user('kasir'));
        $roll = $this->roll('GLN-CLOSE', $this->storeA, 'allocated');
        $item = $this->item('PPF Tutup', 'PPF', 'out', $roll);

        Livewire::test(ListInventoryItems::class)
            ->callTableAction('mark_scroll_code_used', $item)
            ->assertHasNoTableActionErrors();

        $roll->refresh();
        $this->assertSame('used', $roll->status);
        $this->assertNotNull($roll->used_at);
    }

    // ------------------------------------------------------------- Edit Panjang Gulungan

    public function test_edit_roll_length_is_for_full_access_and_sanity_checked(): void
    {
        $roll = $this->roll('GLN-LEN', $this->storeA, 'allocated', 15, 15);
        $item = $this->item('PPF Panjang', 'PPF', 'out', $roll);

        $this->as($this->user('kasir'));
        Livewire::test(ListInventoryItems::class)->assertTableActionHidden('edit_scroll_code_length', $item);

        $this->as($this->user('super_admin'));
        $page = Livewire::test(ListInventoryItems::class);
        $page->assertTableActionVisible('edit_scroll_code_length', $item)
            ->callTableAction('edit_scroll_code_length', $item, data: ['total_length_meters' => 10, 'remaining_length_meters' => 12])
            ->assertHasTableActionErrors(['remaining_length_meters']);
        $this->assertEqualsWithDelta(15.0, (float) $roll->fresh()->remaining_length_meters, 0.001, 'Sisa tidak boleh melebihi total.');

        Livewire::test(ListInventoryItems::class)
            ->callTableAction('edit_scroll_code_length', $item, data: ['total_length_meters' => 30, 'remaining_length_meters' => 12.5])
            ->assertHasNoTableActionErrors();
        $roll->refresh();
        $this->assertSame([30.0, 12.5], [(float) $roll->total_length_meters, (float) $roll->remaining_length_meters]);
    }

    // ------------------------------------------------------------- ekspor & QR

    public function test_export_rows_and_download_actions(): void
    {
        $roll = $this->roll('GLN-EXP', $this->storeA, 'allocated', 15, 6.5);
        $withRoll = $this->item('Ekspor Dengan Roll', 'PPF', 'in_stock', $roll);
        $plain = $this->item('Ekspor Tanpa Roll', 'Window Film', 'out');

        $export = new InventoryItemExport();
        $rows = $export->collection()->keyBy(0);

        $this->assertSame(['Kode', 'Nama Produk', 'Kategori', 'Kode Gulungan', 'Sisa Panjang', 'Total Panjang', 'Tanggal Masuk', 'Status'], $export->headings());
        $this->assertEquals([$withRoll->code, 'Ekspor Dengan Roll', 'PPF', 'GLN-EXP', 6.5, 15.0, '2026-10-01', 'Ada Stok'], $rows[$withRoll->code]);
        $this->assertEquals([$plain->code, 'Ekspor Tanpa Roll', 'Window Film', '-', '-', '-', '2026-10-01', 'Sudah Keluar'], $rows[$plain->code]);

        $this->as($this->user('super_admin'));
        Excel::fake();
        $page = Livewire::test(ListInventoryItems::class);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();
        Excel::assertDownloaded('produk-ppf-wf-20261008-100000.xlsx');
    }

    public function test_import_and_roll_menu_actions_are_for_full_access_only(): void
    {
        $this->as($this->user('kasir'));
        Livewire::test(ListInventoryItems::class)
            ->assertTableActionHidden('import')
            ->assertTableActionHidden('download_template');

        $this->as($this->user('super_admin'));
        Livewire::test(ListInventoryItems::class)
            ->assertTableActionVisible('import')
            ->assertTableActionVisible('download_template');
    }

    public function test_the_qr_pdf_downloads_for_one_item(): void
    {
        $this->as($this->user('kasir'));
        $item = $this->item('QR Satu');

        Livewire::test(ListInventoryItems::class)
            ->callTableAction('download_qr', $item)
            ->assertFileDownloaded("QR-Inventaris-{$item->code}.pdf");
    }

    // ------------------------------------------------------------- Import Excel

    public function test_excel_import_creates_items_links_or_creates_rolls_and_reports_conflicts(): void
    {
        $admin = $this->as($this->user('super_admin'));
        Storage::fake('local');
        $existing = ScrollCode::create(['code' => 'SC-EXIST', 'status' => 'unallocated', 'usage_count' => 0, 'total_length_meters' => 15, 'remaining_length_meters' => 15]);
        $product = FilmProduct::create(['sku' => 'WF-X1', 'name' => 'Film X1', 'product_type' => 'window_film', 'position' => 'front', 'base_price' => 1, 'is_active' => true]);

        Excel::store(new InventoryTestRowsExport([
            ['Nama', 'Kategori', 'Kode Model', 'Kode Gulungan', 'Tanggal Masuk', 'Catatan'],
            ['Film A', 'PPF', '', 'SC-EXIST', '05/10/2026', 'catatan A'],
            ['', 'PPF', '', '', '', ''],
            ['Film B', 'Window Film', 'X1', 'SC-NEW', '', ''],
            ['Film C', 'PPF', '', 'SC-EXIST', '', ''],
            ['Film D', 'PPF', 'ZZ', 'SC-ZZ', '', ''],
        ]), 'inv-imports/test.xlsx', 'local');

        $method = new \ReflectionMethod(InventoryItemResource::class, 'importItems');
        $method->setAccessible(true);
        $method->invoke(null, 'inv-imports/test.xlsx');

        $items = InventoryItem::whereIn('name', ['Film A', 'Film B', 'Film C', 'Film D'])->get()->keyBy('name');
        $this->assertCount(4, $items, 'Baris tanpa nama dilewati.');
        foreach ($items as $item) {
            $this->assertSame(['in_stock', $admin->id], [$item->status, $item->created_by]);
            $this->assertSame('Import Excel', $item->movements()->first()->note, 'Barang hasil import punya riwayat masuk.');
        }

        $this->assertSame($existing->id, $items['Film A']->scroll_code_id, 'Kode yang sudah terdaftar dikaitkan.');
        $this->assertSame('2026-10-05', $items['Film A']->received_date->toDateString());
        $this->assertSame('catatan A', $items['Film A']->notes);
        $this->assertSame('2026-10-08', $items['Film B']->received_date->toDateString(), 'Tanpa tanggal = hari ini.');

        $created = ScrollCode::where('code', 'SC-NEW')->firstOrFail();
        $this->assertSame([$product->id, 30.0, 30.0, 'unallocated'], [$created->film_product_id, (float) $created->total_length_meters, (float) $created->remaining_length_meters, $created->status], 'Roll baru dibuat dari sufiks SKU model.');
        $this->assertSame($created->id, $items['Film B']->scroll_code_id);

        $this->assertNull($items['Film C']->scroll_code_id, 'Kode yang sama dua kali dalam file: yang kedua dilewati.');
        $this->assertNull($items['Film D']->scroll_code_id, 'Kode model tidak dikenal: barang tetap dibuat tanpa roll.');
        $this->assertFalse(ScrollCode::where('code', 'SC-ZZ')->exists());
        Storage::disk('local')->assertMissing('inv-imports/test.xlsx');
    }

    public function test_an_unreadable_import_file_is_reported_without_creating_anything(): void
    {
        $this->as($this->user('super_admin'));
        Storage::fake('local');
        Storage::disk('local')->put('inv-imports/rusak.xlsx', 'bukan file excel');
        $before = InventoryItem::count();

        $method = new \ReflectionMethod(InventoryItemResource::class, 'importItems');
        $method->setAccessible(true);
        $method->invoke(null, 'inv-imports/rusak.xlsx');

        $this->assertSame($before, InventoryItem::count());
        Storage::disk('local')->assertMissing('inv-imports/rusak.xlsx');
    }
}
