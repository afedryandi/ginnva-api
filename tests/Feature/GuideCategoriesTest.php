<?php

namespace Tests\Feature;

use App\Support\GuideCatalog;
use Tests\TestCase;

/**
 * Pengelompokan panduan: tiap panduan punya kelompok yang dikenal, tidak ada pemetaan yatim, dan kelompok punya urutan tetap.
 */
class GuideCategoriesTest extends TestCase
{
    public function test_every_guide_has_a_known_category(): void
    {
        foreach (GuideCatalog::all() as $guide) {
            $this->assertArrayHasKey($guide['category'], GuideCatalog::CATEGORIES, "Panduan `{$guide['id']}` belum punya kelompok di GuideCatalog::CATEGORY_BY_ID.");
        }
    }

    public function test_the_category_map_has_no_orphans(): void
    {
        $ids = collect(GuideCatalog::all())->pluck('id')->all();

        foreach (array_keys(GuideCatalog::CATEGORY_BY_ID) as $id) {
            $this->assertContains($id, $ids, "Pemetaan kelompok untuk panduan `{$id}` yang tidak ada.");
        }
    }
}
