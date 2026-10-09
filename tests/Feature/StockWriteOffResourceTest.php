<?php

namespace Tests\Feature;

use App\Exports\StockWriteOffExport;
use App\Filament\Resources\ConsumableItemResource\Pages\ListConsumableItems;
use App\Filament\Resources\RawMaterialResource\Pages\ListRawMaterials;
use App\Filament\Resources\StockWriteOffResource;
use App\Filament\Resources\StockWriteOffResource\Pages\ListStockWriteOffs;
use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use App\Models\Store;
use App\Models\StockWriteOff;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
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
 * Stok Terbuang: daftar hanya baca, staf hanya melihat write-off tokonya (toko = toko pencatat), pencatatan lewat aksi di
 * Daftar Bahan Baku / Barang Habis Pakai (stok turun, jurnal kerugian bila ada harga modal), nomor urut aman, filter,
 * ekspor Excel/PDF + log. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class StockWriteOffResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        StockWriteOff::withoutGlobalScopes()->delete();
        RawMaterial::query()->delete();
        ConsumableItem::query()->delete();
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?Store $store = null, ?array $menuAccess = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->storeA)->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function material(string $name, float $stock = 20, ?float $cost = 50000): RawMaterial
    {
        return RawMaterial::create(['name' => $name, 'code' => 'RM-' . strtoupper(uniqid()), 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => $stock, 'unit_cost' => $cost]);
    }

    private function writeOff(array $extra = []): StockWriteOff
    {
        $at = $extra['at'] ?? null;
        unset($extra['at']);

        $row = StockWriteOff::withoutGlobalScopes()->create(array_merge([
            'write_off_number' => StockWriteOff::generateNumber(), 'writeoffable_type' => 'raw_material', 'writeoffable_id' => 1, 'item_name' => 'Adhesive',
            'unit' => 'liter', 'quantity' => 2, 'unit_cost' => 1000, 'total_value' => 2000, 'reason' => 'damaged', 'store_id' => null,
        ], $extra));

        if ($at) {
            DB::table('stock_write_offs')->where('id', $row->id)->update(['created_at' => $at, 'updated_at' => $at]);
        }

        return $row->fresh();
    }

    // ------------------------------------------------------------- akses & cakupan toko

    public function test_access_is_read_only_and_follows_the_menu_checkbox(): void
    {
        $record = new StockWriteOff();

        $this->as($this->user('super_admin'));
        $this->assertTrue(StockWriteOffResource::canViewAny());
        $this->assertFalse(StockWriteOffResource::canCreate());
        $this->assertFalse(StockWriteOffResource::canEdit($record));
        $this->assertFalse(StockWriteOffResource::canDelete($record));

        $this->as($this->user('kasir'));
        $this->assertTrue(StockWriteOffResource::canViewAny());

        $this->as($this->user('kasir', null, ['BookingResource']));
        $this->assertFalse(StockWriteOffResource::canViewAny());
    }

    public function test_staff_only_see_write_offs_of_their_own_store(): void
    {
        $a = $this->writeOff(['store_id' => $this->storeA->id, 'item_name' => 'Punya A']);
        $b = $this->writeOff(['store_id' => $this->storeB->id, 'item_name' => 'Punya B']);
        $central = $this->writeOff(['store_id' => null, 'item_name' => 'Pusat']);

        $this->as($this->user('kasir', $this->storeA));
        Livewire::test(ListStockWriteOffs::class)
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b, $central]);

        $this->as($this->user('super_admin'));
        Livewire::test(ListStockWriteOffs::class)->assertCanSeeTableRecords([$a, $b, $central]);
    }

    // ------------------------------------------------------------- daftar & filter

    public function test_list_shows_labels_value_and_journal_flag(): void
    {
        $withJournal = $this->writeOff(['writeoffable_type' => 'raw_material', 'reason' => 'damaged', 'journal_entry_id' => null, 'quantity' => 2.5]);
        $consumable = $this->writeOff(['writeoffable_type' => 'consumable_item', 'reason' => 'expired', 'unit' => 'pcs', 'total_value' => null, 'unit_cost' => null]);

        $this->as($this->user('super_admin'));
        Livewire::test(ListStockWriteOffs::class)
            ->assertCanSeeTableRecords([$withJournal, $consumable])
            ->assertTableColumnFormattedStateSet('writeoffable_type', 'Bahan Baku', record: $withJournal)
            ->assertTableColumnFormattedStateSet('writeoffable_type', 'Barang Habis Pakai', record: $consumable)
            ->assertTableColumnFormattedStateSet('reason', 'Rusak', record: $withJournal)
            ->assertTableColumnFormattedStateSet('reason', 'Kedaluwarsa', record: $consumable)
            ->assertTableColumnFormattedStateSet('quantity', '2.50 liter', record: $withJournal);
    }

    public function test_search_and_filters(): void
    {
        $alice = $this->user('kasir', $this->storeA, null, ['name' => 'Alice']);
        $bob = $this->user('kasir', $this->storeA, null, ['name' => 'Bob']);

        $a = $this->writeOff(['item_name' => 'Adhesive', 'writeoffable_type' => 'raw_material', 'reason' => 'damaged', 'created_by' => $alice->id, 'at' => '2026-10-01 09:00:00']);
        $b = $this->writeOff(['item_name' => 'Lakban', 'writeoffable_type' => 'consumable_item', 'reason' => 'expired', 'created_by' => $bob->id, 'at' => '2026-10-05 09:00:00']);
        $c = $this->writeOff(['item_name' => 'Kain Lap', 'writeoffable_type' => 'consumable_item', 'reason' => 'lost', 'created_by' => $bob->id, 'at' => '2026-10-07 09:00:00']);

        $this->as($this->user('super_admin'));

        Livewire::test(ListStockWriteOffs::class)->searchTable('Lakban')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c]);
        Livewire::test(ListStockWriteOffs::class)->searchTable($a->write_off_number)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c]);

        Livewire::test(ListStockWriteOffs::class)->filterTable('writeoffable_type', 'consumable_item')->assertCanSeeTableRecords([$b, $c])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListStockWriteOffs::class)->filterTable('reason', 'lost')->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$a, $b]);
        Livewire::test(ListStockWriteOffs::class)->filterTable('created_by', $alice->id)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c]);
        Livewire::test(ListStockWriteOffs::class)
            ->filterTable('created_at', ['from' => '2026-10-02', 'until' => '2026-10-06'])
            ->assertCanSeeTableRecords([$b])
            ->assertCanNotSeeTableRecords([$a, $c]);
    }

    // ------------------------------------------------------------- penomoran

    public function test_numbers_follow_the_highest_sequence_of_the_day(): void
    {
        $this->assertSame('WO-20261008-0001', StockWriteOff::generateNumber());

        $first = $this->writeOff();
        $second = $this->writeOff();
        $this->assertSame('WO-20261008-0001', $first->write_off_number);
        $this->assertSame('WO-20261008-0002', $second->write_off_number);

        $first->delete();
        $this->assertSame('WO-20261008-0003', StockWriteOff::generateNumber(), 'Tidak menabrak 0002 yang masih ada.');

        $this->as($this->user('kasir', $this->storeB));
        $this->assertSame('WO-20261008-0003', StockWriteOff::generateNumber(), 'Staf toko lain tetap melihat nomor lintas toko.');
    }

    // ------------------------------------------------------------- pencatatan lewat aksi

    public function test_staff_can_record_a_write_off_and_see_it_but_other_stores_do_not(): void
    {
        $material = $this->material('Adhesive', 20, 50000);
        $staff = $this->as($this->user('kasir', $this->storeA));

        Livewire::test(ListRawMaterials::class)
            ->callTableAction('write_off', $material, data: ['quantity' => 5, 'reason' => 'damaged', 'note' => 'Tumpah'])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(15, (float) $material->fresh()->current_stock);

        $row = StockWriteOff::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->storeA->id, $row->store_id, 'Write-off ditandai toko pencatat.');
        $this->assertSame($staff->id, $row->created_by);
        $this->assertSame('WO-20261008-0001', $row->write_off_number);
        $this->assertEquals(250000, (float) $row->total_value);
        $this->assertNotNull($row->journal_entry_id);

        Livewire::test(ListStockWriteOffs::class)->assertCanSeeTableRecords([$row]);

        $this->as($this->user('kasir', $this->storeB));
        Livewire::test(ListStockWriteOffs::class)->assertCanNotSeeTableRecords([$row]);

        $this->as($this->user('super_admin'));
        Livewire::test(ListStockWriteOffs::class)->assertCanSeeTableRecords([$row]);
    }

    public function test_a_full_access_write_off_has_no_store_tag(): void
    {
        $material = $this->material('Adhesive', 20, 50000);
        $this->as($this->user('super_admin'));

        Livewire::test(ListRawMaterials::class)
            ->callTableAction('write_off', $material, data: ['quantity' => 1, 'reason' => 'lost'])
            ->assertHasNoTableActionErrors();

        $this->assertNull(StockWriteOff::withoutGlobalScopes()->firstOrFail()->store_id);
    }

    public function test_the_action_rejects_more_than_the_stock_and_unknown_reasons(): void
    {
        $material = $this->material('Adhesive', 3, 50000);
        $this->as($this->user('kasir', $this->storeA));

        Livewire::test(ListRawMaterials::class)
            ->callTableAction('write_off', $material, data: ['quantity' => 5, 'reason' => 'damaged'])
            ->assertHasTableActionErrors(['quantity']);
        Livewire::test(ListRawMaterials::class)
            ->callTableAction('write_off', $material, data: ['quantity' => 1, 'reason' => 'ngawur'])
            ->assertHasTableActionErrors(['reason']);
        Livewire::test(ListRawMaterials::class)
            ->callTableAction('write_off', $material, data: ['quantity' => 0, 'reason' => 'damaged'])
            ->assertHasTableActionErrors(['quantity']);

        $this->assertEquals(3, (float) $material->fresh()->current_stock);
        $this->assertSame(0, StockWriteOff::withoutGlobalScopes()->count());
    }

    public function test_a_consumable_write_off_without_cost_skips_the_journal(): void
    {
        $item = ConsumableItem::create(['name' => 'Lakban', 'code' => 'CI-' . uniqid(), 'category' => 'umum', 'unit' => 'pcs', 'current_stock' => 10]);
        $this->as($this->user('kasir', $this->storeA));

        Livewire::test(ListConsumableItems::class)
            ->callTableAction('write_off', $item, data: ['quantity' => 4, 'reason' => 'expired'])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(6, (float) $item->fresh()->current_stock);
        $row = StockWriteOff::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('consumable_item', $row->writeoffable_type);
        $this->assertNull($row->total_value);
        $this->assertNull($row->journal_entry_id);
        $this->assertSame($this->storeA->id, $row->store_id);
    }

    // ------------------------------------------------------------- ekspor

    public function test_excel_export_follows_the_filter_and_is_logged(): void
    {
        $alice = $this->user('kasir', $this->storeA, null, ['name' => 'Alice']);
        $this->writeOff(['item_name' => 'Adhesive', 'reason' => 'damaged', 'quantity' => 2.5, 'total_value' => 125000, 'created_by' => $alice->id, 'note' => 'Tumpah', 'journal_entry_id' => null]);
        $this->writeOff(['item_name' => 'Lakban', 'writeoffable_type' => 'consumable_item', 'reason' => 'expired', 'unit' => 'pcs', 'total_value' => null]);

        $admin = $this->as($this->user('super_admin'));
        Excel::fake();
        Livewire::test(ListStockWriteOffs::class)
            ->filterTable('reason', 'damaged')
            ->callTableAction('exportExcel')
            ->assertHasNoTableActionErrors();

        Excel::assertDownloaded('stok-terbuang-20261008-100000.xlsx', function ($export) {
            $rows = $export->query()->get();

            return $rows->count() === 1
                && $export->map($rows->first()) === ['WO-20261008-0001', '08/10/2026 10:00', 'Adhesive', 'Bahan Baku', '2.50 liter', 'Rusak', 125000.0, 'Tidak', 'Alice', 'Tumpah'];
        });

        $log = Activity::where('log_name', 'report_export')->latest('id')->firstOrFail();
        $this->assertSame('stock_write_off', $log->properties['report']);
        $this->assertSame('xlsx', $log->properties['format']);
        $this->assertSame($admin->id, $log->causer_id);

        $export = new StockWriteOffExport();
        $consumableRow = $export->query()->get()->first(fn ($r) => $r->item_name === 'Lakban');
        $mapped = $export->map($consumableRow);
        $this->assertSame('Barang Habis Pakai', $mapped[3]);
        $this->assertSame('Kedaluwarsa', $mapped[5]);
        $this->assertSame('-', $mapped[6], 'Tanpa nilai -> strip.');
    }

    public function test_pdf_export_downloads_and_is_logged(): void
    {
        $this->writeOff();
        $this->as($this->user('super_admin'));

        Livewire::test(ListStockWriteOffs::class)
            ->callTableAction('exportPdf')
            ->assertFileDownloaded('stok-terbuang-20261008-100000.pdf');

        $this->assertSame('pdf', Activity::where('log_name', 'report_export')->latest('id')->firstOrFail()->properties['format']);
    }
}
