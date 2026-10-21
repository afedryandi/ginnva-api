<?php

namespace Tests\Feature;

use App\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Histori Aktivitas Fase 4 (2026-10-21): indeks tanggal, laporan ukuran baca-saja, dan ringkasan di halaman daftar.
 */
class ActivityLogReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Cache::forget('activity_log_summary');
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function log(string $logName, string $at): void
    {
        $activity = Activity::create(['log_name' => $logName, 'description' => 'x', 'event' => 'updated']);
        DB::table('activity_log')->where('id', $activity->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    public function test_the_date_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasIndex('activity_log', 'activity_log_created_at_index'));
        $this->assertTrue(Schema::hasIndex('activity_log', 'activity_log_log_name_created_at_index'));
    }

    public function test_the_report_on_an_empty_log_says_so(): void
    {
        Activity::query()->delete();

        $this->artisan('activity:size-report')
            ->expectsOutputToContain('masih kosong')
            ->assertSuccessful();
    }

    public function test_the_report_summarises_size_modules_and_months_without_changing_anything(): void
    {
        Activity::query()->delete();
        $this->log('booking', now()->subDays(40)->toDateTimeString());
        $this->log('booking', now()->subDays(5)->toDateTimeString());
        $this->log('store', now()->subDays(2)->toDateTimeString());

        $this->artisan('activity:size-report')
            ->expectsOutputToContain('Jumlah catatan : 3')
            ->expectsOutputToContain('Modul terbanyak')
            ->expectsOutputToContain('booking')
            ->expectsOutputToContain('66,7%')
            ->expectsOutputToContain('Per bulan')
            ->assertSuccessful();

        $this->assertSame(3, Activity::count(), 'Perintah hanya membaca.');
    }

    public function test_the_list_page_shows_how_big_the_log_has_grown(): void
    {
        // Membuat user ikut mencatat aktivitas, jadi log dikosongkan SETELAH user dibuat.
        $admin = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');
        Activity::query()->delete();
        $this->log('booking', '2026-10-01 03:00:00');
        $this->log('store', '2026-10-05 03:00:00');
        Cache::forget('activity_log_summary');

        Livewire::test(ListActivities::class)
            ->assertSuccessful()
            ->assertSee('2 catatan sejak 01 Oct 2026')
            ->assertSee('disimpan tanpa batas waktu');
    }
}
