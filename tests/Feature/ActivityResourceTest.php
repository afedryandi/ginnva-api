<?php

namespace Tests\Feature;

use App\Filament\Resources\ActivityResource;
use App\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Filament\Resources\ActivityResource\Pages\ViewActivity;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use ReflectionClass;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Histori Aktivitas (audit trail): khusus full-access dan hanya baca, daftar dengan label modul / aksi / objek, pelaku atau
 * "Sistem", pencarian (pelaku dan deskripsi), filter modul / pelaku / tanggal, halaman detail (nilai lama-baru, termasuk nilai
 * bersarang, alasan) dan kelengkapan peta label modul terhadap semua nama log yang dipakai kode.
 */
class ActivityResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('direksi', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function user(string $role, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => null, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    /** Catat satu aktivitas tanpa memicu log otomatis model. */
    private function log(string $logName, string $description, string $event = 'updated', ?User $causer = null, array $properties = [], ?string $at = null): Activity
    {
        $activity = Activity::create([
            'log_name' => $logName, 'description' => $description, 'event' => $event,
            'subject_type' => Store::class, 'subject_id' => $this->store->id,
            'causer_type' => $causer ? User::class : null, 'causer_id' => $causer?->id,
            'properties' => $properties,
        ]);

        if ($at) {
            DB::table('activity_log')->where('id', $activity->id)->update(['created_at' => $at, 'updated_at' => $at]);
        }

        return $activity->fresh();
    }

    // ------------------------------------------------------------- akses

    public function test_only_full_access_can_see_it_and_it_is_read_only(): void
    {
        $record = new Activity();

        foreach (['super_admin', 'direksi'] as $role) {
            $this->as($this->user($role));
            $this->assertTrue(ActivityResource::canViewAny(), $role);
            $this->assertTrue(ActivityResource::canView($record), $role);
            $this->assertFalse(ActivityResource::canCreate());
            $this->assertFalse(ActivityResource::canEdit($record));
            $this->assertFalse(ActivityResource::canDelete($record));
        }

        $this->as($this->user('kasir'));
        $this->assertFalse(ActivityResource::canViewAny());
        $this->assertFalse(ActivityResource::canView($record));

        $this->as($this->user('kasir', ['menu_access' => ['ActivityResource']]));
        $this->assertFalse(ActivityResource::canViewAny(), 'Hak menu saja tidak cukup: data sensitif khusus full-access.');
    }

    public function test_staff_cannot_open_the_page(): void
    {
        $this->as($this->user('kasir'));

        $this->get(ActivityResource::getUrl('index'))->assertForbidden();
    }

    // ------------------------------------------------------------- daftar

    public function test_list_shows_module_action_object_and_the_actor(): void
    {
        $admin = $this->user('super_admin');
        $booking = $this->log('booking', 'Booking diubah', 'updated', $admin);
        $system = $this->log('voucher', 'Kampanye dibuat', 'created');
        $unknown = $this->log('modul_baru_belum_dipetakan', 'Sesuatu', 'deleted', $admin);
        Activity::whereNotIn('id', [$booking->id, $system->id, $unknown->id])->delete();

        $this->as($admin);
        $query = ActivityResource::getEloquentQuery();
        $booking = $query->clone()->findOrFail($booking->id);
        $system = $query->clone()->findOrFail($system->id);
        $unknown = $query->clone()->findOrFail($unknown->id);

        Livewire::test(ListActivities::class)
            ->assertCanSeeTableRecords([$booking, $system, $unknown])
            ->assertTableColumnFormattedStateSet('log_name', 'Booking', record: $booking)
            ->assertTableColumnFormattedStateSet('log_name', 'Kampanye Voucher', record: $system)
            ->assertTableColumnFormattedStateSet('log_name', 'modul_baru_belum_dipetakan', record: $unknown)
            ->assertTableColumnFormattedStateSet('event', 'Diubah', record: $booking)
            ->assertTableColumnFormattedStateSet('event', 'Dibuat', record: $system)
            ->assertTableColumnFormattedStateSet('event', 'Dihapus', record: $unknown)
            ->assertTableColumnFormattedStateSet('subject_type', 'Store #' . $this->store->id, record: $booking)
            ->assertTableColumnStateSet('causer.name', $admin->name, record: $booking)
            ->assertTableColumnStateSet('causer.name', null, record: $system);
    }

    public function test_search_by_actor_and_by_description(): void
    {
        $alice = $this->user('super_admin', ['name' => 'Alice Pengubah']);
        $bob = $this->user('direksi', ['name' => 'Bob Direksi']);
        $a = $this->log('store', 'Radius absen toko diubah', 'updated', $alice);
        $b = $this->log('voucher', 'Kampanye Ramadan dibuat', 'created', $bob);

        $this->as($alice);
        Livewire::test(ListActivities::class)->searchTable('Bob')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListActivities::class)->searchTable('Radius absen')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
    }

    public function test_filters_by_module_actor_and_date(): void
    {
        $alice = $this->user('super_admin', ['name' => 'Alice Pengubah']);
        $bob = $this->user('direksi', ['name' => 'Bob Direksi']);
        $a = $this->log('store', 'Toko diubah', 'updated', $alice, [], '2026-10-01 09:00:00');
        $b = $this->log('voucher', 'Voucher dibuat', 'created', $bob, [], '2026-10-05 09:00:00');
        $c = $this->log('store', 'Toko diubah lagi', 'updated', $bob, [], '2026-10-07 09:00:00');

        $this->as($alice);
        Livewire::test(ListActivities::class)->filterTable('log_name', 'voucher')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c]);
        Livewire::test(ListActivities::class)->filterTable('causer_id', $bob->id)->assertCanSeeTableRecords([$b, $c])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListActivities::class)
            ->filterTable('created_at', ['from' => '2026-10-02', 'until' => '2026-10-06'])
            ->assertCanSeeTableRecords([$b])
            ->assertCanNotSeeTableRecords([$a, $c]);
    }

    public function test_the_actor_filter_does_not_match_a_non_user_causer_with_the_same_id(): void
    {
        $alice = $this->user('super_admin', ['name' => 'Alice Pengubah']);
        $mine = $this->log('store', 'Oleh user', 'updated', $alice);
        $other = $this->log('store', 'Oleh bukan user', 'updated');
        DB::table('activity_log')->where('id', $other->id)->update(['causer_type' => 'App\\Models\\Customer', 'causer_id' => $alice->id]);

        $this->as($alice);
        Livewire::test(ListActivities::class)
            ->filterTable('causer_id', $alice->id)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other->fresh()]);
    }

    // ------------------------------------------------------------- detail

    public function test_the_detail_page_shows_old_and_new_values_and_the_reason(): void
    {
        $admin = $this->user('super_admin');
        $activity = $this->log('store', 'Radius absen toko diubah', 'updated', $admin, [
            'old' => ['attendance_radius_meters' => 150, 'is_active' => true],
            'attributes' => ['attendance_radius_meters' => 250, 'is_active' => false],
            'reason' => 'Toko pindah gedung',
        ]);

        $this->as($admin);
        Livewire::test(ViewActivity::class, ['record' => $activity->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Radius absen toko diubah')
            ->assertSee('Toko Baru')
            ->assertSee('Toko pindah gedung')
            ->assertSee('attendance_radius_meters')
            ->assertSee('250')
            ->assertSee('Toko/Dealer');
    }

    public function test_nested_values_do_not_break_the_detail_page(): void
    {
        $admin = $this->user('super_admin');
        $activity = $this->log('store', 'Jam operasional diubah', 'updated', $admin, [
            'old' => ['opening_hours' => [['days' => ['mon'], 'open' => '08:00']]],
            'attributes' => ['opening_hours' => [['days' => ['mon', 'tue'], 'open' => '09:00']], 'phone' => null],
        ]);

        $this->as($admin);
        Livewire::test(ViewActivity::class, ['record' => $activity->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('opening_hours')
            ->assertSee('09:00');
    }

    public function test_a_system_activity_is_labelled_as_automatic(): void
    {
        $activity = $this->log('voucher', 'Kampanye dibuat', 'created');

        $this->as($this->user('super_admin'));
        Livewire::test(ViewActivity::class, ['record' => $activity->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Sistem (otomatis)');
    }

    public function test_values_are_turned_into_plain_text(): void
    {
        $this->assertSame(
            ['a' => '5', 'b' => '—', 'c' => 'ya', 'd' => 'tidak', 'e' => '[1,2]', 'f' => '{"x":"é"}'],
            ActivityResource::stringifyValues(['a' => 5, 'b' => null, 'c' => true, 'd' => false, 'e' => [1, 2], 'f' => ['x' => 'é']])
        );
        $this->assertSame([], ActivityResource::stringifyValues(null));
    }

    // ------------------------------------------------------------- kelengkapan peta label

    public function test_every_log_name_used_by_the_code_has_a_label(): void
    {
        $labels = (new ReflectionClass(ActivityResource::class))->getConstant('LOG_NAME_LABELS');
        $used = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = file_get_contents($file->getPathname());
            preg_match_all("/useLogName\('([^']+)'\)/", $code, $a);
            preg_match_all("/activity\('([^']+)'\)/", $code, $b);
            $used = array_merge($used, $a[1], $b[1]);
        }

        $missing = array_values(array_diff(array_unique($used), array_keys($labels)));

        $this->assertNotEmpty($used);
        $this->assertSame([], $missing, 'Nama log tanpa label di ActivityResource::LOG_NAME_LABELS: ' . implode(', ', $missing));
    }
}
