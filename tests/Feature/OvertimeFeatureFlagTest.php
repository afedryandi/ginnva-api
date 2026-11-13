<?php

namespace Tests\Feature;

use App\Exports\AttendanceSummaryExport;
use App\Filament\Resources\OvertimeRequestResource;
use App\Models\Store;
use App\Models\User;
use App\Services\ApprovalCenterService;
use App\Support\GuideCatalog;
use App\Support\NotificationCatalog;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Keputusan pemilik 2026-11-13: perusahaan belum memberlakukan lembur. Saklar FEATURE_OVERTIME_REQUESTS (config/features.php) menyembunyikan menu
 * Pengajuan Lembur, endpoint /overtime-requests, jenis Lembur di Pusat Persetujuan, kolom lembur di Export Rekap Absensi, tile di aplikasi, serta
 * teks panduan dan daftar notifikasinya. Tes lain berjalan dengan saklar menyala (phpunit.xml). Kode dan data tetap ada.
 */
class OvertimeFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->admin = tap(User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x', 'is_active' => true, 'store_id' => $store->id]), fn (User $u) => $u->assignRole('super_admin'));
    }

    public function test_the_menu_is_closed_even_to_full_access_when_the_switch_is_off(): void
    {
        config(['features.overtime_requests' => false]);
        $this->actingAs($this->admin, 'web');

        $this->assertFalse(OvertimeRequestResource::canViewAny());
    }

    public function test_the_menu_opens_for_full_access_when_the_switch_is_on(): void
    {
        config(['features.overtime_requests' => true]);
        $this->actingAs($this->admin, 'web');

        $this->assertTrue(OvertimeRequestResource::canViewAny());
    }

    public function test_the_api_endpoints_answer_404_and_the_app_flag_is_false_when_the_switch_is_off(): void
    {
        config(['features.overtime_requests' => false]);

        $this->actingAs($this->admin, 'api')->getJson('/api/staff/overtime-requests')->assertStatus(404);
        $this->actingAs($this->admin, 'api')->postJson('/api/staff/overtime-requests', [])->assertStatus(404);

        config(['features.overtime_requests' => true]);
        $this->assertNotSame(404, $this->actingAs($this->admin, 'api')->getJson('/api/staff/overtime-requests')->getStatusCode());
    }

    public function test_the_approval_center_has_no_overtime_kind_when_the_switch_is_off(): void
    {
        config(['features.overtime_requests' => false]);
        $this->assertArrayNotHasKey('overtime', app(ApprovalCenterService::class)->sources());

        config(['features.overtime_requests' => true]);
        $this->assertArrayHasKey('overtime', app(ApprovalCenterService::class)->sources());
    }

    public function test_the_export_and_notification_list_drop_the_overtime_parts_when_the_switch_is_off(): void
    {
        $export = new AttendanceSummaryExport([], Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

        config(['features.overtime_requests' => false]);
        $this->assertNotContains('Jam Lembur Disetujui', $export->headings());
        $this->assertNotContains('Services/OvertimeRequestService.php', array_column(NotificationCatalog::all(), 'source'));

        config(['features.overtime_requests' => true]);
        $this->assertContains('Jam Lembur Disetujui', $export->headings());
        $this->assertContains('Services/OvertimeRequestService.php', array_column(NotificationCatalog::all(), 'source'));
    }

    public function test_the_guides_do_not_mention_overtime_when_the_switch_is_off(): void
    {
        config(['features.overtime_requests' => false]);
        $text = json_encode(GuideCatalog::all(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('OvertimeRequestResource', $text);
        $this->assertStringNotContainsString('Lembur wajib diajukan', $text);
        $this->assertStringNotContainsString('Perlu lembur?', $text);
    }
}
