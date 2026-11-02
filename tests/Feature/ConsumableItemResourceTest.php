<?php

namespace Tests\Feature;

use App\Exports\ConsumableItemExport;
use App\Filament\Resources\ConsumableItemResource;
use App\Filament\Resources\ConsumableItemResource\Pages\CreateConsumableItem;
use App\Filament\Resources\ConsumableItemResource\Pages\EditConsumableItem;
use App\Filament\Resources\ConsumableItemResource\Pages\ListConsumableItems;
use App\Models\ConsumableItem;
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

class ConsumableTestRowsExport implements FromArray
{
    public function __construct(private array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }
}

/**
 * Barang Habis Pakai (lakban, lap, cutter, dll): hak akses (hapus hanya full-access kecuali dibuka), daftar + pencarian + filter
 * (menipis / tidak bergerak / kategori), tambah dengan stok awal yang tercatat sebagai riwayat masuk, ubah (stok tidak bisa
 * diedit dari form), Sesuaikan Stok, Catat Stok (masuk + harga, keluar), Catat Stok Terbuang, ekspor, Import Excel.
 * Migrasi sudah menanam data awal, jadi tes memakai kode/nama sendiri. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class ConsumableItemResourceTest extends TestCase
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

    /** Staf gudang: menu dicentang, boleh Tambah/Ubah dan melihat harga; tidak boleh menghapus. */
    private function writer(): User
    {
        return $this->user('kasir', ['ConsumableItemResource'], ['menu_permissions' => ['ConsumableItemResource' => ['create', 'update', 'view']]]);
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function item(string $name, float $stock = 0, array $extra = []): ConsumableItem
    {
        return ConsumableItem::create(array_merge(['name' => $name, 'code' => 'BHP-' . strtoupper(uniqid()), 'category' => 'Lakban', 'unit' => 'pcs', 'current_stock' => $stock, 'received_date' => '2026-10-01'], $extra));
    }

    // ------------------------------------------------------------- akses

    public function test_access_and_the_stricter_delete_rule(): void
    {
        $record = new ConsumableItem();

        $this->as($this->user('super_admin'));
        $this->assertTrue(ConsumableItemResource::canViewAny());
        $this->assertTrue(ConsumableItemResource::canDelete($record));

        $this->as($this->writer());
        $this->assertTrue(ConsumableItemResource::canViewAny());
        $this->assertTrue(ConsumableItemResource::canCreate());
        $this->assertTrue(ConsumableItemResource::canEdit($record));
        $this->assertFalse(ConsumableItemResource::canDelete($record));
        $this->assertFalse(ConsumableItemResource::canDeleteAny());

        $this->as($this->user('kasir', ['ConsumableItemResource'], ['menu_permissions' => ['ConsumableItemResource' => ['delete']]]));
        $this->assertTrue(ConsumableItemResource::canDelete($record));

        // Akses bawaan (menu_access kosong) tidak membuka menu ini.
        $this->as($this->user('kasir'));
        $this->assertFalse(ConsumableItemResource::canViewAny());
        $this->assertFalse(ConsumableItemResource::canCreate());

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(ConsumableItemResource::canViewAny());
        $this->assertFalse(ConsumableItemResource::canCreate());
    }

    public function test_dead_stock_follows_movements_not_updated_at(): void
    {
        $quiet = $this->item('Mengendap', 20);
        $revived = $this->item('Baru Bergerak', 20);
        DB::table('consumable_items')->whereIn('id', [$quiet->id, $revived->id])->update(['created_at' => '2026-06-01 09:00:00', 'updated_at' => '2026-06-01 09:00:00']);

        // Ganti nama memperbarui updated_at, tapi tidak boleh membuatnya tampak bergerak.
        $quiet->refresh()->update(['name' => 'Mengendap (ganti nama)']);
        $revived->refresh()->recordMovement('out', 1, null, 'dipakai');

        $this->assertSame(['Mengendap (ganti nama)'], ConsumableItem::deadStock()->whereIn('id', [$quiet->id, $revived->id])->pluck('name')->all());
        $this->assertTrue($quiet->fresh()->isDeadStock());
        $this->assertFalse($revived->fresh()->isDeadStock());

        $empty = $this->item('Stok Kosong', 0);
        DB::table('consumable_items')->where('id', $empty->id)->update(['created_at' => '2026-06-01 09:00:00']);
        $this->assertFalse($empty->fresh()->isDeadStock());
    }

    // ------------------------------------------------------------- audit Barang Habis Pakai B-F

    public function test_the_unit_is_locked_after_history_the_name_is_unique_and_the_category_matches_existing_ones(): void
    {
        $this->as($this->user('super_admin'));
        $fresh = $this->item('Belum Ada Riwayat', 0);
        $used = $this->item('Sudah Ada Riwayat', 10, ['category' => 'Lakban Uji']);
        $used->recordMovement('in', 5, null, 'riwayat');

        Livewire::test(EditConsumableItem::class, ['record' => $fresh->getKey()])->assertFormFieldIsEnabled('unit');
        Livewire::test(EditConsumableItem::class, ['record' => $used->getKey()])
            ->assertFormFieldIsDisabled('unit')
            ->fillForm(['unit' => 'box', 'name' => 'Nama Lain'])
            ->call('save')
            ->assertHasNoFormErrors();
        $used->refresh();
        $this->assertSame(['pcs', 'Nama Lain'], [$used->unit, $used->name], 'Satuan tidak berubah, field lain tersimpan.');

        Livewire::test(CreateConsumableItem::class)
            ->fillForm(['name' => 'nama lain', 'unit' => 'pcs', 'received_date' => '2026-10-05'])
            ->call('create')
            ->assertHasFormErrors(['name']);

        Livewire::test(CreateConsumableItem::class)
            ->fillForm(['name' => 'Barang Kategori', 'unit' => 'pcs', 'received_date' => '2026-10-05', 'category' => ' lakban uji '])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame('Lakban Uji', ConsumableItem::where('name', 'Barang Kategori')->value('category'));
    }

    public function test_importing_the_same_file_twice_does_not_duplicate_items(): void
    {
        $this->as($this->user('super_admin'));
        Storage::fake('local');
        $this->item('Sudah Ada Duluan', 0);

        $rows = [
            ['Nama', 'Kode', 'Kategori', 'Satuan', 'Stok Awal', 'Ambang', 'Harga', 'Tgl', 'Catatan'],
            ['Impor Baru', '', '', 'pcs', 10, '', '', '', ''],
            ['IMPOR BARU', '', '', 'pcs', 5, '', '', '', ''],
            ['sudah ada duluan', '', '', 'pcs', 5, '', '', '', ''],
            ['', '', '', '', '', '', '', '', ''],
        ];
        $method = new \ReflectionMethod(ConsumableItemResource::class, 'importItems');
        $method->setAccessible(true);

        foreach ([1, 2] as $run) {
            Excel::store(new ConsumableTestRowsExport($rows), "ci-imports/dup-{$run}.xlsx", 'local');
            $method->invoke(null, "ci-imports/dup-{$run}.xlsx");
        }

        $this->assertSame(1, ConsumableItem::whereRaw('LOWER(name) = ?', ['impor baru'])->count());
        $this->assertSame(1, ConsumableItem::whereRaw('LOWER(name) = ?', ['sudah ada duluan'])->count());
        $this->assertEqualsWithDelta(10.0, (float) ConsumableItem::where('name', 'Impor Baru')->value('current_stock'), 0.001);
    }

    public function test_inactive_items_are_hidden_by_default_and_from_the_alerts_but_keep_their_stock(): void
    {
        $this->as($this->user('super_admin'));
        $active = $this->item('Zeta Aktif', 2, ['reorder_point' => 10]);
        $inactive = $this->item('Zeta Nonaktif', 2, ['reorder_point' => 10, 'is_active' => false]);

        Livewire::test(ListConsumableItems::class)->searchTable('Zeta')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive]);
        Livewire::test(ListConsumableItems::class)->searchTable('Zeta')->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$inactive])
            ->assertCanNotSeeTableRecords([$active]);

        $alerts = \App\Filament\InventoryWidgets\ConsumablesNeedingAttentionWidget::needingAttentionQuery();
        $this->assertTrue((clone $alerts)->whereKey($active->id)->exists());
        $this->assertFalse((clone $alerts)->whereKey($inactive->id)->exists());
        $this->assertEquals(2, (float) $inactive->fresh()->current_stock);
    }

    public function test_the_view_page_the_value_column_and_the_filtered_export(): void
    {
        $item = $this->item('Bernilai', 10, ['unit_cost' => 100]);

        $this->as($this->user('kasir'));
        Livewire::test(\App\Filament\Resources\ConsumableItemResource\Pages\ViewConsumableItem::class, ['record' => $item->getKey()])->assertForbidden();

        $this->as($this->user('kasir', ['ConsumableItemResource']));
        $this->assertTrue(ConsumableItemResource::canView($item));
        Livewire::test(\App\Filament\Resources\ConsumableItemResource\Pages\ViewConsumableItem::class, ['record' => $item->getKey()])
            ->assertSuccessful()
            ->assertActionHidden('edit');
        Livewire::test(ListConsumableItems::class)
            ->assertCanSeeTableRecords([$item])
            ->assertTableColumnHidden('stock_value')
            ->assertTableColumnVisible('last_movement_at');

        $this->as($this->writer());
        Livewire::test(ListConsumableItems::class)->assertTableColumnVisible('stock_value');

        $this->as($this->user('super_admin'));
        $this->item('Ekspor B', 5, ['category' => 'Kategori Ekspor Uji']);
        $this->item('Ekspor A', 5, ['category' => 'Kategori Ekspor Uji']);
        $this->item('Bukan Ekspor', 5, ['category' => 'Kategori Lain']);

        Excel::fake();
        Livewire::test(ListConsumableItems::class)
            ->filterTable('category', 'Kategori Ekspor Uji')
            ->callAction('exportExcel')
            ->assertHasNoActionErrors();
        Excel::assertDownloaded('barang-habis-pakai-20261008-100000.xlsx', function (ConsumableItemExport $export) {
            return $export->collection()->pluck(1)->all() === ['Ekspor A', 'Ekspor B'];
        });
    }

    public function test_an_opname_difference_above_the_limit_needs_a_note_and_the_list_offers_a_purchase_request(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $limited = $this->item('Zeta Berbatas', 20, ['adjustment_note_threshold' => 5, 'reorder_point' => 30]);
        $free = $this->item('Zeta Bebas', 20);

        $this->assertTrue($limited->adjustmentNeedsNote(10));
        $this->assertFalse($limited->adjustmentNeedsNote(15), 'Selisih tepat di batas tidak wajib catatan.');
        $this->assertFalse($free->adjustmentNeedsNote(0), 'Tanpa batas, aturan mati.');

        Livewire::test(ListConsumableItems::class)
            ->callTableAction('adjust_stock', $limited, data: ['actual_quantity' => 10, 'note' => ''])
            ->assertHasTableActionErrors(['note' => 'required']);
        $this->assertEquals(20, (float) $limited->fresh()->current_stock);

        Livewire::test(ListConsumableItems::class)
            ->callTableAction('adjust_stock', $limited, data: ['actual_quantity' => 10, 'note' => 'Hilang di lokasi'])
            ->assertHasNoTableActionErrors();
        $this->assertEquals(10, (float) $limited->fresh()->current_stock);

        Livewire::test(ListConsumableItems::class)
            ->callTableAction('adjust_stock', $free, data: ['actual_quantity' => 1, 'note' => ''])
            ->assertHasNoTableActionErrors();

        // Stok 10 <= ambang 30: permintaan beli ditawarkan; barang yang cukup tidak.
        $fine = $this->item('Zeta Cukup', 50, ['reorder_point' => 10]);
        Livewire::test(ListConsumableItems::class)
            ->assertTableActionVisible('requestPurchase', $limited)
            ->assertTableActionHidden('requestPurchase', $fine)
            ->callTableAction('requestPurchase', $limited, data: ['store_id' => $admin->store_id, 'quantity' => 8])
            ->assertHasNoTableActionErrors()
            ->assertTableActionHidden('requestPurchase', $limited);
        $this->assertSame('pending', \App\Models\PurchaseRequest::where('item_id', $limited->id)->where('item_type', 'consumable_item')->value('status'));
    }

    public function test_a_view_only_account_cannot_record_stock_and_does_not_see_prices(): void
    {
        $item = $this->item('Hanya Lihat', 10, ['unit_cost' => 5000]);

        $this->as($this->user('kasir', ['ConsumableItemResource']));
        $this->assertFalse(ConsumableItemResource::canEdit($item));
        $this->assertFalse(ConsumableItemResource::canSeeCost());

        Livewire::test(ListConsumableItems::class)
            ->assertTableActionHidden('adjust_stock', $item)
            ->assertTableActionHidden('write_off', $item)
            ->assertTableActionHidden('record_movement', $item)
            ->assertTableColumnHidden('unit_cost');

        $this->assertSame(['Kode', 'Nama Barang', 'Kategori', 'Stok', 'Satuan', 'Ambang Menipis'], (new ConsumableItemExport(false))->headings());
        $this->assertCount(6, (new ConsumableItemExport(false))->collection()->first());

        $this->as($this->writer());
        Livewire::test(ListConsumableItems::class)
            ->assertTableActionVisible('adjust_stock', $item)
            ->assertTableActionVisible('write_off', $item)
            ->assertTableActionVisible('record_movement', $item);
        $this->assertContains('Harga/Satuan', (new ConsumableItemExport())->headings());
    }

    // ------------------------------------------------------------- daftar

    public function test_list_search_labels_and_filters(): void
    {
        $this->as($this->user('super_admin'));
        $low = $this->item('Zeta Lakban Menipis', 2, ['reorder_point' => 5, 'category' => 'Lakban']);
        $dead = $this->item('Zeta Lap Sepi', 20, ['category' => 'Kebersihan']);
        DB::table('consumable_items')->where('id', $dead->id)->update(['created_at' => '2026-07-01 09:00:00', 'updated_at' => '2026-07-01 09:00:00']);
        $fine = $this->item('Zeta Cutter Aman', 100, ['category' => 'Kebersihan', 'reorder_point' => 10]);

        $page = fn () => Livewire::test(ListConsumableItems::class)->searchTable('Zeta');

        // Migrasi menanam banyak barang awal, jadi selalu cari "Zeta" dulu.
        $page()->assertSuccessful()
            ->assertCanSeeTableRecords([$low, $dead, $fine])
            ->assertTableColumnFormattedStateSet('current_stock', '2.00 pcs', $low)
            ->assertTableColumnFormattedStateSet('reorder_point', '5.00 pcs', $low);

        Livewire::test(ListConsumableItems::class)->searchTable($fine->code)
            ->assertCanSeeTableRecords([$fine])
            ->assertCanNotSeeTableRecords([$low, $dead]);

        $page()->filterTable('low_stock', true)
            ->assertCanSeeTableRecords([$low])
            ->assertCanNotSeeTableRecords([$dead, $fine]);

        $page()->filterTable('dead_stock', true)
            ->assertCanSeeTableRecords([$dead])
            ->assertCanNotSeeTableRecords([$low, $fine]);

        $page()->filterTable('category', 'Kebersihan')
            ->assertCanSeeTableRecords([$dead, $fine])
            ->assertCanNotSeeTableRecords([$low]);
    }

    // ------------------------------------------------------------- tambah / ubah / hapus

    public function test_creating_with_initial_stock_records_the_first_movement(): void
    {
        $admin = $this->as($this->user('super_admin'));

        Livewire::test(CreateConsumableItem::class)
            ->fillForm(['name' => 'Lakban Baru', 'code' => 'LKB-001', 'category' => 'Lakban', 'unit' => 'roll', 'received_date' => '2026-10-05', 'unit_cost' => 15000, 'reorder_point' => 5, 'current_stock' => 24])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = ConsumableItem::where('code', 'LKB-001')->firstOrFail();
        $this->assertSame([24.0, $admin->id, 'roll'], [(float) $item->current_stock, $item->created_by, $item->unit]);
        $movement = $item->movements()->first();
        $this->assertSame(['in', 24.0, 'Stok awal saat pendaftaran.', 15000.0], [$movement->type, (float) $movement->quantity, $movement->note, (float) $movement->unit_cost]);
    }

    public function test_creating_without_stock_records_nothing(): void
    {
        $this->as($this->writer());

        Livewire::test(CreateConsumableItem::class)
            ->fillForm(['name' => 'Cutter Kosong', 'unit' => 'pcs', 'received_date' => '2026-10-05'])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = ConsumableItem::where('name', 'Cutter Kosong')->firstOrFail();
        $this->assertEquals(0, $item->current_stock);
        $this->assertSame(0, $item->movements()->count());
    }

    public function test_create_validation(): void
    {
        $this->as($this->user('super_admin'));
        $this->item('Sudah Ada', 0, ['code' => 'DUP-1']);

        Livewire::test(CreateConsumableItem::class)
            ->fillForm(['name' => '', 'unit' => '', 'received_date' => null])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'unit' => 'required', 'received_date' => 'required']);

        Livewire::test(CreateConsumableItem::class)
            ->fillForm(['name' => 'Kode Kembar', 'code' => 'DUP-1', 'unit' => 'pcs', 'received_date' => '2026-10-05'])
            ->call('create')
            ->assertHasFormErrors(['code' => 'unique']);

        Livewire::test(CreateConsumableItem::class)
            ->fillForm(['name' => 'Masa Depan', 'unit' => 'pcs', 'received_date' => '2026-10-20'])
            ->call('create')
            ->assertHasFormErrors(['received_date']);

        Livewire::test(CreateConsumableItem::class)
            ->fillForm(['name' => 'Negatif', 'unit' => 'pcs', 'received_date' => '2026-10-05', 'unit_cost' => -5, 'reorder_point' => -1])
            ->call('create')
            ->assertHasFormErrors(['unit_cost', 'reorder_point']);

        $this->assertDatabaseMissing('consumable_items', ['name' => 'Kode Kembar']);
    }

    public function test_the_form_cannot_change_the_stock_but_other_fields_save(): void
    {
        $this->as($this->writer());
        $item = $this->item('Stok Terkunci', 40, ['code' => 'LOCK-1']);

        Livewire::test(EditConsumableItem::class, ['record' => $item->getKey()])
            ->assertFormSet(['name' => 'Stok Terkunci', 'code' => 'LOCK-1'])
            ->fillForm(['name' => 'Nama Baru', 'unit_cost' => 20, 'current_stock' => 9999])
            ->call('save')
            ->assertHasNoFormErrors();

        $item->refresh();
        $this->assertSame(['Nama Baru', 40.0, 'LOCK-1'], [$item->name, (float) $item->current_stock, $item->code]);
        $this->assertEqualsWithDelta(20.0, (float) $item->unit_cost, 0.001);
    }

    public function test_delete_is_offered_only_to_full_access(): void
    {
        $item = $this->item('Untuk Dihapus');

        $this->as($this->writer());
        Livewire::test(EditConsumableItem::class, ['record' => $item->getKey()])->assertActionHidden('delete');
        Livewire::test(ListConsumableItems::class)->assertTableBulkActionHidden('delete');

        $this->as($this->user('super_admin'));
        Livewire::test(EditConsumableItem::class, ['record' => $item->getKey()])->assertActionVisible('delete')->callAction('delete');
        $this->assertDatabaseMissing('consumable_items', ['id' => $item->id]);
    }

    // ------------------------------------------------------------- Sesuaikan / Catat Stok / Stok Terbuang

    public function test_adjust_stock_records_the_difference_or_nothing(): void
    {
        $this->as($this->writer());
        $item = $this->item('Opname', 50);

        Livewire::test(ListConsumableItems::class)
            ->callTableAction('adjust_stock', $item, data: ['actual_quantity' => 46, 'note' => 'opname bulanan'])
            ->assertHasNoTableActionErrors();

        $item->refresh();
        $this->assertEqualsWithDelta(46.0, (float) $item->current_stock, 0.001);
        $movement = $item->movements()->first();
        $this->assertSame(['adjustment', -4.0, 'opname bulanan'], [$movement->type, (float) $movement->quantity, $movement->note]);

        Livewire::test(ListConsumableItems::class)->callTableAction('adjust_stock', $item->fresh(), data: ['actual_quantity' => 46]);
        $this->assertSame(1, $item->movements()->count(), 'Tanpa selisih: tidak ada riwayat baru.');

        Livewire::test(ListConsumableItems::class)
            ->callTableAction('adjust_stock', $item->fresh(), data: ['actual_quantity' => -1])
            ->assertHasTableActionErrors(['actual_quantity']);
    }

    public function test_recording_stock_in_and_out(): void
    {
        $user = $this->as($this->writer());
        $item = $this->item('Catat', 10, ['unit_cost' => 1000]);

        Livewire::test(ListConsumableItems::class)
            ->callTableAction('record_movement', $item, data: ['type' => 'in', 'quantity' => 20, 'unit_cost' => 1200, 'note' => 'PO-9'])
            ->assertHasNoTableActionErrors();

        $item->refresh();
        $this->assertEqualsWithDelta(30.0, (float) $item->current_stock, 0.001);
        $this->assertEqualsWithDelta(1200.0, (float) $item->unit_cost, 0.001, 'Harga terakhir ikut diperbarui.');
        $in = $item->movements()->where('type', 'in')->first();
        $this->assertSame([20.0, 1200.0, 'PO-9', $user->id], [(float) $in->quantity, (float) $in->unit_cost, $in->note, $in->user_id]);

        Livewire::test(ListConsumableItems::class)
            ->callTableAction('record_movement', $item->fresh(), data: ['type' => 'out', 'quantity' => 8, 'note' => 'dipakai booking'])
            ->assertHasNoTableActionErrors();
        $this->assertEqualsWithDelta(22.0, (float) $item->fresh()->current_stock, 0.001);
        $this->assertNull($item->movements()->where('type', 'out')->first()->unit_cost, 'Harga hanya dicatat untuk stok masuk.');

        Livewire::test(ListConsumableItems::class)
            ->callTableAction('record_movement', $item->fresh(), data: ['type' => 'out', 'quantity' => 500]);
        $this->assertEqualsWithDelta(22.0, (float) $item->fresh()->current_stock, 0.001, 'Keluar melebihi stok ditolak.');

        Livewire::test(ListConsumableItems::class)
            ->callTableAction('record_movement', $item->fresh(), data: ['type' => 'in', 'quantity' => 0])
            ->assertHasTableActionErrors(['quantity']);
    }

    public function test_write_off_reduces_stock_and_posts_the_loss_when_a_cost_exists(): void
    {
        $this->as($this->user('super_admin'));
        $priced = $this->item('Terbuang Berharga', 30, ['unit_cost' => 2000]);
        $unpriced = $this->item('Terbuang Tanpa Harga', 30);

        Livewire::test(ListConsumableItems::class)
            ->callTableAction('write_off', $priced, data: ['quantity' => 3, 'reason' => 'damaged', 'note' => 'patah'])
            ->assertHasNoTableActionErrors();

        $this->assertEqualsWithDelta(27.0, (float) $priced->fresh()->current_stock, 0.001);
        $writeOff = StockWriteOff::latest('id')->first();
        $this->assertSame('damaged', $writeOff->reason);
        $this->assertEqualsWithDelta(6000.0, (float) $writeOff->total_value, 0.001);
        $this->assertNotNull($writeOff->journal_entry_id);

        Livewire::test(ListConsumableItems::class)
            ->callTableAction('write_off', $unpriced, data: ['quantity' => 5, 'reason' => 'lost'])
            ->assertHasNoTableActionErrors();
        $this->assertEqualsWithDelta(25.0, (float) $unpriced->fresh()->current_stock, 0.001);
        $this->assertNull(StockWriteOff::latest('id')->first()->total_value);
    }

    public function test_write_off_cannot_exceed_the_stock_or_use_an_unknown_reason(): void
    {
        $this->as($this->writer());
        $item = $this->item('Terbuang Terbatas', 10, ['unit_cost' => 100]);
        $before = StockWriteOff::count();

        Livewire::test(ListConsumableItems::class)
            ->callTableAction('write_off', $item, data: ['quantity' => 11, 'reason' => 'damaged'])
            ->assertHasTableActionErrors(['quantity']);
        Livewire::test(ListConsumableItems::class)
            ->callTableAction('write_off', $item, data: ['quantity' => 2, 'reason' => 'bukan-alasan'])
            ->assertHasTableActionErrors(['reason']);

        $this->assertEqualsWithDelta(10.0, (float) $item->fresh()->current_stock, 0.001);
        $this->assertSame($before, StockWriteOff::count());
    }

    // ------------------------------------------------------------- ekspor & impor

    public function test_export_rows_and_download_actions(): void
    {
        $this->item('Export A', 20, ['code' => 'EXP-A', 'category' => 'Lakban', 'reorder_point' => 5, 'unit_cost' => 7.5]);
        $this->item('Export B', 3, ['code' => 'EXP-B', 'category' => null]);

        $export = new ConsumableItemExport();
        $rows = $export->collection()->keyBy(0);

        $this->assertSame(['Kode', 'Nama Barang', 'Kategori', 'Stok', 'Satuan', 'Ambang Menipis', 'Harga/Satuan'], $export->headings());
        $this->assertEquals(['EXP-A', 'Export A', 'Lakban', 20.0, 'pcs', 5.0, 7.5], $rows['EXP-A']);
        $this->assertEquals(['EXP-B', 'Export B', '-', 3.0, 'pcs', '-', '-'], $rows['EXP-B']);

        $this->as($this->writer());
        Excel::fake();
        $page = Livewire::test(ListConsumableItems::class);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();
        Excel::assertDownloaded('barang-habis-pakai-20261008-100000.xlsx');
    }

    public function test_import_and_template_actions_are_for_full_access_only(): void
    {
        $this->as($this->writer());
        Livewire::test(ListConsumableItems::class)->assertTableActionHidden('import')->assertTableActionHidden('download_template');

        $this->as($this->user('super_admin'));
        Livewire::test(ListConsumableItems::class)->assertTableActionVisible('import')->assertTableActionVisible('download_template');
    }

    public function test_excel_import_creates_items_with_opening_stock_and_skips_bad_rows(): void
    {
        $admin = $this->as($this->user('super_admin'));
        Storage::fake('local');
        $this->item('Sudah Ada', 0, ['code' => 'IMP-EXIST']);

        Excel::store(new ConsumableTestRowsExport([
            ['Nama', 'Kode', 'Kategori', 'Satuan', 'Stok Awal', 'Ambang', 'Harga', 'Tgl Masuk', 'Catatan'],
            ['Lakban A', 'IMP-A', 'Lakban', 'roll', 24, 5, 15000, '05/10/2026', 'catatan A'],
            ['', 'IMP-X', '', 'pcs', 1, '', '', '', ''],
            ['Tanpa Satuan', '', '', '', 1, '', '', '', ''],
            ['Lakban B', 'IMP-EXIST', '', 'roll', 0, '', '', '', ''],
            ['Lakban C', 'IMP-A', '', 'roll', 10, '', '', '', ''],
            ['Stok Negatif', 'IMP-NEG', '', 'pcs', -5, '', '', '', ''],
            ['Harga Negatif', 'IMP-NEG2', '', 'pcs', 5, '', -3, '', ''],
        ]), 'bhp-imports/test.xlsx', 'local');

        $method = new \ReflectionMethod(ConsumableItemResource::class, 'importItems');
        $method->setAccessible(true);
        $method->invoke(null, 'bhp-imports/test.xlsx');

        $a = ConsumableItem::where('code', 'IMP-A')->firstOrFail();
        $this->assertSame(['Lakban A', 'Lakban', 'roll', $admin->id, '2026-10-05', 'catatan A'], [$a->name, $a->category, $a->unit, $a->created_by, $a->received_date->toDateString(), $a->notes]);
        $this->assertSame([24.0, 5.0, 15000.0], [(float) $a->current_stock, (float) $a->reorder_point, (float) $a->unit_cost]);
        $this->assertSame('Stok awal dari import Excel.', $a->movements()->first()->note);

        $this->assertNull(ConsumableItem::where('name', 'Lakban B')->firstOrFail()->code, 'Kode yang sudah dipakai: barang tetap dibuat tanpa kode.');
        $this->assertNull(ConsumableItem::where('name', 'Lakban C')->firstOrFail()->code, 'Kode ganda dalam file: yang kedua tanpa kode.');
        foreach (['Tanpa Satuan', 'Stok Negatif', 'Harga Negatif'] as $skipped) {
            $this->assertDatabaseMissing('consumable_items', ['name' => $skipped]);
        }
        Storage::disk('local')->assertMissing('bhp-imports/test.xlsx');
    }
}
