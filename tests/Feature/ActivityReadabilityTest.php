<?php

namespace Tests\Feature;

use App\Filament\Resources\ActivityResource;
use App\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Filament\Resources\ActivityResource\Pages\ViewActivity;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Support\ActivityPresenter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Histori Aktivitas Fase 0 (2026-10-21): waktu WIB, label dan nilai yang terbaca, deskripsi utuh, tautan dan filter objek.
 */
class ActivityReadabilityTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private ?string $originalTimezone = null;

    /** Zona aplikasi di tes: config() saja tidak mengubah zona bawaan PHP/Carbon, jadi keduanya diatur dan dipulihkan. */
    private function useAppTimezone(string $timezone): void
    {
        $this->originalTimezone ??= date_default_timezone_get();
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
    }

    protected function tearDown(): void
    {
        if ($this->originalTimezone !== null) {
            date_default_timezone_set($this->originalTimezone);
        }

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        ActivityPresenter::flush();
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store =Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko Kebayoran', 'is_active' => true]);
    }

    private function admin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]);
        $user->assignRole('super_admin');
        $this->actingAs($user, 'web');

        return $user;
    }

    private function log(array $attributes = []): Activity
    {
        $activity = Activity::create(array_merge([
            'log_name' => 'store', 'description' => 'updated', 'event' => 'updated',
            'subject_type' => Store::class, 'subject_id' => $this->store->id, 'properties' => [],
        ], $attributes));

        return $activity->fresh();
    }

    // ------------------------------------------------------------- nilai dan label

    public function test_labels_are_in_indonesian_with_a_readable_fallback(): void
    {
        $this->assertSame('Toko', ActivityPresenter::label('store_id'));
        $this->assertSame('Gaji Pokok', ActivityPresenter::label('base_salary'));
        $this->assertSame('Kolom Baru Belum Dipetakan', ActivityPresenter::label('kolom_baru_belum_dipetakan'));
        $this->assertSame('Jenis Bahan', ActivityPresenter::label('jenis_bahan_id'), 'Akhiran _id dibuang pada label bawaan.');
    }

    public function test_values_are_turned_into_readable_text(): void
    {
        $this->assertSame('ya', ActivityPresenter::value('is_active', true));
        $this->assertSame('tidak', ActivityPresenter::value('is_active', 0));
        $this->assertSame('—', ActivityPresenter::value('notes', null));
        $this->assertSame('Rp 1.350.000', ActivityPresenter::value('base_price', '1350000.00'));
        $this->assertSame('120', ActivityPresenter::value('current_stock', 120), 'Bukan kolom uang: tidak diberi Rp.');
        $this->assertSame('Dikerjakan', ActivityPresenter::value('status', 'in_progress'));
        $this->assertSame('status_tak_dikenal', ActivityPresenter::value('status', 'status_tak_dikenal'));
        $this->assertSame('{"a":1}', ActivityPresenter::value('payload', ['a' => 1]));
    }

    public function test_foreign_keys_show_the_name_not_the_number(): void
    {
        $this->assertSame('Toko Kebayoran', ActivityPresenter::value('store_id', $this->store->id));
        $this->assertSame('#99999 (sudah dihapus)', ActivityPresenter::value('store_id', 99999));

        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $customer->update(['name' => null]);
        $customer->delete();
        $this->assertSame('Pelanggan Terhapus', ActivityPresenter::value('customer_id', $customer->id));
    }

    public function test_dates_are_shown_in_wib_and_date_only_values_without_a_clock(): void
    {
        $this->useAppTimezone('UTC');

        $this->assertSame('02 Nov 2026 07:30', ActivityPresenter::value('clock_in_at', '2026-11-02T00:30:00.000000Z'));
        $this->assertSame('02 Nov 2026', ActivityPresenter::value('received_date', '2026-11-02T00:00:00.000000Z'), 'Tengah malam di zona aplikasi = tanggal saja.');
        $this->assertSame('02 Nov 2026', ActivityPresenter::value('due_date', '2026-11-02'));
        $this->assertSame('Catatan 2026-11 biasa', ActivityPresenter::value('notes', 'Catatan 2026-11 biasa'));
    }

    public function test_rows_pair_each_label_with_a_readable_value(): void
    {
        $this->assertSame(
            ['Toko' => 'Toko Kebayoran', 'Harga Dasar' => 'Rp 500.000', 'Aktif' => 'tidak'],
            ActivityPresenter::rows(['store_id' => $this->store->id, 'base_price' => 500000, 'is_active' => false])
        );
        $this->assertSame([], ActivityPresenter::rows(null));
    }

    // ------------------------------------------------------------- deskripsi dan objek

    public function test_a_package_default_description_becomes_a_full_sentence(): void
    {
        $updated = $this->log(['properties' => ['attributes' => ['status' => 'x', 'store_id' => 1, 'notes' => 'y']]]);
        $created = $this->log(['description' => 'created', 'event' => 'created']);
        $deleted = $this->log(['description' => 'deleted', 'event' => 'deleted']);
        $custom = $this->log(['description' => 'Radius absen toko diubah']);

        $id = $this->store->id;
        $this->assertSame("Toko #{$id} diubah (Status, Toko, Catatan)", ActivityPresenter::describe($updated));
        $this->assertSame("Toko #{$id} dibuat", ActivityPresenter::describe($created));
        $this->assertSame("Toko #{$id} dihapus", ActivityPresenter::describe($deleted));
        $this->assertSame('Radius absen toko diubah', ActivityPresenter::describe($custom), 'Deskripsi yang sudah rapi tidak diubah.');
    }

    public function test_a_log_for_a_model_that_no_longer_exists_in_the_code_does_not_break(): void
    {
        $this->admin();
        $old = $this->log(['subject_type' => 'App\\Models\\RollScrapPool', 'subject_id' => 5, 'log_name' => 'roll_scrap_pool']);

        $this->assertSame('RollScrapPool #5', ActivityPresenter::subjectLabel($old));
        $this->assertNull(ActivityPresenter::subjectUrl($old));

        Livewire::test(ListActivities::class)->assertSuccessful()->assertCanSeeTableRecords([ActivityResource::getEloquentQuery()->findOrFail($old->id)]);
        Livewire::test(ViewActivity::class, ['record' => $old->getRouteKey()])->assertSuccessful();
    }

    public function test_the_object_links_to_its_record_only_while_it_exists(): void
    {
        $this->admin();
        $log = $this->log();

        $url = ActivityPresenter::subjectUrl($log);
        $this->assertNotNull($url);
        $this->assertStringContainsString((string) $this->store->id, $url);

        $this->store->delete();
        $this->assertNull(ActivityPresenter::subjectUrl($log->fresh()));
    }

    public function test_a_manual_entry_without_an_event_is_labelled_as_recorded(): void
    {
        $this->admin();
        $manual = $this->log(['event' => null, 'description' => 'Peran diubah manual', 'log_name' => 'role']);

        Livewire::test(ListActivities::class)
            ->assertTableColumnFormattedStateSet('event', 'Dicatat', record: ActivityResource::getEloquentQuery()->findOrFail($manual->id));
    }

    public function test_a_customer_actor_is_labelled_as_a_customer(): void
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $log = $this->log(['causer_type' => Customer::class, 'causer_id' => $customer->id]);

        $this->assertSame('Pelanggan', ActivityPresenter::actorNote($log));
        $this->assertNull(ActivityPresenter::actorNote($this->log()));
    }

    // ------------------------------------------------------------- tampilan

    public function test_the_list_and_detail_show_wib_times(): void
    {
        $this->useAppTimezone('UTC');
        $this->admin();
        $log = $this->log();
        DB::table('activity_log')->where('id', $log->id)->update(['created_at' => '2026-11-02 00:30:00']);
        $record = ActivityResource::getEloquentQuery()->findOrFail($log->id);

        Livewire::test(ListActivities::class)
            ->assertTableColumnFormattedStateSet('created_at', '02 Nov 2026 07:30:00', record: $record);

        Livewire::test(ViewActivity::class, ['record' => $log->getRouteKey()])->assertSee('02 Nov 2026 07:30:00');
    }

    public function test_the_detail_shows_readable_changes(): void
    {
        $this->admin();
        $log = $this->log(['properties' => [
            'old' => ['base_price' => 1200000, 'is_active' => true, 'store_id' => $this->store->id],
            'attributes' => ['base_price' => 1350000, 'is_active' => false, 'store_id' => $this->store->id],
        ]]);

        Livewire::test(ViewActivity::class, ['record' => $log->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Harga Dasar')
            ->assertSee('Rp 1.350.000')
            ->assertSee('Rp 1.200.000')
            ->assertSee('Toko Kebayoran')
            ->assertDontSee('base_price');
    }

    public function test_filter_by_object_type_and_number(): void
    {
        $admin = $this->admin();
        $other = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko Bandung', 'is_active' => true]);
        $mine = $this->log();
        $theirs = $this->log(['subject_id' => $other->id]);
        $customer = $this->log(['subject_type' => Customer::class, 'subject_id' => $this->store->id, 'log_name' => 'customer']);

        $this->assertSame('Toko', ActivityResource::subjectTypeOptions()[Store::class]);

        Livewire::test(ListActivities::class)
            ->filterTable('subject', ['type' => Store::class, 'id' => $this->store->id])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs, $customer]);

        Livewire::test(ListActivities::class)
            ->filterTable('subject', ['type' => Store::class])
            ->assertCanSeeTableRecords([$mine, $theirs])
            ->assertCanNotSeeTableRecords([$customer]);
    }

    public function test_the_date_filter_follows_wib_day_boundaries(): void
    {
        $this->useAppTimezone('UTC');
        $this->admin();
        $beforeMidnight = $this->log();
        $afterMidnight = $this->log();
        // 2 Nov 2026 23:30 WIB = 16:30 UTC; 3 Nov 00:30 WIB = 17:30 UTC.
        DB::table('activity_log')->where('id', $beforeMidnight->id)->update(['created_at' => '2026-11-02 16:30:00']);
        DB::table('activity_log')->where('id', $afterMidnight->id)->update(['created_at' => '2026-11-02 17:30:00']);

        Livewire::test(ListActivities::class)
            ->filterTable('created_at', ['from' => '2026-11-02', 'until' => '2026-11-02'])
            ->assertCanSeeTableRecords([$beforeMidnight->fresh()])
            ->assertCanNotSeeTableRecords([$afterMidnight->fresh()]);
    }
}
