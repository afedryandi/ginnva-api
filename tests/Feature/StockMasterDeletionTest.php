<?php

namespace Tests\Feature;

use App\Filament\Resources\ConsumableItemResource\Pages\EditConsumableItem;
use App\Filament\Resources\ConsumableItemResource\Pages\ListConsumableItems;
use App\Filament\Resources\RawMaterialResource\Pages\EditRawMaterial;
use App\Filament\Resources\RawMaterialResource\Pages\ListRawMaterials;
use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use App\Models\User;
use App\Support\StockMasterDeletion;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Master Data (2026-10-21): Bahan Baku dan Barang Habis Pakai yang sudah punya riwayat pergerakan stok tidak boleh dihapus (cascade di
 * database akan menghapus seluruh riwayatnya diam-diam). Yang belum pernah bergerak (salah ketik) tetap boleh.
 */
class StockMasterDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin')), 'web');
    }

    private function material(string $name): RawMaterial
    {
        return RawMaterial::create(['name' => $name, 'code' => 'RM-' . strtoupper(uniqid()), 'category' => 'Adhesive', 'unit' => 'ml', 'current_stock' => 0, 'received_date' => '2026-10-01']);
    }

    private function item(string $name): ConsumableItem
    {
        return ConsumableItem::create(['name' => $name, 'code' => 'BHP-' . strtoupper(uniqid()), 'category' => 'Lakban', 'unit' => 'pcs', 'current_stock' => 0, 'received_date' => '2026-10-01']);
    }

    public function test_the_blocker_counts_movements_and_is_empty_when_there_are_none(): void
    {
        $material = $this->material('Adhesive A');
        $this->assertNull(StockMasterDeletion::blocker($material, 'Bahan baku'));

        $material->recordMovement('in', 5, null);
        $material->recordMovement('out', 2, null);

        $message = StockMasterDeletion::blocker($material->fresh(), 'Bahan baku');
        $this->assertStringContainsString('2 riwayat pergerakan stok', $message);
        $this->assertStringContainsString('Bahan baku ini', $message);
    }

    public function test_a_raw_material_with_history_cannot_be_deleted_from_the_edit_page(): void
    {
        $material = $this->material('Adhesive A');
        $material->recordMovement('in', 5, null);

        Livewire::test(EditRawMaterial::class, ['record' => $material->getKey()])
            ->callAction('delete')
            ->assertNotified('Tidak bisa menghapus bahan baku ini');

        $this->assertNotNull(RawMaterial::find($material->id));
        $this->assertSame(1, $material->movements()->count(), 'Riwayat stok tetap utuh.');
    }

    public function test_a_raw_material_without_history_can_still_be_deleted(): void
    {
        $material = $this->material('Salah Ketik');

        Livewire::test(EditRawMaterial::class, ['record' => $material->getKey()])->callAction('delete');

        $this->assertDatabaseMissing('raw_materials', ['id' => $material->id]);
    }

    public function test_bulk_delete_of_raw_materials_skips_those_with_history(): void
    {
        $used = $this->material('Dipakai');
        $used->recordMovement('in', 5, null);
        $unused = $this->material('Belum Dipakai');

        Livewire::test(ListRawMaterials::class)
            ->callTableBulkAction('delete', [$used, $unused])
            ->assertNotified('1 dihapus, 1 tidak bisa dihapus');

        $this->assertNotNull(RawMaterial::find($used->id));
        $this->assertNull(RawMaterial::find($unused->id));
        $this->assertSame(1, $used->movements()->count());
    }

    public function test_the_same_rules_apply_to_consumable_items(): void
    {
        $used = $this->item('Lakban A');
        $used->recordMovement('in', 5, null);
        $unused = $this->item('Lakban B');

        Livewire::test(EditConsumableItem::class, ['record' => $used->getKey()])
            ->callAction('delete')
            ->assertNotified('Tidak bisa menghapus barang ini');
        $this->assertNotNull(ConsumableItem::find($used->id));

        Livewire::test(ListConsumableItems::class)
            ->callTableBulkAction('delete', [$used, $unused])
            ->assertNotified('1 dihapus, 1 tidak bisa dihapus');
        $this->assertNotNull(ConsumableItem::find($used->id));
        $this->assertNull(ConsumableItem::find($unused->id));

        Livewire::test(EditConsumableItem::class, ['record' => $this->item('Salah Ketik')->getKey()])->callAction('delete');
        $this->assertSame(1, ConsumableItem::count());
    }
}
