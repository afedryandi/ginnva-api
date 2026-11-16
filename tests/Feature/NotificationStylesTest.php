<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

/** Gaya panel Notifikasi (slide-over lonceng): berkas gaya ada, hanya menyasar modal notifikasi, dan terpasang di head panel admin. */
class NotificationStylesTest extends TestCase
{
    public function test_the_style_targets_only_the_notification_modal(): void
    {
        $css = view('filament.partials.notification-styles')->render();

        $this->assertStringContainsString(':has(.fi-no-notification)', $css);
        $this->assertStringContainsString('.fi-no-notification.fi-inline', $css);
        $this->assertStringContainsString('.dark .fi-modal-window:has(.fi-no-notification)', $css);
    }

    public function test_the_style_is_injected_into_the_head_of_the_admin_panel(): void
    {
        $this->get(Filament::getPanel('admin')->getLoginUrl())
            ->assertOk()
            ->assertSee('--nt-soft-hover', false);
    }
}
