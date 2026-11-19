<?php

namespace Tests\Feature;

use App\Support\HiddenInventoryTypes;
use Tests\TestCase;

/** PPF/WF berdiri sendiri (keputusan pemilik 2026-12-20): pilihan gulungan hilang dari memo kecuali saklar memo_ppf_wf menyala. */
class MemoWithoutPpfWfTest extends TestCase
{
    public function test_ppf_wf_is_hidden_from_memo_choices_when_the_switch_is_off(): void
    {
        config(['features.memo_ppf_wf' => false]);

        $options = HiddenInventoryTypes::filter(['raw_material' => 'Bahan Baku', 'inventory_item' => 'PPF/WF (Gulungan)']);

        $this->assertSame(['raw_material' => 'Bahan Baku'], $options);
        $this->assertSame(['raw_material'], HiddenInventoryTypes::allowed(['raw_material', 'inventory_item']));
    }

    public function test_ppf_wf_stays_available_when_the_switch_is_on(): void
    {
        config(['features.memo_ppf_wf' => true]);

        $this->assertArrayHasKey('inventory_item', HiddenInventoryTypes::filter(['raw_material' => 'Bahan Baku', 'inventory_item' => 'PPF/WF (Gulungan)']));
        $this->assertContains('inventory_item', HiddenInventoryTypes::allowed(['raw_material', 'inventory_item']));
    }
}
