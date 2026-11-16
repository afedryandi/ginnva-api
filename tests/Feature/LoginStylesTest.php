<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

/** Tampilan halaman Login panel admin: gaya terpasang di head, judul dan catatan kaki tampil, isian tetap "email" (email atau No. HP). */
class LoginStylesTest extends TestCase
{
    public function test_the_login_style_partial_targets_the_simple_layout_only(): void
    {
        $css = view('filament.partials.login-styles')->render();

        foreach (['.fi-simple-layout', '.fi-simple-main', '.fi-simple-header-heading', '.fi-input-wrp', '.fi-form-actions', '.dark '] as $needle) {
            $this->assertStringContainsString($needle, $css);
        }
    }

    public function test_the_login_page_shows_the_new_styles_heading_and_footer(): void
    {
        $this->get(Filament::getPanel('admin')->getLoginUrl())
            ->assertOk()
            ->assertSee('--lg-canvas', false)
            ->assertSee('Masuk ke panel')
            ->assertSee('Gunakan email atau nomor HP yang terdaftar.')
            ->assertSee('Panel Admin')
            ->assertSee('Email atau No. HP')
            ->assertSee('data.email', false);
    }
}
