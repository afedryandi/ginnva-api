<?php

namespace Tests\Feature;

use App\Filament\Pages\DashboardHome;
use App\Filament\Widgets\BackupStatusWidget;
use App\Models\User;
use App\Support\BackupStatus;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Kartu "Cadangan Database": status dibaca dari berkas ginnva_backup_*.sql.gz, hanya terlihat oleh akses penuh. */
class BackupStatusTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/ginnva-backup-test-' . uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function backup(string $name, Carbon $at, string $content = 'x'): void
    {
        $path = $this->dir . '/' . $name;
        File::put($path, $content);
        touch($path, $at->getTimestamp());
    }

    private function user(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::create(['name' => ucfirst($role), 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_the_newest_backup_is_reported(): void
    {
        $now = Carbon::parse('2026-10-24 10:00:00');
        $this->backup('ginnva_backup_20261023_030000.sql.gz', $now->copy()->subHours(31));
        $this->backup('ginnva_backup_20261024_030000.sql.gz', $now->copy()->subHours(7), str_repeat('a', 2048));
        $this->backup('catatan.txt', $now, 'bukan cadangan');

        $status = BackupStatus::latest($this->dir, $now);

        $this->assertTrue($status['found']);
        $this->assertSame(7, $status['age_hours']);
        $this->assertSame(2, $status['count'], 'Hanya berkas ginnva_backup_*.sql.gz yang dihitung.');
        $this->assertFalse($status['stale']);
        $this->assertSame('ok', $status['state']);
    }

    public function test_an_old_backup_is_flagged_as_stale(): void
    {
        $now = Carbon::parse('2026-10-24 10:00:00');
        $this->backup('ginnva_backup_20261022_030000.sql.gz', $now->copy()->subHours(55));

        $status = BackupStatus::latest($this->dir, $now);

        $this->assertTrue($status['stale']);
        $this->assertSame('stale', $status['state']);
    }

    public function test_no_backup_files_is_reported_as_missing(): void
    {
        $status = BackupStatus::latest($this->dir);
        $this->assertFalse($status['found']);
        $this->assertSame('missing', $status['state']);

        $this->assertSame('missing', BackupStatus::latest($this->dir . '/tidak-ada')['state']);
    }

    public function test_offsite_flag_follows_the_rclone_remote_setting(): void
    {
        config(['services.backup.rclone_remote' => null]);
        $this->assertFalse(BackupStatus::latest($this->dir)['offsite_configured']);

        config(['services.backup.rclone_remote' => 'gdrive:ginnva-backup']);
        $this->assertTrue(BackupStatus::latest($this->dir)['offsite_configured']);
    }

    public function test_only_full_access_users_can_see_the_card(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(BackupStatusWidget::canView());

        $this->actingAs($this->user('kasir'), 'web');
        $this->assertFalse(BackupStatusWidget::canView());
    }

    public function test_the_dashboard_shows_the_card_to_full_access(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user('super_admin'), 'web');

        Livewire::test(DashboardHome::class)->assertSee('Cadangan Database');
    }
}
