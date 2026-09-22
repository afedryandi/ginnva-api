<?php

namespace Tests\Feature;

use App\Models\RawMaterial;
use Tests\TestCase;

/**
 * Audit Majoo f38 ("Multi-satuan per bahan baku dgn faktor konversi"),
 * dibangun 2026-09-22 atas keputusan user (contoh nyata: cairan
 * pembersih/coating dibeli per Galon, dipakai per ml). Fokus test:
 * konversi murni pembantu INPUT -- current_stock/unit tetap selalu
 * dalam satuan dasar, tidak pernah tersimpan dalam purchase_unit.
 */
class RawMaterialUnitConversionTest extends TestCase
{
    public function test_converts_purchase_quantity_and_cost_to_base_unit(): void
    {
        $material = new RawMaterial([
            'unit' => 'ml',
            'purchase_unit' => 'Galon',
            'purchase_conversion_factor' => 3785,
        ]);

        $result = $material->convertPurchaseToBaseUnit(2, 300_000);

        $this->assertEquals(7570.0, $result['quantity']); // 2 galon x 3785 ml
        $this->assertEqualsWithDelta(39.63, $result['unitCost'], 0.01); // 300.000 / 7570 ml
    }

    public function test_conversion_without_cost_returns_null_unit_cost(): void
    {
        $material = new RawMaterial([
            'unit' => 'ml',
            'purchase_unit' => 'Galon',
            'purchase_conversion_factor' => 3785,
        ]);

        $result = $material->convertPurchaseToBaseUnit(1, null);

        $this->assertEquals(3785.0, $result['quantity']);
        $this->assertNull($result['unitCost']);
    }

    public function test_missing_conversion_factor_defaults_to_one_to_one(): void
    {
        $material = new RawMaterial(['unit' => 'ml']);

        $result = $material->convertPurchaseToBaseUnit(5, null);

        $this->assertEquals(5.0, $result['quantity']);
    }
}
