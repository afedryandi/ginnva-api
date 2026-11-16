<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

/** Gaya menu samping kiri (sub-navigasi klaster): berkas gaya ada dan terpasang global lewat render hook panel admin. */
class SidebarStylesTest extends TestCase
{
    public function test_the_sidebar_style_partial_targets_filament_sidebar_classes(): void
    {
        $css = view('filament.partials.sidebar-styles')->render();

        foreach (['.fi-page-sub-navigation-sidebar', '.fi-sidebar-group-label', '.fi-sidebar-item-button', '.fi-active', '.dark '] as $needle) {
            $this->assertStringContainsString($needle, $css);
        }
    }

    public function test_the_style_is_injected_into_the_head_of_the_admin_panel(): void
    {
        // Hook panel baru terpasang saat panel melayani permintaan, jadi diuji lewat halaman login panel yang sungguhan.
        $this->get(Filament::getPanel('admin')->getLoginUrl())
            ->assertOk()
            ->assertSee('--sb-active-bg', false);
    }
}
