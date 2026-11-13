<?php

namespace Tests\Feature;

use App\Filament\Resources\AssetResource;
use App\Filament\Resources\ConsumableItemMovementResource;
use App\Filament\Resources\ConsumableItemResource;
use App\Filament\Resources\RawMaterialResource;
use App\Models\User;
use App\Support\GuideCatalog;
use App\Support\HiddenInventoryTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Barang Habis Pakai dan Aset Tetap dimatikan lewat saklar fitur (keputusan pemilik 2026-11-13): menunya, hal yang terkait, dan panduannya
 * hilang; Bahan Baku tidak terpengaruh. Tes memeriksa kedua keadaan (phpunit.xml menyalakan keduanya secara bawaan).
 */
class ConsumablesAssetsHiddenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x', 'join_date' => '2025-01-01', 'is_active' => true]);
        $this->admin->assignRole('super_admin');
    }

    public function test_switched_off_the_menus_close_even_for_full_access_but_raw_materials_stay(): void
    {
        config(['features.consumables' => false, 'features.assets' => false]);
        $this->actingAs($this->admin);

        $this->assertFalse($this->admin->hasExplicitMenuAccess(ConsumableItemResource::class));
        $this->assertFalse($this->admin->hasExplicitMenuAccess(ConsumableItemMovementResource::class));
        $this->assertFalse($this->admin->hasExplicitMenuAccess(AssetResource::class));
        $this->assertFalse($this->admin->hasConsumableAccess());
        $this->assertFalse($this->admin->hasAssetAccess());
        $this->assertFalse(ConsumableItemResource::canViewAny());
        $this->assertFalse(AssetResource::canViewAny());

        $this->assertTrue($this->admin->hasExplicitMenuAccess(RawMaterialResource::class), 'Bahan Baku tidak terpengaruh.');
        $this->assertTrue($this->admin->hasMaterialAccess());
    }

    public function test_switched_on_everything_works_as_before(): void
    {
        config(['features.consumables' => true, 'features.assets' => true]);
        $this->actingAs($this->admin);

        $this->assertTrue($this->admin->hasConsumableAccess());
        $this->assertTrue($this->admin->hasAssetAccess());
        $this->assertTrue(ConsumableItemResource::canViewAny());
        $this->assertTrue(AssetResource::canViewAny());
    }

    public function test_the_item_type_choices_drop_the_hidden_types(): void
    {
        $all = ['raw_material' => 'Bahan Baku', 'consumable_item' => 'Barang Habis Pakai', 'asset' => 'Aset Baru'];

        config(['features.consumables' => false, 'features.assets' => false]);
        $this->assertSame(['raw_material' => 'Bahan Baku'], HiddenInventoryTypes::filter($all));
        $this->assertSame(['raw_material'], HiddenInventoryTypes::allowed(['raw_material', 'consumable_item', 'asset']));

        config(['features.consumables' => true, 'features.assets' => false]);
        $this->assertSame(['raw_material', 'consumable_item'], array_keys(HiddenInventoryTypes::filter($all)));

        config(['features.consumables' => true, 'features.assets' => true]);
        $this->assertSame($all, HiddenInventoryTypes::filter($all));
    }

    public function test_the_guides_stop_mentioning_the_hidden_menus(): void
    {
        config(['features.consumables' => false, 'features.assets' => false]);

        $guides = collect(GuideCatalog::all())->keyBy('id');

        $this->assertFalse($guides->has('aset-tetap'), 'Panduan Aset Tetap disembunyikan.');

        foreach (['stok', 'permohonan-pembelian', 'riwayat-stok-dashboard', 'analisa-laporan'] as $id) {
            $text = mb_strtolower(json_encode($guides[$id], JSON_UNESCAPED_UNICODE));

            $this->assertStringNotContainsString('barang habis pakai', $text, $id);
            $this->assertStringNotContainsString('aset bermasalah', $text, $id);
            $this->assertStringNotContainsString('aset baru', $text, $id);
        }

        $this->assertNotContains('ConsumableItemMovementResource', $guides['riwayat-stok-dashboard']['menus']);
    }

    public function test_the_guides_are_unchanged_when_both_are_switched_on(): void
    {
        config(['features.consumables' => true, 'features.assets' => true]);

        $guides = collect(GuideCatalog::all())->keyBy('id');

        $this->assertTrue($guides->has('aset-tetap'));
        $this->assertStringContainsString('Barang Habis Pakai', json_encode($guides['stok'], JSON_UNESCAPED_UNICODE));
        $this->assertContains('ConsumableItemMovementResource', $guides['riwayat-stok-dashboard']['menus']);
    }
}
