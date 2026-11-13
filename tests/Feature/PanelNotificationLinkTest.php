<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StaffNotifier;
use App\Support\PanelNotificationLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Notifikasi di lonceng panel admin punya tombol "Buka" yang membawa langsung ke halaman terkait (2026-11-13), bukan sekadar teks.
 */
class PanelNotificationLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_titles_point_to_the_right_page(): void
    {
        $expect = [
            'Pengajuan Lembur Baru' => '/overtime-requests',
            'Pengajuan Dinas Luar Disetujui' => '/field-duty-requests',
            'Permintaan koreksi absensi baru' => '/attendance-correction-requests',
            'Pengajuan Izin Disetujui' => '/leave-requests',
            'Surat Peringatan Diterbitkan' => '/warning-letters',
            'Permohonan Pembelian Baru' => '/purchase-requests',
            'Booking Baru' => '/bookings',
        ];

        foreach ($expect as $title => $path) {
            $url = PanelNotificationLink::url($title);

            $this->assertNotNull($url, $title);
            $this->assertStringContainsString('/admin', $url, $title);
            $this->assertStringEndsWith($path, $url, $title);
        }
    }

    public function test_an_unknown_title_gets_no_misleading_button(): void
    {
        $this->assertNull(PanelNotificationLink::url('Pengumuman umum yang tidak dikenal'));
        $this->assertSame([], PanelNotificationLink::actions('Pengumuman umum yang tidak dikenal'));
    }

    public function test_a_page_or_resource_class_can_be_given_directly(): void
    {
        $this->assertStringEndsWith('/overtime-requests', (string) PanelNotificationLink::url(\App\Filament\Resources\OvertimeRequestResource::class));
    }

    public function test_the_demo_command_seeds_and_purges_without_duplicating(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web');
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x', 'join_date' => '2025-01-01', 'is_active' => true]);
        $admin->assignRole('super_admin');

        $this->artisan('notifications:seed-demo')->assertSuccessful();
        $this->artisan('notifications:seed-demo')->assertSuccessful();

        $rows = $admin->notifications()->get();
        $this->assertCount(12, $rows, 'Dijalankan dua kali tidak menggandakan data.');
        $this->assertTrue($rows->contains(fn ($n) => $n->read_at === null));
        $this->assertTrue($rows->contains(fn ($n) => ! empty($n->data['actions'])), 'Sebagian contoh punya tombol Buka.');
        $this->assertTrue($rows->contains(fn ($n) => empty($n->data['actions'])), 'Satu contoh sengaja tanpa tombol.');

        $this->artisan('notifications:seed-demo --purge')->assertSuccessful();
        $this->assertCount(0, $admin->notifications()->get());
    }

    public function test_staff_inbox_rows_carry_the_open_button(): void
    {
        Http::fake();
        $user = User::create(['name' => 'Manajer', 'email' => 'm@test.local', 'password' => 'x', 'join_date' => '2025-01-01', 'is_active' => true]);

        app(StaffNotifier::class)->toUsers([$user->id], 'Pengajuan Lembur Baru', 'Andi mengajukan lembur.', color: 'warning');

        $action = $user->notifications()->firstOrFail()->data['actions'][0] ?? null;

        $this->assertNotNull($action);
        $this->assertSame('Buka', $action['label']);
        $this->assertStringEndsWith('/overtime-requests', $action['url']);
        $this->assertTrue($action['shouldMarkAsRead']);
    }
}
