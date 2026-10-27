<?php

namespace Tests\Feature;

use App\Exports\ActivityLogExport;
use App\Filament\Resources\ActivityResource;
use App\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Filament\Resources\UserResource;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Histori Aktivitas Fase 5 (2026-10-21): akses lewat Akses Menu, tab Perubahan Berisiko dan Keamanan Akun, ekspor terfilter
 * yang ikut tercatat.
 */
class ActivityAccessExportTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private string $originalTimezone;

    /** Nama berkas ekspor memuat waktu; dibekukan agar bisa diperiksa persis (Excel::assertDownloaded butuh nama string). */
    private const EXPORT_NAME = 'histori-aktivitas-20261102-100000.xlsx';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->originalTimezone);

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTimezone = date_default_timezone_get();
        Carbon::setTestNow('2026-11-02 10:00:00');

        Http::fake();
        ActivityLogExport::$maxRows = 50000;
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function user(string $role, ?array $menuAccess = null): User
    {
        $user = User::create(['name' => ucfirst($role), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => $menuAccess, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function log(string $logName, string $event, string $description = 'x', array $extra = []): Activity
    {
        return Activity::create(array_merge([
            'log_name' => $logName, 'event' => $event, 'description' => $description,
            'subject_type' => Store::class, 'subject_id' => $this->store->id, 'properties' => [],
        ], $extra));
    }

    // ------------------------------------------------------------- akses menu

    public function test_the_menu_grant_appears_in_the_access_matrix(): void
    {
        $options = (new ReflectionMethod(UserResource::class, 'menuAccessOptions'))->invoke(null);

        $this->assertSame('Riwayat Aktivitas', $options['Sistem']['ActivityResource']);
    }

    public function test_a_delegated_staff_member_can_open_the_page(): void
    {
        $this->as($this->user('kasir', ['ActivityResource']));

        $this->get(ActivityResource::getUrl('index'))->assertSuccessful();
    }

    public function test_staff_whose_menu_list_lacks_it_cannot_open_the_page(): void
    {
        $this->as($this->user('kasir', ['BookingResource']));

        $this->get(ActivityResource::getUrl('index'))->assertForbidden();
    }

    public function test_unrestricted_staff_do_not_get_it_just_because_all_menus_are_open(): void
    {
        // menu_access kosong = semua menu terbuka (bawaan staf), tetapi Histori Aktivitas butuh pemberian eksplisit.
        $staff = $this->as($this->user('kasir', null));

        $this->assertTrue($staff->hasMenuAccess(ActivityResource::class), 'Syarat: menu_access kosong membuka semua menu.');
        $this->assertFalse(ActivityResource::canViewAny());
        $this->get(ActivityResource::getUrl('index'))->assertForbidden();
    }

    // ------------------------------------------------------------- tab

    public function test_the_risky_tab_shows_permission_price_closing_and_deletion_events_only(): void
    {
        $this->as($this->user('super_admin'));
        Activity::query()->delete();

        $userChange = $this->log('user', 'updated');
        $price = $this->log('film_product_price', 'updated');
        $deleted = $this->log('booking', 'deleted');
        $failedLogin = $this->log('auth', 'login_failed');
        $passwordReset = $this->log('auth', 'password_reset');
        $export = $this->log('report_export', 'updated');

        $ordinary = $this->log('booking', 'updated');
        $created = $this->log('customer', 'created');
        $normalLogin = $this->log('auth', 'login');

        $risky = [$userChange, $price, $deleted, $failedLogin, $passwordReset, $export];
        $calm = [$ordinary, $created, $normalLogin];

        Livewire::test(ListActivities::class)->assertCanSeeTableRecords(array_merge($risky, $calm));

        Livewire::test(ListActivities::class)
            ->set('activeTab', 'berisiko')
            ->assertCanSeeTableRecords($risky)
            ->assertCanNotSeeTableRecords($calm);
    }

    public function test_the_security_tab_shows_only_account_security_events(): void
    {
        $this->as($this->user('super_admin'));
        Activity::query()->delete();

        $login = $this->log('auth', 'login');
        $failed = $this->log('auth', 'login_failed');
        $other = $this->log('booking', 'updated');

        Livewire::test(ListActivities::class)
            ->set('activeTab', 'keamanan')
            ->assertCanSeeTableRecords([$login, $failed])
            ->assertCanNotSeeTableRecords([$other]);
    }

    // ------------------------------------------------------------- ekspor

    public function test_the_export_follows_filters_and_the_active_tab_and_is_logged(): void
    {
        $admin = $this->as($this->user('super_admin'));
        Activity::query()->delete();
        $store = $this->log('store', 'updated', 'Toko diubah');
        $booking = $this->log('booking', 'updated', 'Booking diubah');
        $deleted = $this->log('booking', 'deleted', 'Booking dihapus');
        Excel::fake();

        Livewire::test(ListActivities::class)
            ->filterTable('log_name', 'booking')
            ->callAction('exportExcel');

        Excel::assertDownloaded(
            self::EXPORT_NAME,
            fn (ActivityLogExport $export) => $export->query()->pluck('activity_log.id')->sort()->values()->all() === [$booking->id, $deleted->id]
        );

        $log = Activity::where('log_name', 'report_export')->where('causer_id', $admin->id)->latest('id')->firstOrFail();
        $this->assertSame('activity_log', $log->properties['report']);
        $this->assertSame('xlsx', $log->properties['format']);
        $this->assertSame(2, $log->properties['rows']);

        Excel::fake();
        Livewire::test(ListActivities::class)->set('activeTab', 'berisiko')->callAction('exportExcel');
        Excel::assertDownloaded(
            self::EXPORT_NAME,
            fn (ActivityLogExport $export) => $export->query()->pluck('activity_log.id')->contains($deleted->id)
                && ! $export->query()->pluck('activity_log.id')->contains($store->id)
        );
    }

    public function test_a_delegated_staff_member_can_export_and_the_export_names_them(): void
    {
        $delegated = $this->as($this->user('kasir', ['ActivityResource']));
        $this->log('store', 'updated');
        Excel::fake();

        Livewire::test(ListActivities::class)->callAction('exportExcel');

        Excel::assertDownloaded(self::EXPORT_NAME);
        $this->assertSame(1, Activity::where('log_name', 'report_export')->where('causer_id', $delegated->id)->count());
    }

    public function test_a_too_large_export_is_refused_with_a_message_and_not_logged(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $this->log('store', 'updated');
        $this->log('store', 'updated');
        ActivityLogExport::$maxRows = 1;
        Excel::fake();

        Livewire::test(ListActivities::class)
            ->callAction('exportExcel')
            ->assertNotified('Terlalu banyak baris untuk diekspor');

        // Ditolak sebelum mencatat dan sebelum mengunduh: tidak ada catatan ekspor.
        $this->assertSame(0, Activity::where('log_name', 'report_export')->where('causer_id', $admin->id)->count());
    }

    public function test_exported_rows_are_labelled_in_indonesian_in_wib_and_safe_from_formulas(): void
    {
        $admin = $this->user('super_admin', null);
        $admin->update(['name' => '=HYPERLINK("http://jahat.test")']);
        $row = $this->log('auth', 'login_failed', '+Percobaan masuk', [
            'causer_type' => User::class, 'causer_id' => $admin->id,
            'properties' => ['context' => ['source' => 'mobile_staf', 'ip' => '10.0.0.5', 'device' => 'GinnvaStaff/1.2'], 'old' => ['is_active' => true], 'attributes' => ['is_active' => false]],
        ]);
        DB::table('activity_log')->where('id', $row->id)->update(['created_at' => '2026-11-02 00:30:00']);
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');

        $mapped = (new ActivityLogExport())->map(Activity::with('causer')->findOrFail($row->id));

        $this->assertSame('2026-11-02 07:30:00', $mapped[0]);
        $this->assertSame("'=HYPERLINK(\"http://jahat.test\")", $mapped[1], 'Nama berawalan = tidak boleh jadi rumus.');
        $this->assertSame('Staff', $mapped[2]);
        $this->assertSame('Keamanan Akun', $mapped[3]);
        $this->assertSame('Gagal Masuk', $mapped[4]);
        $this->assertSame("'+Percobaan masuk", $mapped[6]);
        $this->assertSame(['Aplikasi Staff', '10.0.0.5', 'GinnvaStaff/1.2'], [$mapped[7], $mapped[8], $mapped[9]]);
        $this->assertSame('Aktif: ya', $mapped[10]);
        $this->assertSame('Aktif: tidak', $mapped[11]);
        $this->assertSame('plain', ActivityLogExport::safe('plain'));
        $this->assertSame(5, ActivityLogExport::safe(5));
    }
}
