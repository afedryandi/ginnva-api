<?php

namespace Tests\Feature;

use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\InventoryItemResource\Pages\CreateInventoryItem;
use App\Filament\Resources\RawMaterialResource;
use App\Filament\Resources\StockBatchResource;
use App\Filament\Resources\StockBatchResource\Pages\ListStockBatches;
use App\Models\FilmProduct;
use App\Models\InventoryItem;
use App\Models\RawMaterial;
use App\Models\RawMaterialBatch;
use App\Models\ScrollCode;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockBatchTestRowsExport implements FromArray
{
    public function __construct(private array $rows)
    {
    }

    public function array(): array
    {
        return $this->rows;
    }
}

/**
 * Batch Penerimaan (2026-11-23): nomor otomatis, pemasok dan faktur opsional, satu import = satu batch, PPF/WF dan bahan baku
 * dibedakan lewat jenis.
 */
class StockBatchTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'super_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function user(string $role, ?array $menuAccess = null): User
    {
        return tap(User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => $menuAccess, 'is_active' => true]), fn (User $u) => $u->assignRole($role));
    }

    private function material(string $name = 'Lem Batch'): RawMaterial
    {
        return RawMaterial::create(['name' => $name, 'unit' => 'ml', 'current_stock' => 0, 'reorder_point' => 0, 'created_by' => $this->user('super_admin')->id]);
    }

    public function test_batch_numbers_are_automatic_and_count_per_received_date(): void
    {
        $a = StockBatch::open(StockBatch::TYPE_PPF_WF, ['received_date' => '2026-11-23']);
        $b = StockBatch::open(StockBatch::TYPE_RAW_MATERIAL, ['received_date' => '2026-11-23']);
        $c = StockBatch::open(StockBatch::TYPE_PPF_WF, ['received_date' => '2026-11-24']);

        $this->assertSame('BTH-261123-001', $a->batch_number);
        $this->assertSame('BTH-261123-002', $b->batch_number);
        $this->assertSame('BTH-261124-001', $c->batch_number);
        $this->assertSame('PPF/WF', $a->typeLabel());
        $this->assertSame('Bahan Baku', $b->typeLabel());
    }

    public function test_supplier_and_invoice_are_optional(): void
    {
        $supplier = Supplier::create(['name' => 'PT Film Jaya', 'is_active' => true]);

        $plain = StockBatch::open(StockBatch::TYPE_PPF_WF, ['received_date' => '2026-11-23']);
        $full = StockBatch::open(StockBatch::TYPE_PPF_WF, ['received_date' => '2026-11-23', 'supplier_id' => $supplier->id, 'invoice_number' => '  FKT-77 ']);

        $this->assertNull($plain->supplier_id);
        $this->assertNull($plain->invoice_number);
        $this->assertSame($supplier->id, $full->supplier_id);
        $this->assertSame('FKT-77', $full->invoice_number);
    }

    public function test_every_raw_material_stock_in_opens_a_batch_but_out_and_adjustments_do_not(): void
    {
        $material = $this->material();
        $supplier = Supplier::create(['name' => 'CV Kimia', 'is_active' => true]);
        $user = $this->user('super_admin');

        $material->recordMovement('in', 10, $user->id, null, '2026-11-20', null, null, null, null, null, $supplier->id, 'SJ-1');
        $material->fresh()->recordMovement('in', 5, $user->id, null, '2026-11-20');

        $batches = RawMaterialBatch::where('raw_material_id', $material->id)->orderBy('id')->get();
        $this->assertCount(2, $batches);
        $this->assertSame('BTH-261120-001', $batches[0]->stockBatch->batch_number);
        $this->assertSame($supplier->id, $batches[0]->stockBatch->supplier_id);
        $this->assertSame('SJ-1', $batches[0]->stockBatch->invoice_number);
        $this->assertSame('BTH-261120-002', $batches[1]->stockBatch->batch_number);
        $this->assertNull($batches[1]->stockBatch->supplier_id);
        $this->assertSame(StockBatch::TYPE_RAW_MATERIAL, $batches[0]->stockBatch->type);

        $material->fresh()->recordMovement('out', 3, $user->id);
        $this->assertSame(2, StockBatch::count(), 'Stok keluar tidak membuka batch.');

        $material->fresh()->adjustStock(20, $user->id, 'Hitung ulang');
        $this->assertSame(2, StockBatch::count(), 'Penyesuaian stok (opname) tidak membuka batch penerimaan.');
        $this->assertNull(RawMaterialBatch::where('is_adjustment', true)->first()?->stock_batch_id);
    }

    public function test_the_staff_api_accepts_optional_supplier_and_invoice_for_a_stock_in(): void
    {
        $material = $this->material();
        $supplier = Supplier::create(['name' => 'CV API', 'is_active' => true]);
        $staff = $this->user('super_admin');

        $this->actingAs($staff, 'api')->postJson("/api/staff/materials/{$material->id}/movement", ['type' => 'in', 'quantity' => 4, 'supplier_id' => $supplier->id, 'invoice_number' => 'FK-9'])->assertSuccessful();
        $this->actingAs($staff, 'api')->postJson("/api/staff/materials/{$material->id}/movement", ['type' => 'in', 'quantity' => 2])->assertSuccessful();
        $this->actingAs($staff, 'api')->postJson("/api/staff/materials/{$material->id}/movement", ['type' => 'in', 'quantity' => 2, 'supplier_id' => 999999])->assertStatus(422);

        $this->assertSame(2, StockBatch::count());
        $this->assertSame('FK-9', StockBatch::orderBy('id')->first()->invoice_number);
    }

    public function test_one_ppf_wf_excel_import_is_one_batch_for_all_its_rows(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        Storage::fake('local');
        $supplier = Supplier::create(['name' => 'PT Film Jaya', 'is_active' => true]);
        FilmProduct::create(['sku' => 'WF-X1', 'name' => 'Film X1', 'product_type' => 'window_film', 'position' => 'front', 'base_price' => 1, 'is_active' => true]);

        Excel::store(new StockBatchTestRowsExport([
            ['Nama', 'Kategori', 'Kode Model', 'Kode Gulungan', 'Tanggal Masuk', 'Catatan'],
            ['Film A', 'PPF', '', '', '', ''],
            ['Film B', 'Window Film', 'X1', 'SC-NEW', '', ''],
        ]), 'inv-imports/batch.xlsx', 'local');

        $method = new \ReflectionMethod(InventoryItemResource::class, 'importItems');
        $method->setAccessible(true);
        $method->invoke(null, 'inv-imports/batch.xlsx', ['supplier_id' => $supplier->id, 'invoice_number' => 'FKT-1']);

        $this->assertSame(1, StockBatch::count());
        $batch = StockBatch::firstOrFail();
        $this->assertSame(StockBatch::TYPE_PPF_WF, $batch->type);
        $this->assertSame($supplier->id, $batch->supplier_id);
        $this->assertSame('FKT-1', $batch->invoice_number);
        $this->assertSame(2, InventoryItem::where('stock_batch_id', $batch->id)->count());
        $this->assertSame($batch->id, ScrollCode::where('code', 'SC-NEW')->value('stock_batch_id'), 'Gulungan baru dari import ikut batch itu.');
    }

    public function test_an_import_that_adds_nothing_leaves_no_empty_batch(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        Storage::fake('local');

        Excel::store(new StockBatchTestRowsExport([
            ['Nama', 'Kategori', 'Kode Model', 'Kode Gulungan', 'Tanggal Masuk', 'Catatan'],
            ['', 'PPF', '', '', '', ''],
        ]), 'inv-imports/kosong.xlsx', 'local');

        $method = new \ReflectionMethod(InventoryItemResource::class, 'importItems');
        $method->setAccessible(true);
        $method->invoke(null, 'inv-imports/kosong.xlsx', []);

        $this->assertSame(0, StockBatch::count());
    }

    public function test_one_raw_material_excel_import_is_one_batch_and_only_rows_with_opening_stock_use_it(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        Storage::fake('local');

        Excel::store(new StockBatchTestRowsExport([
            ['Nama', 'Kode', 'Kategori', 'Satuan', 'Stok Awal', 'Ambang', 'Harga', 'Tgl Daftar', 'Kedaluwarsa', 'Catatan'],
            ['Bahan A', '', '', 'ml', 10, '', '', '', '', ''],
            ['Bahan B', '', '', 'ml', 20, '', '', '', '', ''],
            ['Bahan C', '', '', 'ml', 0, '', '', '', '', ''],
        ]), 'rm-imports/batch.xlsx', 'local');

        $method = new \ReflectionMethod(RawMaterialResource::class, 'importMaterials');
        $method->setAccessible(true);
        $method->invoke(null, 'rm-imports/batch.xlsx', ['invoice_number' => 'FKT-IMP']);

        $this->assertSame(1, StockBatch::count());
        $batch = StockBatch::firstOrFail();
        $this->assertSame(StockBatch::TYPE_RAW_MATERIAL, $batch->type);
        $this->assertSame('FKT-IMP', $batch->invoice_number);
        $this->assertSame(2, RawMaterialBatch::where('stock_batch_id', $batch->id)->count());
    }

    public function test_adding_a_ppf_wf_item_through_the_form_opens_a_batch_with_the_optional_supplier(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $supplier = Supplier::create(['name' => 'PT Form', 'is_active' => true]);

        Livewire::test(CreateInventoryItem::class)
            ->fillForm(['name' => 'Film Form', 'category' => 'PPF', 'received_date' => '2026-11-23', 'batch_supplier_id' => $supplier->id, 'batch_invoice_number' => 'FKT-FORM'])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = InventoryItem::where('name', 'Film Form')->firstOrFail();
        $this->assertNotNull($item->stock_batch_id);
        $this->assertSame('BTH-261123-001', $item->stockBatch->batch_number);
        $this->assertSame($supplier->id, $item->stockBatch->supplier_id);
        $this->assertSame('FKT-FORM', $item->stockBatch->invoice_number);
    }

    public function test_the_qr_print_shows_the_batch_number_of_the_item_or_of_its_roll(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $ownBatch = StockBatch::open(StockBatch::TYPE_PPF_WF, ['received_date' => '2026-11-23']);
        $rollBatch = StockBatch::open(StockBatch::TYPE_PPF_WF, ['received_date' => '2026-11-24']);
        $roll = ScrollCode::create(['code' => 'SC-QR', 'status' => 'unallocated', 'usage_count' => 0, 'total_length_meters' => 15, 'remaining_length_meters' => 15, 'stock_batch_id' => $rollBatch->id]);

        $withOwn = InventoryItem::create(['code' => 'INV-QR00001', 'name' => 'Film Sendiri', 'category' => 'PPF', 'status' => 'in_stock', 'stock_batch_id' => $ownBatch->id]);
        $viaRoll = InventoryItem::create(['code' => 'INV-QR00002', 'name' => 'Film Lewat Gulungan', 'category' => 'PPF', 'status' => 'in_stock', 'scroll_code_id' => $roll->id]);
        $none = InventoryItem::create(['code' => 'INV-QR00003', 'name' => 'Film Lama', 'category' => 'PPF', 'status' => 'in_stock']);

        $captured = null;
        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->andReturnSelf();
        $pdf->shouldReceive('output')->andReturn('pdf');
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()->with('pdf.inventory_qr_sticker', \Mockery::on(function ($data) use (&$captured) {
            $captured = $data['items'];

            return true;
        }))->andReturn($pdf);

        $method = new \ReflectionMethod(InventoryItemResource::class, 'downloadQrPdf');
        $method->setAccessible(true);
        $method->invoke(null, InventoryItem::whereIn('id', [$withOwn->id, $viaRoll->id, $none->id])->orderBy('id')->get());

        $this->assertSame($ownBatch->batch_number, $captured[0]['batch']);
        $this->assertSame($rollBatch->batch_number, $captured[1]['batch'], 'Barang tanpa batch sendiri memakai batch gulungannya.');
        $this->assertNull($captured[2]['batch']);

        $html = view('pdf.inventory_qr_sticker', ['items' => collect([
            ['name' => 'A', 'meta' => null, 'code' => 'INV-1', 'scroll_code' => '260518020101001', 'qr' => 'data:image/png;base64,', 'batch' => 'BTH-261123-001'],
            ['name' => 'B', 'meta' => null, 'code' => 'INV-2', 'scroll_code' => null, 'qr' => 'data:image/png;base64,', 'batch' => null],
        ])])->render();
        $this->assertStringContainsString('BTH-261123-001', $html);
        $this->assertSame(1, substr_count($html, 'class="batch"'), 'Barang tanpa batch tidak menampilkan baris batch.');
        $this->assertStringContainsString('>260518020101001<', preg_replace('/\s+/', '', $html));
        $this->assertSame(1, substr_count($html, 'class="roll"'), 'Barang tanpa gulungan tidak menampilkan baris gulungan.');
        $this->assertStringNotContainsString('INV-1', $html, 'Kode barang tidak dicetak di label.');
        $this->assertStringContainsString('>A<', str_replace("\n", '', preg_replace('/\\s+/', '', $html)));
    }

    public function test_the_batch_list_tells_ppf_wf_from_raw_material_and_is_read_only(): void
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');
        $ppf = StockBatch::open(StockBatch::TYPE_PPF_WF, ['received_date' => '2026-11-23']);
        $raw = StockBatch::open(StockBatch::TYPE_RAW_MATERIAL, ['received_date' => '2026-11-23']);

        $this->assertTrue(StockBatchResource::canViewAny());
        $this->assertFalse(StockBatchResource::canCreate());
        $this->assertFalse(StockBatchResource::canEdit($ppf));
        $this->assertFalse(StockBatchResource::canDelete($ppf));

        Livewire::test(ListStockBatches::class)
            ->assertCanSeeTableRecords([$ppf, $raw])
            ->filterTable('type', StockBatch::TYPE_PPF_WF)
            ->assertCanSeeTableRecords([$ppf])
            ->assertCanNotSeeTableRecords([$raw])
            ->filterTable('type', StockBatch::TYPE_RAW_MATERIAL)
            ->assertCanSeeTableRecords([$raw])
            ->assertCanNotSeeTableRecords([$ppf]);

        $this->actingAs($this->user('kasir', []), 'web');
        $this->assertFalse(StockBatchResource::canViewAny());
        $this->actingAs($this->user('kasir', ['StockBatchResource']), 'web');
        $this->assertTrue(StockBatchResource::canViewAny());
    }
}
