<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

/** Gaya kepala halaman (breadcrumb, judul, tombol aksi): berkas gaya ada, menyasar kelas fi-header Filament, dan terpasang di head panel admin. */
class HeaderStylesTest extends TestCase
{
    public function test_the_header_style_targets_filament_header_classes(): void
    {
        $css = view('filament.partials.header-styles')->render();

        foreach (['.fi-header-heading', '.fi-breadcrumbs-item-label', '.fi-header .fi-btn', '.fi-page > section', '.dark '] as $needle) {
            $this->assertStringContainsString($needle, $css);
        }
    }

    public function test_the_style_is_injected_into_the_head_of_the_admin_panel(): void
    {
        $this->get(Filament::getPanel('admin')->getLoginUrl())
            ->assertOk()
            ->assertSee('.fi-header-subheading', false);
    }
}
