<?php

namespace Tests\Feature;

use App\Exports\RawMaterialExport;
use App\Filament\Resources\RawMaterialResource;
use App\Filament\Resources\RawMaterialResource\Pages\CreateRawMaterial;
use App\Filament\Resources\RawMaterialResource\Pages\EditRawMaterial;
use App\Filament\Resources\RawMaterialResource\Pages\ListRawMaterials;
use App\Models\RawMaterial;
use App\Models\RawMaterialBatch;
use App\Models\StockWriteOff;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RawMaterialTestRowsExport implements FromArray
{
    public function __construct(private array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }
}

/**
 * Daftar Bahan Baku: hak akses (hapus hanya full-access kecuali dibuka), daftar + pencarian + filter (menipis / kedaluwarsa /
 * tidak bergerak / kategori), tambah dengan stok awal yang tercatat sebagai batch + riwayat, ubah (stok tidak bisa diedit dari
 * form), Sesuaikan Stok, Catat Stok (masuk dengan batch/kedaluwarsa/harga, satuan beli, keluar FIFO), Catat Stok Terbuang,
 * ekspor, dan Import Excel. Logika model stok sudah dites di RawMaterialStockTest.
 * Migrasi sudah menanam data awal, jadi tes memakai kode/nama sendiri. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class RawMaterialResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?array $menuAccess = null, array $extra = []): User
    {
        $store = Store::firstOrCreate(['name' => 'Toko Test'], ['city' => 'Jakarta', 'address' => 'Jl. A', 'is_active' => true]);

        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function material(string $name, float $stock = 0, array $extra = []): RawMaterial
    {
        return RawMaterial::create(array_merge(['name' => $name, 'code' => 'RM-' . strtoupper(uniqid()), 'category' => 'Adhesive', 'unit' => 'ml', 'current_stock' => $stock, 'received_date' => '2026-10-01'], $extra));
    }

    // ------------------------------------------------------------- akses

    public function test_access_and_the_stricter_delete_rule(): void
    {
        $record = new RawMaterial();

        $this->as($this->user('super_admin'));
        $this->assertTrue(RawMaterialResource::canViewAny());
        $this->assertTrue(RawMaterialResource::canDelete($record));

        $this->as($this->user('kasir'));
        $this->assertTrue(RawMaterialResource::canViewAny());
        $this->assertTrue(RawMaterialResource::canCreate());
        $this->assertTrue(RawMaterialResource::canEdit($record));
        $this->assertFalse(RawMaterialResource::canDelete($record));
        $this->assertFalse(RawMaterialResource::canDeleteAny());

        $this->as($this->user('kasir', null, ['menu_permissions' => ['RawMaterialResource' => ['delete']]]));
        $this->assertTrue(RawMaterialResource::canDelete($record));

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(RawMaterialResource::canViewAny());
        $this->assertFalse(RawMaterialResource::canCreate());
    }

    // ------------------------------------------------------------- daftar

    public function test_list_search_labels_and_filters(): void
    {
        $this->as($this->user('super_admin'));
        $low = $this->material('Zeta Lem Menipis', 5, ['reorder_point' => 10, 'category' => 'Adhesive']);
        $expiring = $this->material('Zeta Lem Kedaluwarsa', 50, ['category' => 'Adhesive']);
        RawMaterialBatch::create(['raw_material_id' => $expiring->id, 'quantity' => 50, 'unit_cost' => 10, 'received_date' => '2026-09-01', 'expiry_date' => '2026-10-20']);
        $dead = $this->material('Zeta Kertas Sepi', 20, ['category' => 'Packaging']);
        DB::table('raw_materials')->where('id', $dead->id)->update(['updated_at' => '2026-07-01 09:00:00']);
        $fine = $this->material('Zeta Aman', 100, ['category' => 'Packaging', 'reorder_point' => 10]);

        // Migrasi menanam banyak bahan awal (halaman pertama penuh), jadi cari "Zeta" dulu sebelum memeriksa barisnya.
        Livewire::test(ListRawMaterials::class)
            ->assertSuccessful()
            ->searchTable('Zeta')
            ->assertCanSeeTableRecords([$low, $expiring, $dead, $fine])
            ->assertTableColumnFormattedStateSet('current_stock', '5.00 ml', $low)
            ->assertTableColumnFormattedStateSet('reorder_point', '10.00 ml', $low)
            ->searchTable($fine->code)
            ->assertCanSeeTableRecords([$fine])
            ->assertCanNotSeeTableRecords([$low, $expiring, $dead]);

        Livewire::test(ListRawMaterials::class)->searchTable('Zeta')
            ->filterTable('low_stock', true)
            ->assertCanSeeTableRecords([$low])
            ->assertCanNotSeeTableRecords([$expiring, $dead, $fine]);

        Livewire::test(ListRawMaterials::class)->searchTable('Zeta')
            ->filterTable('near_expiry', true)
            ->assertCanSeeTableRecords([$expiring])
            ->assertCanNotSeeTableRecords([$low, $dead, $fine]);

        Livewire::test(ListRawMaterials::class)->searchTable('Zeta')
            ->filterTable('dead_stock', true)
            ->assertCanSeeTableRecords([$dead])
            ->assertCanNotSeeTableRecords([$low, $expiring, $fine]);

        Livewire::test(ListRawMaterials::class)->searchTable('Zeta')
            ->filterTable('category', 'Packaging')
            ->assertCanSeeTableRecords([$dead, $fine])
            ->assertCanNotSeeTableRecords([$low, $expiring]);
    }

    public function test_the_expiry_column_comes_from_batches_that_still_have_stock(): void
    {
        $this->as($this->user('super_admin'));
        $material = $this->material('Zeta Batch', 30);
        RawMaterialBatch::create(['raw_material_id' => $material->id, 'quantity' => 0, 'received_date' => '2026-08-01', 'expiry_date' => '2026-09-01']);   // habis: diabaikan
        RawMaterialBatch::create(['raw_material_id' => $material->id, 'quantity' => 30, 'received_date' => '2026-09-01', 'expiry_date' => '2026-11-15']);

        Livewire::test(ListRawMaterials::class)
            ->assertTableColumnFormattedStateSet('earliest_expiry', '15 Nov 2026', $material);
    }

    // ------------------------------------------------------------- tambah / ubah / hapus

    public function test_creating_with_initial_stock_records_a_batch_and_a_movement(): void
    {
        $admin = $this->as($this->user('super_admin'));

        Livewire::test(CreateRawMaterial::class)
            ->fillForm(['name' => 'Lem Baru', 'code' => 'LEM-001', 'category' => 'Adhesive', 'unit' => 'ml', 'received_date' => '2026-10-05', 'unit_cost' => 12.5, 'reorder_point' => 100, 'current_stock' => 500])
            ->call('create')
            ->assertHasNoFormErrors();

        $material = RawMaterial::where('code', 'LEM-001')->firstOrFail();
        $this->assertSame([500.0, $admin->id], [(float) $material->current_stock, $material->created_by]);
        $batch = $material->batches()->first();
        $this->assertSame([500.0, 12.5, '2026-10-05'], [(float) $batch->quantity, (float) $batch->unit_cost, $batch->received_date->toDateString()]);
        $movement = $material->movements()->first();
        $this->assertSame(['in', 500.0, 'Stok awal saat pendaftaran.'], [$movement->type, (float) $movement->quantity, $movement->note]);
    }

    public function test_creating_without_stock_records_nothing(): void
    {
        $this->as($this->user('kasir'));

        Livewire::test(CreateRawMaterial::class)
            ->fillForm(['name' => 'Kertas Kosong', 'unit' => 'lembar', 'received_date' => '2026-10-05'])
            ->call('create')
            ->assertHasNoFormErrors();

        $material = RawMaterial::where('name', 'Kertas Kosong')->firstOrFail();
        $this->assertEquals(0, $material->current_stock);
        $this->assertSame(0, $material->batches()->count());
        $this->assertSame(0, $material->movements()->count());
    }

    public function test_create_validation(): void
    {
        $this->as($this->user('super_admin'));
        $this->material('Sudah Ada', 0, ['code' => 'DUP-1']);

        Livewire::test(CreateRawMaterial::class)
            ->fillForm(['name' => '', 'unit' => '', 'received_date' => null])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'unit' => 'required', 'received_date' => 'required']);

        Livewire::test(CreateRawMaterial::class)
            ->fillForm(['name' => 'Kode Kembar', 'code' => 'DUP-1', 'unit' => 'ml', 'received_date' => '2026-10-05'])
            ->call('create')
            ->assertHasFormErrors(['code' => 'unique']);

        Livewire::test(CreateRawMaterial::class)
            ->fillForm(['name' => 'Masa Depan', 'unit' => 'ml', 'received_date' => '2026-10-20'])
            ->call('create')
            ->assertHasFormErrors(['received_date']);

        Livewire::test(CreateRawMaterial::class)
            ->fillForm(['name' => 'Negatif', 'unit' => 'ml', 'received_date' => '2026-10-05', 'unit_cost' => -5, 'reorder_point' => -1])
            ->call('create')
            ->assertHasFormErrors(['unit_cost', 'reorder_point']);

        Livewire::test(CreateRawMaterial::class)
            ->fillForm(['name' => 'Satuan Beli', 'unit' => 'ml', 'received_date' => '2026-10-05', 'purchase_unit' => 'Galon', 'purchase_conversion_factor' => null])
            ->call('create')
            ->assertHasFormErrors(['purchase_conversion_factor' => 'required']);

        $this->assertDatabaseMissing('raw_materials', ['name' => 'Kode Kembar']);
    }

    public function test_the_form_cannot_change_the_stock_but_other_fields_save(): void
    {
        $this->as($this->user('kasir'));
        $material = $this->material('Stok Terkunci', 40, ['code' => 'LOCK-1']);

        Livewire::test(EditRawMaterial::class, ['record' => $material->getKey()])
            ->assertFormSet(['name' => 'Stok Terkunci', 'code' => 'LOCK-1'])
            ->fillForm(['name' => 'Nama Baru', 'unit_cost' => 20, 'current_stock' => 9999])
            ->call('save')
            ->assertHasNoFormErrors();

        $material->refresh();
        $this->assertSame(['Nama Baru', 40.0, 'LOCK-1'], [$material->name, (float) $material->current_stock, $material->code]);
        $this->assertEqualsWithDelta(20.0, (float) $material->unit_cost, 0.001);
    }

    public function test_delete_is_offered_only_to_full_access(): void
    {
        $material = $this->material('Untuk Dihapus');

        $this->as($this->user('kasir'));
        Livewire::test(EditRawMaterial::class, ['record' => $material->getKey()])->assertActionHidden('delete');
        Livewire::test(ListRawMaterials::class)->assertTableBulkActionHidden('delete');

        $this->as($this->user('super_admin'));
        Livewire::test(EditRawMaterial::class, ['record' => $material->getKey()])->assertActionVisible('delete')->callAction('delete');
        $this->assertDatabaseMissing('raw_materials', ['id' => $material->id]);
    }

    // ------------------------------------------------------------- Sesuaikan Stok / Catat Stok / Stok Terbuang

    public function test_adjust_stock_records_the_difference_or_nothing(): void
    {
        $this->as($this->user('kasir'));
        $material = $this->material('Opname', 100);

        Livewire::test(ListRawMaterials::class)
            ->callTableAction('adjust_stock', $material, data: ['actual_quantity' => 92.5, 'note' => 'opname bulanan'])
            ->assertHasNoTableActionErrors();

        $material->refresh();
        $this->assertEqualsWithDelta(92.5, (float) $material->current_stock, 0.001);
        $movement = $material->movements()->first();
        $this->assertSame(['adjustment', -7.5, 'opname bulanan'], [$movement->type, (float) $movement->quantity, $movement->note]);

        Livewire::test(ListRawMaterials::class)
            ->callTableAction('adjust_stock', $material->fresh(), data: ['actual_quantity' => 92.5]);
        $this->assertSame(1, $material->movements()->count(), 'Tanpa selisih: tidak ada riwayat baru.');

        Livewire::test(ListRawMaterials::class)
            ->callTableAction('adjust_stock', $material->fresh(), data: ['actual_quantity' => -1])
            ->assertHasTableActionErrors(['actual_quantity']);
    }

    public function test_recording_stock_in_creates_a_batch_with_expiry_and_cost_and_updates_the_last_price(): void
    {
        $this->as($this->user('kasir'));
        $material = $this->material('Masuk', 10, ['unit_cost' => 5]);

        Livewire::test(ListRawMaterials::class)
            ->callTableAction('record_movement', $material, data: ['type' => 'in', 'quantity' => 40, 'received_date' => '2026-10-06', 'expiry_date' => '2027-01-31', 'unit_cost' => 8, 'note' => 'PO-77'])
            ->assertHasNoTableActionErrors();

        $material->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $material->current_stock, 0.001);
        $this->assertEqualsWithDelta(8.0, (float) $material->unit_cost, 0.001, 'Harga terakhir ikut diperbarui.');
        $batch = $material->batches()->where('quantity', 40)->first();
        $this->assertSame(['2026-10-06', '2027-01-31', 8.0], [$batch->received_date->toDateString(), $batch->expiry_date->toDateString(), (float) $batch->unit_cost]);
        $this->assertSame('PO-77', $material->movements()->first()->note);
    }

    public function test_stock_in_can_be_entered_in_the_purchase_unit(): void
    {
        $this->as($this->user('kasir'));
        $material = $this->material('Cairan Galon', 0, ['unit' => 'ml', 'purchase_unit' => 'Galon', 'purchase_conversion_factor' => 3785]);

        Livewire::test(ListRawMaterials::class)
            ->callTableAction('record_movement', $material, data: ['type' => 'in', 'use_purchase_unit' => true, 'purchase_quantity' => 2, 'purchase_total_cost' => 757000, 'received_date' => '2026-10-06'])
            ->assertHasNoTableActionErrors();

        $material->refresh();
        $this->assertEqualsWithDelta(7570.0, (float) $material->current_stock, 0.001, '2 galon x 3.785 ml.');
        $this->assertEqualsWithDelta(100.0, (float) $material->unit_cost, 0.001, 'Rp757.000 / 7.570 ml.');
    }

    public function test_stock_out_consumes_the_oldest_batch_first_and_refuses_more_than_the_stock(): void
    {
        $this->as($this->user('kasir'));
        $material = $this->material('Keluar', 0);
        $material->recordMovement('in', 10, null, null, '2026-08-01', null, 5);
        $material->recordMovement('in', 10, null, null, '2026-09-01', null, 6);

        Livewire::test(ListRawMaterials::class)
            ->callTableAction('record_movement', $material->fresh(), data: ['type' => 'out', 'quantity' => 12])
            ->assertHasNoTableActionErrors();

        $material->refresh();
        $this->assertEqualsWithDelta(8.0, (float) $material->current_stock, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $material->batches()->where('received_date', '2026-08-01')->value('quantity'), 0.001, 'Batch tertua habis dulu.');
        $this->assertEqualsWithDelta(8.0, (float) $material->batches()->where('received_date', '2026-09-01')->value('quantity'), 0.001);

        Livewire::test(ListRawMaterials::class)
            ->callTableAction('record_movement', $material->fresh(), data: ['type' => 'out', 'quantity' => 50]);
        $this->assertEqualsWithDelta(8.0, (float) $material->fresh()->current_stock, 0.001, 'Melebihi stok ditolak.');
    }

    public function test_write_off_reduces_stock_and_posts_the_loss_when_a_cost_exists(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $priced = $this->material('Terbuang Berharga', 30, ['unit_cost' => 1000]);
        $unpriced = $this->material('Terbuang Tanpa Harga', 30);

        Livewire::test(ListRawMaterials::class)
            ->callTableAction('write_off', $priced, data: ['quantity' => 4, 'reason' => 'damaged', 'note' => 'tumpah'])
            ->assertHasNoTableActionErrors();

        $this->assertEqualsWithDelta(26.0, (float) $priced->fresh()->current_stock, 0.001);
        $writeOff = StockWriteOff::latest('id')->first();
        $this->assertSame(['damaged', $admin->id], [$writeOff->reason, $writeOff->created_by ?? $admin->id]);
        $this->assertEqualsWithDelta(4000.0, (float) $writeOff->total_value, 0.001);
        $this->assertNotNull($writeOff->journal_entry_id, 'Kerugian Rp4.000 diposting ke jurnal.');

        Livewire::test(ListRawMaterials::class)
            ->callTableAction('write_off', $unpriced, data: ['quantity' => 5, 'reason' => 'lost'])
            ->assertHasNoTableActionErrors();
        $this->assertEqualsWithDelta(25.0, (float) $unpriced->fresh()->current_stock, 0.001, 'Stok tetap berkurang walau harga kosong.');
        $this->assertNull(StockWriteOff::latest('id')->first()->total_value);
    }

    public function test_write_off_cannot_exceed_the_stock_or_use_an_unknown_reason(): void
    {
        $this->as($this->user('kasir'));
        $material = $this->material('Terbuang Terbatas', 10, ['unit_cost' => 100]);
        $before = StockWriteOff::count();

        Livewire::test(ListRawMaterials::class)
            ->callTableAction('write_off', $material, data: ['quantity' => 11, 'reason' => 'damaged'])
            ->assertHasTableActionErrors(['quantity']);
        Livewire::test(ListRawMaterials::class)
            ->callTableAction('write_off', $material, data: ['quantity' => 2, 'reason' => 'bukan-alasan'])
            ->assertHasTableActionErrors(['reason']);

        $this->assertEqualsWithDelta(10.0, (float) $material->fresh()->current_stock, 0.001);
        $this->assertSame($before, StockWriteOff::count());
    }

    // ------------------------------------------------------------- ekspor & impor

    public function test_export_rows_and_download_actions(): void
    {
        $withBatch = $this->material('Ekspor A', 20, ['code' => 'EXP-A', 'category' => 'Adhesive', 'reorder_point' => 5, 'unit_cost' => 7.5]);
        RawMaterialBatch::create(['raw_material_id' => $withBatch->id, 'quantity' => 20, 'unit_cost' => 7.5, 'received_date' => '2026-09-01', 'expiry_date' => '2026-12-31']);
        $bare = $this->material('Ekspor B', 3, ['code' => 'EXP-B', 'category' => null]);

        $export = new RawMaterialExport();
        $rows = $export->collection()->keyBy(0);

        $this->assertSame(['Kode', 'Nama Bahan', 'Kategori', 'Stok (Total)', 'Satuan', 'Ambang Menipis', 'Harga/Satuan', 'Kedaluwarsa Terdekat'], $export->headings());
        $this->assertEquals(['EXP-A', 'Ekspor A', 'Adhesive', 20.0, 'ml', 5.0, 7.5, '2026-12-31'], $rows['EXP-A']);
        $this->assertEquals(['EXP-B', 'Ekspor B', '-', 3.0, 'ml', '-', '-', '-'], $rows['EXP-B']);

        $this->as($this->user('kasir'));
        Excel::fake();
        $page = Livewire::test(ListRawMaterials::class);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();
        Excel::assertDownloaded('daftar-bahan-baku-20261008-100000.xlsx');
    }

    public function test_import_and_template_actions_are_for_full_access_only(): void
    {
        $this->as($this->user('kasir'));
        Livewire::test(ListRawMaterials::class)->assertTableActionHidden('import')->assertTableActionHidden('download_template');

        $this->as($this->user('super_admin'));
        Livewire::test(ListRawMaterials::class)->assertTableActionVisible('import')->assertTableActionVisible('download_template');
    }

    public function test_excel_import_creates_materials_with_opening_stock_and_skips_bad_rows(): void
    {
        $admin = $this->as($this->user('super_admin'));
        Storage::fake('local');
        $this->material('Sudah Ada', 0, ['code' => 'IMP-EXIST']);

        Excel::store(new RawMaterialTestRowsExport([
            ['Nama', 'Kode', 'Kategori', 'Satuan', 'Stok Awal', 'Ambang', 'Harga', 'Tgl Daftar', 'Kedaluwarsa', 'Catatan'],
            ['Lem A', 'IMP-A', 'Adhesive', 'ml', 250, 50, 12.5, '05/10/2026', '31/12/2026', 'catatan A'],
            ['', 'IMP-X', '', 'ml', 1, '', '', '', '', ''],
            ['Tanpa Satuan', '', '', '', 1, '', '', '', '', ''],
            ['Lem B', 'IMP-EXIST', '', 'gram', 0, '', '', '', '', ''],
            ['Lem C', 'IMP-A', '', 'gram', 10, '', '', '', '', ''],
            ['Stok Negatif', 'IMP-NEG', '', 'pcs', -5, '', '', '', '', ''],
            ['Harga Negatif', 'IMP-NEG2', '', 'pcs', 5, '', -3, '', '', ''],
        ]), 'rm-imports/test.xlsx', 'local');

        $method = new \ReflectionMethod(RawMaterialResource::class, 'importMaterials');
        $method->setAccessible(true);
        $method->invoke(null, 'rm-imports/test.xlsx');

        $a = RawMaterial::where('code', 'IMP-A')->firstOrFail();
        $this->assertSame(['Lem A', 'Adhesive', 'ml', $admin->id, '2026-10-05', '2026-12-31', 'catatan A'], [$a->name, $a->category, $a->unit, $a->created_by, $a->received_date->toDateString(), $a->expiry_date->toDateString(), $a->notes]);
        $this->assertSame([250.0, 50.0, 12.5], [(float) $a->current_stock, (float) $a->reorder_point, (float) $a->unit_cost]);
        $this->assertSame(1, $a->batches()->count(), 'Stok awal tercatat sebagai batch.');
        $this->assertSame('Stok awal dari import Excel.', $a->movements()->first()->note);

        $b = RawMaterial::where('name', 'Lem B')->firstOrFail();
        $this->assertNull($b->code, 'Kode yang sudah dipakai: bahan tetap dibuat tanpa kode.');
        $c = RawMaterial::where('name', 'Lem C')->firstOrFail();
        $this->assertNull($c->code, 'Kode ganda dalam file: yang kedua tanpa kode.');

        foreach (['Tanpa Satuan', 'Stok Negatif', 'Harga Negatif'] as $skipped) {
            $this->assertDatabaseMissing('raw_materials', ['name' => $skipped]);
        }
        Storage::disk('local')->assertMissing('rm-imports/test.xlsx');
    }
}
