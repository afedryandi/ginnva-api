<?php

namespace Tests\Feature;

use App\Filament\Pages\Panduan;
use App\Filament\Resources\GuideNoteResource;
use App\Filament\Resources\GuideNoteResource\Pages\CreateGuideNote;
use App\Filament\Resources\GuideNoteResource\Pages\EditGuideNote;
use App\Models\GuideNote;
use App\Models\User;
use App\Support\GuideCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Panduan Fase 4: catatan perusahaan yang diubah pemilik di panel (hanya akses penuh), tampil di panel dan API aplikasi staf, aman dari
 * HTML, tercatat di Histori Aktivitas; serta versi dan tanggal tinjau di tiap panduan dengan penanda bila lewat masa tinjau.
 */
class GuideNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'kasir'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(string $role, ?array $menuAccess = null): User
    {
        return tap(User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'menu_access' => $menuAccess, 'is_active' => true]), fn (User $u) => $u->assignRole($role));
    }

    public function test_only_full_access_can_manage_notes(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(GuideNoteResource::canViewAny());
        $this->assertTrue(GuideNoteResource::canCreate());

        $this->actingAs($this->user('kasir', ['GuideNoteResource']), 'web');
        $this->assertFalse(GuideNoteResource::canViewAny(), 'Mencentang menu tidak boleh membuka Catatan Panduan untuk staff biasa.');
        $this->assertFalse(GuideNoteResource::canCreate());
        $this->assertFalse(GuideNoteResource::canEdit(new GuideNote()));
        $this->assertFalse(GuideNoteResource::canDelete(new GuideNote()));
    }

    public function test_a_note_is_created_for_a_real_guide_once_and_records_who_wrote_it(): void
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');

        Livewire::test(CreateGuideNote::class)
            ->fillForm(['guide_id' => 'absensi', 'body' => 'Toleransi telat toko kami **10 menit**.'])
            ->call('create')
            ->assertHasNoFormErrors();

        $note = GuideNote::where('guide_id', 'absensi')->firstOrFail();
        $this->assertSame($admin->id, $note->updated_by);

        Livewire::test(CreateGuideNote::class)
            ->fillForm(['guide_id' => 'absensi', 'body' => 'Catatan kedua'])
            ->call('create')
            ->assertHasFormErrors(['guide_id']);

        Livewire::test(CreateGuideNote::class)
            ->fillForm(['guide_id' => 'panduan-yang-tidak-ada', 'body' => 'Tes'])
            ->call('create')
            ->assertHasFormErrors(['guide_id']);

        $this->assertSame(1, GuideNote::count());
    }

    public function test_changes_to_a_note_are_logged_in_the_activity_history(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $note = GuideNote::create(['guide_id' => 'absensi', 'body' => 'Versi awal']);

        Livewire::test(EditGuideNote::class, ['record' => $note->getKey()])
            ->fillForm(['body' => 'Versi baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertGreaterThanOrEqual(2, Activity::where('log_name', 'guide_note')->count(), 'Pembuatan dan perubahan catatan harus tercatat.');
    }

    public function test_the_note_reaches_staff_in_the_api_and_the_panel_and_is_escaped(): void
    {
        GuideNote::create(['guide_id' => 'absensi', 'body' => "Jam toleransi **10 menit**\n<script>alert(1)</script>"]);

        $kasir = $this->user('kasir', ['AttendanceResource']);
        $guide = collect($this->actingAs($kasir, 'api')->getJson('/api/staff/guides')->assertOk()->json('guides'))->firstWhere('id', 'absensi');
        $this->assertStringContainsString('Jam toleransi', $guide['note']);

        $this->actingAs($kasir, 'web');
        $html = Livewire::test(Panduan::class)->html();
        $this->assertStringContainsString('data-guide-note', $html);
        $this->assertStringContainsString('<strong>10 menit</strong>', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'Catatan tidak boleh menyisipkan HTML mentah.');
    }

    public function test_guides_without_a_note_return_null_and_every_guide_has_a_version_and_review_date(): void
    {
        $guides = GuideCatalog::forUser($this->user('super_admin'));

        $this->assertNotEmpty($guides);
        foreach ($guides as $guide) {
            $this->assertNotEmpty($guide['version'], "Panduan {$guide['id']} tanpa versi.");
            $this->assertNotFalse(strtotime($guide['reviewed_at']), "Panduan {$guide['id']} tanpa tanggal tinjau.");
            $this->assertNull($guide['note']);
        }
    }

    public function test_a_guide_past_its_review_interval_is_flagged(): void
    {
        $guide = ['reviewed_at' => '2026-10-22'];

        $this->assertFalse(GuideCatalog::isOverdue($guide, Carbon::parse('2026-10-22')->addDays(GuideCatalog::REVIEW_INTERVAL_DAYS)));
        $this->assertTrue(GuideCatalog::isOverdue($guide, Carbon::parse('2026-10-22')->addDays(GuideCatalog::REVIEW_INTERVAL_DAYS + 1)));
    }
}
