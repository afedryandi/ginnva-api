<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Technician;
use App\Models\TechnicianTraining;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pengingat mingguan sertifikat installer (audit Kompetensi Installer, 2026-10-31). Waktu dibekukan: Rabu 7 Okt 2026.
 */
class NotifyExpiringTrainingsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['super_admin', 'store_manager', 'installer', 'kasir'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Carbon::setTestNow('2026-10-07 08:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        $user = User::create($extra + ['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function training(string $name, ?Store $store, string $title, string $until, string $status = 'active'): TechnicianTraining
    {
        $technician = Technician::create(['store_id' => ($store ?? $this->store)->id, 'name' => $name, 'level' => 'intermediate', 'status' => $status]);

        return TechnicianTraining::create(['technician_id' => $technician->id, 'title' => $title, 'held_on' => '2025-01-01', 'valid_until' => $until]);
    }

    /** @return array<int, array{0: array, 1: string, 2: string}> */
    private function runAndCapturePushes(): array
    {
        $pushes = [];
        $this->mock(PushNotificationService::class, function ($mock) use (&$pushes) {
            $mock->shouldReceive('sendToUsers')->andReturnUsing(function (array $ids, string $title, string $body) use (&$pushes) {
                $pushes[] = [$ids, $title, $body];
            });
        });

        $this->artisan('training:notify-expiring')->assertSuccessful();

        return $pushes;
    }

    public function test_full_access_gets_every_store_and_a_store_manager_only_their_own_stores_installers(): void
    {
        $admin = $this->user('super_admin');
        $managerA = $this->user('store_manager', $this->store);
        $managerB = $this->user('store_manager', $this->otherStore);
        $this->training('Andi Toko A', $this->store, 'Sertifikasi PPF', '2026-10-20');        // 13 hari lagi
        $this->training('Budi Toko B', $this->otherStore, 'Sertifikasi WF', '2026-09-25');    // baru kedaluwarsa 12 hari lalu

        $pushes = $this->runAndCapturePushes();
        $byUser = collect($pushes)->mapWithKeys(fn ($p) => [$p[0][0] => $p]);

        $this->assertCount(3, $pushes);
        $this->assertStringContainsString('Andi Toko A', $byUser[$admin->id][2]);
        $this->assertStringContainsString('Budi Toko B', $byUser[$admin->id][2]);
        $this->assertStringContainsString('2 sertifikat', $byUser[$admin->id][2]);
        $this->assertStringContainsString('Andi Toko A', $byUser[$managerA->id][2]);
        $this->assertStringNotContainsString('Budi Toko B', $byUser[$managerA->id][2]);
        $this->assertStringContainsString('kedaluwarsa 25 Sep 2026', $byUser[$managerB->id][2]);
        $this->assertSame('Sertifikat Installer Segera Kedaluwarsa', $byUser[$admin->id][1]);
    }

    public function test_renewed_old_inactive_and_far_away_certificates_are_not_reminded(): void
    {
        $admin = $this->user('super_admin');
        $this->training('Sudah Diperbarui', $this->store, 'Sertifikasi PPF', '2026-09-20');
        $renewed = Technician::where('name', 'Sudah Diperbarui')->first();
        TechnicianTraining::create(['technician_id' => $renewed->id, 'title' => 'Sertifikasi PPF', 'held_on' => '2026-09-21', 'valid_until' => '2027-09-21']);
        $this->training('Lama Sekali', $this->store, 'Sertifikasi Lama', '2026-05-01');       // kedaluwarsa > 30 hari
        $this->training('Masih Jauh', $this->store, 'Sertifikasi Jauh', '2027-03-01');
        $this->training('Sudah Keluar', $this->store, 'Sertifikasi Keluar', '2026-10-15', 'inactive');

        $this->assertSame([], $this->runAndCapturePushes(), 'Tidak ada yang perlu diingatkan, jadi tidak ada pesan.');
        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_default_staff_and_installers_receive_nothing_and_the_inbox_row_is_created_for_recipients(): void
    {
        $admin = $this->user('super_admin');
        $kasir = $this->user('kasir');
        $installer = $this->user('installer');
        $this->training('Andi', $this->store, 'Sertifikasi PPF', '2026-10-20');

        $pushes = $this->runAndCapturePushes();

        $this->assertSame([[$admin->id]], array_column($pushes, 0));
        $this->assertSame(1, $admin->notifications()->count(), 'Baris kotak masuk untuk penerima.');
        $this->assertSame(0, $kasir->notifications()->count());
        $this->assertSame(0, $installer->notifications()->count());
    }

    public function test_the_command_is_scheduled_weekly_on_monday_morning(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('training:notify-expiring')->assertSuccessful();

        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'training:notify-expiring'));

        $this->assertNotNull($event, 'Terdaftar di jadwal.');
        $this->assertSame('0 8 * * 1', $event->expression);
    }
}
