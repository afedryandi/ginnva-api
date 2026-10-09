<?php

namespace Tests\Feature;

use App\Filament\Resources\WarrantyResource;
use App\Filament\Resources\WarrantyResource\Pages\ListWarranties;
use App\Filament\Resources\WarrantyResource\Pages\ViewWarranty;
use App\Filament\Resources\WarrantyResource\RelationManagers\MaintenanceSchedulesRelationManager;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Store;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyMaintenanceSchedule;
use App\Models\WarrantyMaintenanceVisit;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Maintenance PPF (siklus jadwal pada garansi): jadwal pertama terbentuk hanya untuk PPF yang disetujui dengan interval
 * (digeser ke hari buka, tidak pernah ke masa lalu), occurrence berikutnya setelah selesai / hangus (dan pemberitahuan saat
 * siklus habis), command harian (konfirmasi H-7, hangus bila lewat tanpa respons, garansi dibatalkan / toko nonaktif
 * dilewati), tindak lanjut forfeit berturut-turut, konfirmasi / tolak oleh customer lewat app (membuat booking),
 * penyelesaian / pembatalan booking, serta catat / batalkan kunjungan walk-in. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class MaintenancePpfTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->seed(RolePermissionSeeder::class);
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(string $role): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999), 'email' => uniqid() . '@mail.test']);
    }

    private function warranty(array $overrides = []): Warranty
    {
        return Warranty::create(array_merge([
            'warranty_code' => 'GNV-PPF-T' . strtoupper(substr(uniqid(), -6)), 'customer_name' => 'Budi Santoso', 'phone_number' => '081234567890', 'car_plate' => 'B 1234 XYZ', 'car_type' => 'Toyota Raize',
            'product_series' => 'Ginnva PPF Pro', 'product_category' => 'ppf', 'installation_date' => '2026-04-20', 'expiry_date' => '2031-04-20',
            'dealer_name' => 'Toko A', 'store_id' => $this->store->id, 'customer_id' => null, 'status' => 'active', 'review_status' => 'approved',
            'maintenance_quota' => 3, 'maintenance_interval_months' => 6,
        ], $overrides));
    }

    private function schedule(Warranty $warranty, int $sequence, string $date, string $status = 'pending', array $extra = []): WarrantyMaintenanceSchedule
    {
        return WarrantyMaintenanceSchedule::create(array_merge(['warranty_id' => $warranty->id, 'sequence' => $sequence, 'scheduled_date' => $date, 'status' => $status], $extra));
    }

    // ------------------------------------------------------------- jadwal pertama

    public function test_the_first_schedule_is_created_for_an_approved_ppf_warranty_with_an_interval(): void
    {
        $warranty = $this->warranty();

        $first = $warranty->maintenanceSchedules()->get();
        $this->assertCount(1, $first);
        $this->assertSame(1, $first[0]->sequence);
        $this->assertSame('2026-10-20', $first[0]->scheduled_date->toDateString());
        $this->assertSame('pending', $first[0]->status);

        $warranty->update(['car_plate' => 'B 9999 ABC']);
        $this->assertSame(1, $warranty->maintenanceSchedules()->count(), 'Tidak dobel saat garansi diubah.');
    }

    public function test_no_schedule_without_an_approval_a_ppf_product_or_an_interval(): void
    {
        $pending = $this->warranty(['review_status' => 'pending_review']);
        $film = $this->warranty(['product_category' => 'window_film']);
        $noInterval = $this->warranty(['maintenance_interval_months' => null]);

        $this->assertSame(0, $pending->maintenanceSchedules()->count());
        $this->assertSame(0, $film->maintenanceSchedules()->count());
        $this->assertSame(0, $noInterval->maintenanceSchedules()->count());

        $pending->update(['review_status' => 'approved']);
        $this->assertSame(1, $pending->maintenanceSchedules()->count(), 'Terbentuk begitu garansi disetujui.');

        $noInterval->update(['maintenance_interval_months' => 4]);
        $this->assertSame(1, $noInterval->maintenanceSchedules()->count(), 'Terbentuk begitu interval diisi.');
    }

    public function test_the_date_moves_to_the_next_open_day_when_it_lands_on_a_closed_day(): void
    {
        $this->store->update(['opening_hours' => [['days' => ['sun'], 'closed' => true]]]);

        $warranty = $this->warranty(['installation_date' => '2026-04-11']);

        $this->assertSame('2026-10-12', $warranty->maintenanceSchedules()->firstOrFail()->scheduled_date->toDateString(), 'Minggu 11 Okt digeser ke Senin.');
    }

    public function test_an_old_warranty_never_gets_a_schedule_in_the_past(): void
    {
        $warranty = $this->warranty(['installation_date' => '2024-01-15']);

        $first = $warranty->maintenanceSchedules()->firstOrFail();
        $this->assertSame(1, $first->sequence, 'Siklus yang terlewat tidak memakan nomor urut.');
        $this->assertSame('2027-01-15', $first->scheduled_date->toDateString());
    }

    // ------------------------------------------------------------- hangus & selesai

    public function test_forfeiting_schedules_the_next_one_until_the_quota_is_used(): void
    {
        $warranty = $this->warranty(['maintenance_quota' => 2]);
        $first = $warranty->maintenanceSchedules()->firstOrFail();

        $first->forfeit(explicit: true);

        $this->assertSame('forfeited', $first->status);
        $this->assertNotNull($first->responded_at);
        $second = $warranty->maintenanceSchedules()->where('sequence', 2)->firstOrFail();
        $this->assertSame('pending', $second->status);
        $this->assertSame('2027-04-20', $second->scheduled_date->toDateString());

        $first->forfeit(explicit: true);
        $this->assertSame(2, $warranty->maintenanceSchedules()->count(), 'Sudah hangus: tidak diproses dua kali.');
    }

    public function test_the_last_forfeit_ends_the_cycle_and_tells_the_customer(): void
    {
        $customer = $this->customer();
        $warranty = $this->warranty(['maintenance_quota' => 1, 'customer_id' => $customer->id]);
        $warranty->maintenanceSchedules()->firstOrFail()->forfeit(explicit: false);

        $this->assertSame(1, $warranty->maintenanceSchedules()->count(), 'Tidak ada occurrence berikutnya.');
        $note = CustomerNotification::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('Siklus Maintenance Berakhir', $note->title);
        $this->assertNull($warranty->maintenanceSchedules()->first()->responded_at, 'Tidak merespons berbeda dari menolak aktif.');
    }

    public function test_completing_schedules_the_next_and_the_last_one_announces_the_quota_is_used(): void
    {
        $customer = $this->customer();
        $warranty = $this->warranty(['maintenance_quota' => 2, 'customer_id' => $customer->id]);
        $first = $warranty->maintenanceSchedules()->firstOrFail();

        $first->completeAndScheduleNext();
        $this->assertSame('completed', $first->status);
        $second = $warranty->maintenanceSchedules()->where('sequence', 2)->firstOrFail();

        $second->completeAndScheduleNext();
        $this->assertSame(2, $warranty->maintenanceSchedules()->count());
        $this->assertSame('Kuota Maintenance Habis', CustomerNotification::where('customer_id', $customer->id)->latest('id')->firstOrFail()->title);
    }

    // ------------------------------------------------------------- command harian

    public function test_the_daily_command_sends_confirmations_inside_the_window_only(): void
    {
        $customer = $this->customer();
        $soon = $this->warranty(['customer_id' => $customer->id, 'maintenance_interval_months' => null]);
        $far = $this->warranty(['customer_id' => $customer->id, 'maintenance_interval_months' => null]);
        $inWindow = $this->schedule($soon, 1, '2026-10-12');
        $outside = $this->schedule($far, 1, '2026-11-20');

        $this->artisan('maintenance:process-schedules')->assertSuccessful();

        $this->assertSame('confirmation_sent', $inWindow->fresh()->status);
        $this->assertNotNull($inWindow->fresh()->reminder_sent_at);
        $this->assertSame('pending', $outside->fresh()->status);
        $this->assertSame('Konfirmasi Kedatangan Maintenance PPF', CustomerNotification::where('customer_id', $customer->id)->firstOrFail()->title);

        $this->artisan('maintenance:process-schedules')->assertSuccessful();
        $this->assertSame(1, CustomerNotification::where('customer_id', $customer->id)->count(), 'Tidak dikirim dobel.');
    }

    public function test_the_daily_command_forfeits_overdue_schedules_and_prepares_the_next(): void
    {
        $customer = $this->customer();
        $warranty = $this->warranty(['customer_id' => $customer->id, 'maintenance_interval_months' => 6, 'installation_date' => '2026-04-20']);
        $warranty->maintenanceSchedules()->delete();
        $overdue = $this->schedule($warranty, 1, '2026-10-01');
        $sent = $this->schedule($this->warranty(['customer_id' => $customer->id, 'maintenance_interval_months' => null]), 1, '2026-10-02', 'confirmation_sent');

        $this->artisan('maintenance:process-schedules')->assertSuccessful();

        $this->assertSame('forfeited', $overdue->fresh()->status);
        $this->assertSame('forfeited', $sent->fresh()->status);
        $next = $warranty->maintenanceSchedules()->where('sequence', 2)->firstOrFail();
        $this->assertSame('2027-04-01', $next->scheduled_date->toDateString());
    }

    public function test_revoked_warranties_and_inactive_stores_are_skipped(): void
    {
        $customer = $this->customer();
        $revoked = $this->warranty(['customer_id' => $customer->id, 'status' => 'revoked', 'maintenance_interval_months' => null]);
        $offStore = Store::create(['city' => 'Medan', 'address' => 'Jl. M', 'name' => 'Toko Tutup', 'is_active' => false]);
        $inactive = $this->warranty(['customer_id' => $customer->id, 'store_id' => $offStore->id, 'maintenance_interval_months' => null]);
        $revokedSoon = $this->schedule($revoked, 1, '2026-10-12');
        $inactiveOverdue = $this->schedule($inactive, 1, '2026-10-01');
        $revokedOverdue = $this->schedule($revoked, 2, '2026-10-02');

        $this->artisan('maintenance:process-schedules')->assertSuccessful();

        $this->assertSame('pending', $revokedSoon->fresh()->status, 'Tidak dikirimi konfirmasi.');
        $this->assertSame('pending', $inactiveOverdue->fresh()->status, 'Tidak dihanguskan paksa; siklus bisa lanjut kalau toko aktif lagi.');
        $this->assertSame('pending', $revokedOverdue->fresh()->status);
        $this->assertSame(0, CustomerNotification::count());
    }

    public function test_repeated_forfeits_notify_staff_for_follow_up(): void
    {
        $admin = $this->staff('super_admin');
        $twice = $this->warranty(['maintenance_interval_months' => null]);
        $this->schedule($twice, 1, '2026-04-01', 'forfeited');
        $this->schedule($twice, 2, '2026-07-01', 'forfeited');
        $recovered = $this->warranty(['maintenance_interval_months' => null]);
        $this->schedule($recovered, 1, '2026-04-01', 'forfeited');
        $this->schedule($recovered, 2, '2026-07-01', 'completed');
        $once = $this->warranty(['maintenance_interval_months' => null]);
        $this->schedule($once, 1, '2026-07-01', 'forfeited');

        $this->artisan('warranty:notify-maintenance-followup')->assertSuccessful();

        $notes = $admin->fresh()->notifications;
        $this->assertCount(1, $notes);
        $this->assertSame('Maintenance PPF Terlewat Berulang', $notes[0]->data['title']);
        $this->assertStringContainsString($twice->warranty_code, $notes[0]->data['body']);
    }

    // ------------------------------------------------------------- customer lewat app

    public function test_the_pending_list_shows_only_my_active_schedules(): void
    {
        $me = $this->customer();
        $other = $this->customer();
        $mine = $this->warranty(['customer_id' => $me->id, 'maintenance_interval_months' => null]);
        $revoked = $this->warranty(['customer_id' => $me->id, 'status' => 'revoked', 'maintenance_interval_months' => null]);
        $theirs = $this->warranty(['customer_id' => $other->id, 'maintenance_interval_months' => null]);
        $shown = $this->schedule($mine, 1, '2026-10-20');
        $this->schedule($mine, 2, '2027-04-20', 'completed');
        $this->schedule($revoked, 1, '2026-10-21');
        $this->schedule($theirs, 1, '2026-10-22');

        $response = $this->actingAs($me, 'customer')->getJson('/api/customer/maintenance-schedules/pending')->assertSuccessful();

        $this->assertSame([$shown->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_confirming_creates_a_pending_maintenance_booking(): void
    {
        $me = $this->customer();
        $warranty = $this->warranty(['customer_id' => $me->id, 'maintenance_interval_months' => null]);
        $schedule = $this->schedule($warranty, 1, '2026-10-20', 'confirmation_sent');

        $this->actingAs($me, 'customer')->postJson("/api/customer/maintenance-schedules/{$schedule->id}/confirm")->assertStatus(201);

        $booking = Booking::firstOrFail();
        $this->assertSame(['Maintenance PPF', 'pending', $warranty->id, $me->id, '2026-10-20'], [$booking->service_type, $booking->status, $booking->warranty_id, $booking->customer_id, $booking->preferred_date->toDateString()]);
        $fresh = $schedule->fresh();
        $this->assertSame(['confirmed', $booking->id], [$fresh->status, $fresh->booking_id]);
        $this->assertNotNull($fresh->responded_at);

        $this->actingAs($me, 'customer')->postJson("/api/customer/maintenance-schedules/{$schedule->id}/confirm")->assertStatus(422);
        $this->assertSame(1, Booking::count());
    }

    public function test_confirming_is_refused_in_the_unsafe_cases(): void
    {
        $me = $this->customer();
        $other = $this->customer();
        $this->store->update(['opening_hours' => [['days' => ['sun'], 'closed' => true]]]);

        $revoked = $this->schedule($this->warranty(['customer_id' => $me->id, 'status' => 'revoked', 'maintenance_interval_months' => null]), 1, '2026-10-20');
        $past = $this->schedule($this->warranty(['customer_id' => $me->id, 'maintenance_interval_months' => null]), 1, '2026-10-01');
        $closedDay = $this->schedule($this->warranty(['customer_id' => $me->id, 'maintenance_interval_months' => null]), 1, '2026-10-11');
        $offStore = Store::create(['city' => 'Medan', 'address' => 'Jl. M', 'name' => 'Toko Tutup', 'is_active' => false]);
        $inactive = $this->schedule($this->warranty(['customer_id' => $me->id, 'store_id' => $offStore->id, 'maintenance_interval_months' => null]), 1, '2026-10-20');
        $foreign = $this->schedule($this->warranty(['customer_id' => $other->id, 'maintenance_interval_months' => null]), 1, '2026-10-20');

        foreach ([$revoked, $past, $closedDay, $inactive] as $schedule) {
            $this->actingAs($me, 'customer')->postJson("/api/customer/maintenance-schedules/{$schedule->id}/confirm")->assertStatus(422);
        }
        $this->actingAs($me, 'customer')->postJson("/api/customer/maintenance-schedules/{$foreign->id}/confirm")->assertNotFound();

        $this->assertSame(0, Booking::count());
        $this->assertSame('pending', $revoked->fresh()->status);
    }

    public function test_declining_forfeits_and_prepares_the_next_occurrence(): void
    {
        $me = $this->customer();
        $warranty = $this->warranty(['customer_id' => $me->id, 'maintenance_interval_months' => 6]);
        $schedule = $warranty->maintenanceSchedules()->firstOrFail();

        $this->actingAs($me, 'customer')->postJson("/api/customer/maintenance-schedules/{$schedule->id}/decline")->assertSuccessful();

        $this->assertSame('forfeited', $schedule->fresh()->status);
        $this->assertNotNull($schedule->fresh()->responded_at);
        $this->assertSame(1, $warranty->maintenanceSchedules()->where('sequence', 2)->count());

        $this->actingAs($me, 'customer')->postJson("/api/customer/maintenance-schedules/{$schedule->id}/decline")->assertStatus(422);
    }

    // ------------------------------------------------------------- booking selesai / batal

    public function test_completing_the_maintenance_booking_finishes_the_schedule_and_logs_the_visit(): void
    {
        $me = $this->customer();
        $warranty = $this->warranty(['customer_id' => $me->id, 'maintenance_interval_months' => 6]);
        $schedule = $warranty->maintenanceSchedules()->firstOrFail();
        $this->actingAs($me, 'customer')->postJson("/api/customer/maintenance-schedules/{$schedule->id}/confirm")->assertStatus(201);
        $booking = Booking::firstOrFail();

        // Yang menyelesaikan booking adalah staf (pengamat booking mencatat pelakunya); actingAs customer di atas masih aktif.
        $this->actingAs($this->staff('super_admin'), 'web');
        $booking->update(['status' => 'confirmed']);
        $booking->update(['status' => 'completed', 'current_stage' => 'completed']);

        $this->assertSame('completed', $schedule->fresh()->status);
        $this->assertSame(1, $warranty->maintenanceSchedules()->where('sequence', 2)->count());
        $visit = WarrantyMaintenanceVisit::where('warranty_id', $warranty->id)->firstOrFail();
        $this->assertStringContainsString($booking->booking_number, $visit->note);
        $this->assertNull($visit->recorded_by);
    }

    public function test_cancelling_the_maintenance_booking_reopens_the_schedule(): void
    {
        $me = $this->customer();
        $warranty = $this->warranty(['customer_id' => $me->id, 'maintenance_interval_months' => 6]);
        $schedule = $warranty->maintenanceSchedules()->firstOrFail();
        $this->actingAs($me, 'customer')->postJson("/api/customer/maintenance-schedules/{$schedule->id}/confirm")->assertStatus(201);

        Booking::firstOrFail()->update(['status' => 'cancelled']);

        $this->assertSame('pending', $schedule->fresh()->status, 'Aktif lagi, bukan menggantung atau hangus.');
    }

    // ------------------------------------------------------------- kunjungan walk-in & panel

    public function test_staff_record_a_walk_in_visit_until_the_quota_is_used(): void
    {
        $customer = $this->customer();
        $warranty = $this->warranty(['customer_id' => $customer->id, 'maintenance_quota' => 2, 'maintenance_interval_months' => null]);
        $this->actingAs($this->staff('super_admin'), 'web');

        Livewire::test(ListWarranties::class)
            ->assertTableActionVisible('record_maintenance_visit', $warranty)
            ->callTableAction('record_maintenance_visit', $warranty, data: ['visited_at' => '2026-10-05', 'note' => 'Cek kap mesin'])
            ->assertNotified('Kunjungan maintenance dicatat');

        $this->assertSame(1, $warranty->fresh()->maintenance_used);
        Livewire::test(ListWarranties::class)->callTableAction('record_maintenance_visit', $warranty->fresh(), data: ['visited_at' => '2026-10-06']);

        $this->assertSame(0, $warranty->fresh()->maintenance_remaining);
        Livewire::test(ListWarranties::class)->assertTableActionHidden('record_maintenance_visit', $warranty->fresh());
        $this->assertSame(2, WarrantyMaintenanceVisit::where('warranty_id', $warranty->id)->count());
        $this->assertSame('Kunjungan Maintenance Tercatat', CustomerNotification::where('customer_id', $customer->id)->orderBy('id')->firstOrFail()->title);
    }

    public function test_a_walk_in_visit_cannot_be_dated_before_the_installation_or_in_the_future(): void
    {
        $warranty = $this->warranty(['maintenance_quota' => 2, 'maintenance_interval_months' => null]);
        $this->actingAs($this->staff('super_admin'), 'web');

        Livewire::test(ListWarranties::class)
            ->callTableAction('record_maintenance_visit', $warranty, data: ['visited_at' => '2026-04-01'])
            ->assertHasTableActionErrors(['visited_at']);
        Livewire::test(ListWarranties::class)
            ->callTableAction('record_maintenance_visit', $warranty, data: ['visited_at' => '2026-12-01'])
            ->assertHasTableActionErrors(['visited_at']);

        $this->assertSame(0, WarrantyMaintenanceVisit::count());
    }

    public function test_a_failing_push_does_not_undo_a_recorded_visit(): void
    {
        $customer = $this->customer();
        $warranty = $this->warranty(['customer_id' => $customer->id, 'maintenance_interval_months' => null]);
        $this->mock(\App\Services\PushNotificationService::class)->shouldReceive('sendToCustomer')->andThrow(new \RuntimeException('FCM down'));
        $this->actingAs($this->staff('super_admin'), 'web');

        Livewire::test(ListWarranties::class)->callTableAction('record_maintenance_visit', $warranty, data: ['visited_at' => '2026-10-05']);

        $this->assertSame(1, WarrantyMaintenanceVisit::where('warranty_id', $warranty->id)->count());
    }

    public function test_only_full_access_can_cancel_a_visit_and_the_quota_comes_back(): void
    {
        $warranty = $this->warranty(['maintenance_quota' => 1, 'maintenance_interval_months' => null]);
        $visit = WarrantyMaintenanceVisit::create(['warranty_id' => $warranty->id, 'visited_at' => '2026-10-05', 'note' => 'Salah catat']);
        $this->assertSame(0, $warranty->fresh()->maintenance_remaining);

        $this->actingAs($this->staff('kasir'), 'web');
        $withCount = WarrantyResource::getEloquentQuery()->findOrFail($warranty->id);
        Livewire::test(ListWarranties::class)->assertTableActionHidden('cancel_maintenance_visit', $withCount);

        $this->actingAs($this->staff('super_admin'), 'web');
        Livewire::test(ListWarranties::class)
            ->assertTableActionVisible('cancel_maintenance_visit', $withCount)
            ->callTableAction('cancel_maintenance_visit', $withCount, data: ['visit_id' => $visit->id, 'cancel_reason' => 'Salah pilih garansi'])
            ->assertNotified('Kunjungan dibatalkan, kuota dikembalikan');

        $this->assertTrue($visit->fresh()->isCancelled());
        $this->assertSame(1, $warranty->fresh()->maintenance_remaining);
    }

    public function test_the_schedule_tab_lists_every_occurrence_with_status_labels_and_a_filter(): void
    {
        $warranty = $this->warranty(['maintenance_interval_months' => null]);
        $done = $this->schedule($warranty, 1, '2026-04-20', 'completed');
        $lost = $this->schedule($warranty, 2, '2026-07-20', 'forfeited');
        $next = $this->schedule($warranty, 3, '2026-10-20', 'confirmation_sent', ['reminder_sent_at' => '2026-10-08 08:15:00']);
        $this->actingAs($this->staff('super_admin'), 'web');

        Livewire::test(MaintenanceSchedulesRelationManager::class, ['ownerRecord' => $warranty, 'pageClass' => ViewWarranty::class])
            ->assertCanSeeTableRecords([$done, $lost, $next], inOrder: false)
            ->assertTableColumnFormattedStateSet('status', 'Selesai', record: $done)
            ->assertTableColumnFormattedStateSet('status', 'Hangus', record: $lost)
            ->assertTableColumnFormattedStateSet('status', 'Menunggu Konfirmasi', record: $next)
            ->filterTable('status', 'forfeited')
            ->assertCanSeeTableRecords([$lost])
            ->assertCanNotSeeTableRecords([$done, $next]);
    }
}
