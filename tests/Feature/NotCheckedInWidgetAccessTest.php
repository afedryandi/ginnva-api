<?php

namespace Tests\Feature;

use App\Filament\Resources\AttendanceResource;
use App\Filament\Widgets\NotCheckedInTodayWidget;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Widget "Belum Absen Hari Ini" memeriksa canView() di setiap permintaan Livewire-nya. Sebelumnya pemeriksaan itu hanya melihat nama rute
 * halaman, yang selalu salah untuk rute livewire/update, sehingga polling dan interaksi widget ditolak 403 (muncul "tiba-tiba" di Absensi).
 */
class NotCheckedInWidgetAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
    }

    private function livewireRequestFrom(string $path): void
    {
        $request = Request::create('/livewire/update', 'POST', ['components' => [['snapshot' => json_encode(['memo' => ['path' => $path]])]]]);
        $request->headers->set('X-Livewire', 'true');
        app()->instance('request', $request);
    }

    public function test_a_livewire_request_from_the_attendance_page_is_allowed(): void
    {
        $this->livewireRequestFrom(ltrim((string) parse_url(AttendanceResource::getUrl('index'), PHP_URL_PATH), '/'));

        $this->assertTrue(NotCheckedInTodayWidget::canView());
    }

    public function test_a_livewire_request_from_another_page_is_still_refused(): void
    {
        $this->livewireRequestFrom('admin');

        $this->assertFalse(NotCheckedInTodayWidget::canView());
    }

    public function test_an_ordinary_request_to_another_page_is_refused(): void
    {
        app()->instance('request', Request::create('/admin', 'GET'));

        $this->assertFalse(NotCheckedInTodayWidget::canView());
    }
}
