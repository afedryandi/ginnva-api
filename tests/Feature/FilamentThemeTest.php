<?php

namespace Tests\Feature;

use App\Providers\Filament\AdminPanelProvider;
use Tests\TestCase;

/** Tema Vite panel admin dipasang hanya bila hasil build-nya ada, supaya build yang hilang tidak membuat semua halaman error. */
class FilamentThemeTest extends TestCase
{
    private function manifest(string $json): string
    {
        $path = tempnam(sys_get_temp_dir(), 'manifest');
        file_put_contents($path, $json);

        return $path;
    }

    public function test_the_theme_is_off_without_a_manifest_or_without_the_entry(): void
    {
        $this->assertFalse(AdminPanelProvider::themeIsBuilt(sys_get_temp_dir() . '/tidak-ada-' . uniqid() . '.json'));
        $this->assertFalse(AdminPanelProvider::themeIsBuilt($this->manifest('{"resources/css/app.css": {"file": "assets/app.css"}}')));
        $this->assertFalse(AdminPanelProvider::themeIsBuilt($this->manifest('bukan json')));
    }

    public function test_the_theme_is_on_when_the_manifest_lists_the_theme_entry(): void
    {
        $manifest = $this->manifest('{"' . AdminPanelProvider::VITE_THEME . '": {"file": "assets/theme.css"}}');

        $this->assertTrue(AdminPanelProvider::themeIsBuilt($manifest));
    }

    public function test_the_theme_sources_exist_and_use_the_filament_preset(): void
    {
        $this->assertFileExists(base_path('resources/css/filament/admin/theme.css'));
        $this->assertStringContainsString('filament/support/tailwind.config.preset', file_get_contents(base_path('resources/css/filament/admin/tailwind.config.js')));
        $this->assertStringContainsString('resources/css/filament/admin/theme.css', file_get_contents(base_path('vite.config.js')));
    }

    public function test_the_login_page_still_renders_without_a_built_theme(): void
    {
        $this->get(\Filament\Facades\Filament::getPanel('admin')->getLoginUrl())->assertOk();
    }
}
