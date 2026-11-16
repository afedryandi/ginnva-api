<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

/** Gaya navbar atas: berkas gaya ada, menyasar kelas topbar Filament, dan terpasang di head panel admin. */
class TopbarStylesTest extends TestCase
{
    public function test_the_topbar_style_partial_targets_filament_topbar_classes(): void
    {
        $css = view('filament.partials.topbar-styles')->render();

        foreach (['.fi-topbar > nav', '.fi-topbar-item-button', '.fi-topbar-item.fi-active', '.fi-dropdown-panel', '.dark .fi-topbar'] as $needle) {
            $this->assertStringContainsString($needle, $css);
        }
    }

    public function test_the_style_is_injected_into_the_head_and_keeps_the_sidebar_offset_in_step(): void
    {
        $html = $this->get(Filament::getPanel('admin')->getLoginUrl())->assertOk()->getContent();

        $this->assertStringContainsString('--tb-active-bg', $html);
        // Bilah atas 56px: menu samping menempel di 4.5rem, dan gaya topbar harus dimuat SETELAH gaya menu samping agar menang.
        $this->assertGreaterThan(strpos($html, '--sb-active-bg'), strpos($html, '--tb-active-bg'));
    }
}
