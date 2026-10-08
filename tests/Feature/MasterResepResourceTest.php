<?php

namespace Tests\Feature;

use App\Exports\MasterResepExport;
use App\Filament\Resources\MasterResepResource;
use App\Filament\Resources\MasterResepResource\Pages\EditMasterResep;
use App\Filament\Resources\MasterResepResource\Pages\ListMasterReseps;
use App\Models\ConsumableItem;
use App\Models\FilmProduct;
use App\Models\FilmProductRecipeItem;
use App\Models\RawMaterial;
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
 * Master Resep (BOM per produk): daftar produk dengan jumlah bahan resepnya, tanpa tambah/hapus produk (hanya isi resep),
 * isi resep lewat form Edit -- bahan baku / barang habis pakai / roll film, jumlah > 0, bahan yang sama (atau dua baris roll
 * film) ditolak, resep lama bisa diubah dan dikosongkan, jejak audit per bahan, ekspor Excel/PDF.
 * Migrasi sudah menanam produk awal, jadi tes memakai SKU sendiri dan tidak menghitung seluruh tabel.
 */
class MasterResepResourceTest extends TestCase
{
    use RefreshDatabase;

    private RawMaterial $cairan;
    private ConsumableItem $lap;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->cairan = RawMaterial::create(['name' => 'Cairan Slip', 'code' => 'CS-1', 'unit' => 'ml', 'current_stock' => 1000]);
        $this->lap = ConsumableItem::create(['name' => 'Lap Microfiber', 'code' => 'LM-1', 'unit' => 'pcs', 'current_stock' => 50]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?array $menuAccess = null): User
    {
        $store = Store::firstOrCreate(['name' => 'Toko Test'], ['city' => 'Jakarta', 'address' => 'Jl. A', 'is_active' => true]);

        return tap(User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store->id, 'menu_access' => $menuAccess]), fn (User $u) => $u->assignRole($role));
    }

    private function admin(): User
    {
        return tap($this->user('super_admin'), fn ($u) => $this->actingAs($u, 'web'));
    }

    private function product(string $sku, string $type = 'ppf'): FilmProduct
    {
        return FilmProduct::create(['sku' => $sku, 'name' => "Produk {$sku}", 'product_type' => $type, 'position' => 'front', 'base_price' => 100000, 'is_active' => true]);
    }

    private function recipe(FilmProduct $product, string $type, ?int $itemId, string $name, string $unit, float $qty, ?string $note = null): FilmProductRecipeItem
    {
        return FilmProductRecipeItem::create(['film_product_id' => $product->id, 'item_type' => $type, 'item_id' => $itemId, 'item_name' => $name, 'unit' => $unit, 'standard_qty' => $qty, 'note' => $note]);
    }

    private function row(string $type, ?int $id, string $name, string $unit, float|int|null $qty, ?string $note = null): array
    {
        return ['item_type' => $type, 'item_id' => $id, 'item_name' => $name, 'unit' => $unit, 'standard_qty' => $qty, 'note' => $note];
    }

    // ------------------------------------------------------------- akses

    public function test_access_follows_staff_area_and_the_menu_checkbox_and_products_cannot_be_created_or_deleted_here(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(MasterResepResource::canViewAny());
        $this->assertTrue(MasterResepResource::canEdit(new FilmProduct()));

        $this->actingAs($this->user('kasir'), 'web');
        $this->assertTrue(MasterResepResource::canViewAny());
        $this->assertTrue(MasterResepResource::canEdit(new FilmProduct()));

        $this->actingAs($this->user('kasir', ['MasterResepResource']), 'web');
        $this->assertTrue(MasterResepResource::canViewAny());

        $this->actingAs($this->user('kasir', ['BookingResource']), 'web');
        $this->assertFalse(MasterResepResource::canViewAny());
        $this->assertFalse(MasterResepResource::canEdit(new FilmProduct()));

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertFalse(MasterResepResource::canCreate(), 'Produk dibuat di Daftar Produk.');
        $this->assertFalse(MasterResepResource::canDelete(new FilmProduct()));
        $this->assertFalse(MasterResepResource::canDeleteAny());
    }

    // ------------------------------------------------------------- daftar

    public function test_list_shows_products_with_recipe_counts_search_and_filters(): void
    {
        $this->admin();
        $filled = $this->product('RES-FILLED');
        $this->recipe($filled, 'raw_material', $this->cairan->id, 'Cairan Slip', 'ml', 50);
        $this->recipe($filled, 'film_roll', null, 'Roll Film', 'meter', 2);
        $empty = $this->product('RES-EMPTY', 'window_film');

        Livewire::test(ListMasterReseps::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$filled, $empty])
            ->assertTableColumnFormattedStateSet('recipe_items_count', '2 bahan', $filled)
            ->assertTableColumnFormattedStateSet('recipe_items_count', 'Belum diisi', $empty)
            ->assertTableColumnFormattedStateSet('product_type', 'PPF', $filled)
            ->assertTableColumnFormattedStateSet('position', '—', $filled)
            ->assertTableColumnFormattedStateSet('position', 'Kaca Depan', $empty);

        Livewire::test(ListMasterReseps::class)
            ->searchTable('RES-')
            ->filterTable('belum_diisi', true)
            ->assertCanSeeTableRecords([$empty])
            ->assertCanNotSeeTableRecords([$filled]);

        Livewire::test(ListMasterReseps::class)
            ->searchTable('RES-')
            ->filterTable('product_type', 'window_film')
            ->assertCanSeeTableRecords([$empty])
            ->assertCanNotSeeTableRecords([$filled]);
    }

    public function test_deleted_products_are_not_listed(): void
    {
        $this->admin();
        $gone = $this->product('RES-GONE');
        $gone->delete();

        Livewire::test(ListMasterReseps::class)->assertCanNotSeeTableRecords([$gone]);
    }

    // ------------------------------------------------------------- isi resep

    public function test_a_recipe_can_be_filled_with_all_three_item_kinds_and_is_audited(): void
    {
        $admin = $this->admin();
        $product = $this->product('RES-NEW');

        Livewire::test(EditMasterResep::class, ['record' => $product->getKey()])
            ->fillForm(['recipeItems' => [
                $this->row('raw_material', $this->cairan->id, 'Cairan Slip', 'ml', 50.5, 'untuk basah'),
                $this->row('consumable_item', $this->lap->id, 'Lap Microfiber', 'pcs', 2),
                $this->row('film_roll', null, 'Roll Film', 'meter', 1.5),
            ]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(MasterResepResource::getUrl('index'));

        $items = FilmProductRecipeItem::where('film_product_id', $product->id)->get()->keyBy('item_type');
        $this->assertCount(3, $items);
        $this->assertEqualsWithDelta(50.5, (float) $items['raw_material']->standard_qty, 0.001);
        $this->assertSame([$this->cairan->id, 'ml', 'untuk basah'], [$items['raw_material']->item_id, $items['raw_material']->unit, $items['raw_material']->note]);
        $this->assertSame('pcs', $items['consumable_item']->unit);
        $this->assertNull($items['film_roll']->item_id);

        $log = Activity::where('log_name', 'film_product_recipe_item')->where('subject_id', $items['raw_material']->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertStringContainsString('ditambahkan ke resep', $log->description);
    }

    public function test_existing_recipe_rows_can_be_changed_and_removed(): void
    {
        $this->admin();
        $product = $this->product('RES-EDIT');
        $keep = $this->recipe($product, 'raw_material', $this->cairan->id, 'Cairan Slip', 'ml', 50);
        $drop = $this->recipe($product, 'consumable_item', $this->lap->id, 'Lap Microfiber', 'pcs', 2);

        Livewire::test(EditMasterResep::class, ['record' => $product->getKey()])
            ->fillForm(['recipeItems' => [
                $this->row('raw_material', $this->cairan->id, 'Cairan Slip', 'ml', 75),
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseMissing('film_product_recipe_items', ['id' => $drop->id]);
        $this->assertEqualsWithDelta(75.0, (float) FilmProductRecipeItem::where('film_product_id', $product->id)->firstOrFail()->standard_qty, 0.001);
        $this->assertSame(1, FilmProductRecipeItem::where('film_product_id', $product->id)->count());
        $this->assertNotNull(Activity::where('log_name', 'film_product_recipe_item')->where('description', 'like', '%dihapus dari resep%')->first());
    }

    public function test_a_recipe_can_be_emptied(): void
    {
        $this->admin();
        $product = $this->product('RES-EMPTYME');
        $this->recipe($product, 'raw_material', $this->cairan->id, 'Cairan Slip', 'ml', 50);

        Livewire::test(EditMasterResep::class, ['record' => $product->getKey()])
            ->fillForm(['recipeItems' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, FilmProductRecipeItem::where('film_product_id', $product->id)->count());
    }

    public function test_the_same_material_twice_is_rejected_but_different_materials_are_fine(): void
    {
        $this->admin();
        $product = $this->product('RES-DUP');

        Livewire::test(EditMasterResep::class, ['record' => $product->getKey()])
            ->fillForm(['recipeItems' => [
                $this->row('raw_material', $this->cairan->id, 'Cairan Slip', 'ml', 50),
                $this->row('raw_material', $this->cairan->id, 'Cairan Slip', 'ml', 25),
            ]])
            ->call('save')
            ->assertHasFormErrors(['recipeItems']);
        $this->assertSame(0, FilmProductRecipeItem::where('film_product_id', $product->id)->count());

        // Bahan baku dan barang habis pakai yang berbeda tetap boleh berdampingan dalam satu resep.
        Livewire::test(EditMasterResep::class, ['record' => $product->getKey()])
            ->fillForm(['recipeItems' => [
                $this->row('raw_material', $this->cairan->id, 'Cairan Slip', 'ml', 1),
                $this->row('consumable_item', $this->lap->id, 'Lap Microfiber', 'pcs', 1),
            ]])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(2, FilmProductRecipeItem::where('film_product_id', $product->id)->count());
    }

    public function test_only_one_roll_film_line_is_allowed_per_recipe(): void
    {
        $this->admin();
        $product = $this->product('RES-ROLL');

        Livewire::test(EditMasterResep::class, ['record' => $product->getKey()])
            ->fillForm(['recipeItems' => [
                $this->row('film_roll', null, 'Roll Film', 'meter', 1),
                $this->row('film_roll', null, 'Roll Film', 'meter', 2),
            ]])
            ->call('save')
            ->assertHasFormErrors(['recipeItems']);

        $this->assertSame(0, FilmProductRecipeItem::where('film_product_id', $product->id)->count());
    }

    public function test_quantity_must_be_positive_and_required(): void
    {
        $this->admin();
        $product = $this->product('RES-QTY');

        foreach ([0, null, -1] as $bad) {
            Livewire::test(EditMasterResep::class, ['record' => $product->getKey()])
                ->fillForm(['recipeItems' => [$this->row('raw_material', $this->cairan->id, 'Cairan Slip', 'ml', $bad)]])
                ->call('save')
                ->assertHasFormErrors();
        }

        $this->assertSame(0, FilmProductRecipeItem::where('film_product_id', $product->id)->count());
    }

    public function test_the_edit_page_title_names_the_product(): void
    {
        $this->admin();
        $product = $this->product('RES-TITLE');

        Livewire::test(EditMasterResep::class, ['record' => $product->getKey()])
            ->assertSuccessful()
            ->assertSee('Resep — Produk RES-TITLE');
    }

    // ------------------------------------------------------------- ekspor

    public function test_export_has_one_row_per_ingredient_and_an_unfilled_row_for_empty_recipes(): void
    {
        $filled = $this->product('EXP-FILLED', 'premium_wash');
        $this->recipe($filled, 'raw_material', $this->cairan->id, 'Cairan Slip', 'ml', 50, 'basah');
        $this->recipe($filled, 'film_roll', null, 'Roll Film', 'meter', 2);
        $this->product('EXP-EMPTY', 'ppf');

        $export = new MasterResepExport();
        $rows = $export->collection()->groupBy(0);

        $this->assertSame(['SKU', 'Nama Produk', 'Tipe Produk', 'Jenis Bahan', 'Nama Bahan', 'Jumlah', 'Satuan', 'Catatan'], $export->headings());
        $this->assertCount(2, $rows['EXP-FILLED']);
        $this->assertEquals(['EXP-FILLED', 'Produk EXP-FILLED', 'Premium Wash', 'Bahan Baku', 'Cairan Slip', 50.0, 'ml', 'basah'], $rows['EXP-FILLED']->first());
        $this->assertSame('Roll Film (meteran)', $rows['EXP-FILLED']->last()[3]);
        $this->assertSame('-', $rows['EXP-FILLED']->last()[7]);
        $this->assertCount(1, $rows['EXP-EMPTY']);
        $this->assertSame(['EXP-EMPTY', 'Produk EXP-EMPTY', 'PPF', '-', 'Belum diisi', '-', '-', '-'], $rows['EXP-EMPTY']->first());
    }

    public function test_export_actions_download_and_the_pdf_names_every_product_type(): void
    {
        $this->admin();
        $wash = $this->product('PDF-WASH', 'premium_wash');
        $this->recipe($wash, 'raw_material', $this->cairan->id, 'Cairan Slip', 'ml', 10);
        Excel::fake();

        $page = Livewire::test(ListMasterReseps::class);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('master-resep-20261008-100000.xlsx');
        $html = view('pdf.master_resep', ['products' => FilmProduct::with('recipeItems')->where('sku', 'PDF-WASH')->get()])->render();
        $this->assertStringContainsString('Premium Wash', $html);
        $this->assertStringNotContainsString('premium_wash', $html);
    }
}
