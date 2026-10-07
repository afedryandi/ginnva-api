<?php

namespace Tests\Feature;

use App\Exports\TechnicianServiceDurationExport;
use App\Filament\Pages\TechnicianServiceDurationReport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Spk;
use App\Models\Store;
use App\Models\Technician;
use App\Models\User;
use App\Services\TechnicianServiceDurationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Akumulasi Durasi Servis Teknisi: durasi AKTUAL dari SPK (kendaraan masuk ke
 * keluar bengkel) dikreditkan penuh ke tiap installer booking-nya; hanya SPK
 * yang lengkap masuk-keluar dan check-in-nya di rentang laporan; teknisi aktif
 * saja; urut dari total terbesar. Plus halaman laporan (akses, rentang, cabang)
 * dan isi ekspor.
 */
class TechnicianServiceDurationTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['kasir', 'store_manager'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id,
        ], $extra)), fn (User $u) => $u->assignRole($role));
    }

    /** @return array{0: Technician, 1: ?User} */
    private function technician(string $name, ?Store $store = null, bool $withAccount = true, string $status = 'active'): array
    {
        $store ??= $this->store;
        $user = $withAccount ? $this->user('kasir', $store, ['name' => $name]) : null;

        return [Technician::create(['store_id' => $store->id, 'user_id' => $user?->id, 'name' => $name, 'level' => 'intermediate', 'status' => $status]), $user];
    }

    /** SPK dengan booking yang dikerjakan $installers. */
    private function spk(array $installers, ?string $in, ?string $out, ?Store $store = null): Spk
    {
        $store ??= $this->store;
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $booking = Booking::create([
            'booking_number' => 'BKG-T-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => $store->id,
            'service_type' => 'Pelindung Cat (PPF)', 'product_ppf' => true, 'preferred_date' => '2026-09-07', 'status' => 'confirmed',
        ]);
        foreach ($installers as $installer) {
            $booking->installers()->attach($installer->id);
        }

        return Spk::create([
            'spk_number' => 'SPK-T-' . uniqid(), 'store_id' => $store->id, 'booking_id' => $booking->id, 'customer_name' => 'Budi',
            'checked_in_at' => $in, 'checked_out_at' => $out,
        ]);
    }

    private function summarize(string $from = '2026-09-07', string $to = '2026-09-13', ?int $storeId = null)
    {
        return app(TechnicianServiceDurationService::class)->summarize(Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay(), $storeId);
    }

    private function row($rows, Technician $technician): array
    {
        return collect($rows)->firstWhere('technician_id', $technician->id);
    }

    // ------------------------------------------------------------- service

    public function test_durations_are_accumulated_per_technician_with_job_count_and_average(): void
    {
        [$andi, $andiUser] = $this->technician('Andi');
        $this->spk([$andiUser], '2026-09-07 09:00:00', '2026-09-07 12:00:00');   // 180 mnt
        $this->spk([$andiUser], '2026-09-08 09:00:00', '2026-09-08 10:00:00');   // 60 mnt

        $row = $this->row($this->summarize(), $andi);

        $this->assertSame(240, $row['total_minutes']);
        $this->assertSame(4.0, $row['total_hours']);
        $this->assertSame(2, $row['job_count']);
        $this->assertSame(120, $row['avg_minutes_per_job']);
        $this->assertTrue($row['has_account']);
    }

    public function test_a_team_job_credits_the_full_duration_to_every_installer(): void
    {
        [$a, $aUser] = $this->technician('Andi');
        [$b, $bUser] = $this->technician('Budi');
        $this->spk([$aUser, $bUser], '2026-09-07 09:00:00', '2026-09-07 11:00:00');

        $rows = $this->summarize();

        $this->assertSame(120, $this->row($rows, $a)['total_minutes']);
        $this->assertSame(120, $this->row($rows, $b)['total_minutes'], 'Durasi penuh, tidak dibagi rata.');
    }

    public function test_only_complete_spks_checked_in_within_the_range_count(): void
    {
        [$tech, $user] = $this->technician('Citra');
        $this->spk([$user], '2026-09-07 09:00:00', null);                          // belum keluar
        $this->spk([$user], null, '2026-09-07 12:00:00');                          // belum masuk
        $this->spk([$user], '2026-09-06 09:00:00', '2026-09-06 12:00:00');         // sebelum rentang
        $this->spk([$user], '2026-09-14 09:00:00', '2026-09-14 12:00:00');         // sesudah rentang
        $this->spk([$user], '2026-09-07 12:00:00', '2026-09-07 12:00:00');         // durasi 0
        $this->spk([$user], '2026-09-13 22:00:00', '2026-09-14 02:00:00');         // check-in di rentang: dihitung penuh (240)

        $row = $this->row($this->summarize(), $tech);

        $this->assertSame(240, $row['total_minutes']);
        $this->assertSame(1, $row['job_count']);
    }

    public function test_installers_without_a_technician_record_or_inactive_technicians_are_ignored(): void
    {
        [$active, $activeUser] = $this->technician('Dewi');
        [$inactive, $inactiveUser] = $this->technician('Eko', null, true, 'inactive');
        $plain = $this->user('kasir', null, ['name' => 'Bukan Teknisi']);
        $this->spk([$activeUser, $inactiveUser, $plain], '2026-09-07 09:00:00', '2026-09-07 10:00:00');

        $rows = $this->summarize();

        $this->assertSame([$active->id], $rows->pluck('technician_id')->all());
        $this->assertSame(60, $this->row($rows, $active)['total_minutes']);
    }

    public function test_a_technician_without_an_account_shows_zero_and_is_flagged(): void
    {
        [$tech] = $this->technician('Fajar', null, false);

        $row = $this->row($this->summarize(), $tech);

        $this->assertFalse($row['has_account']);
        $this->assertSame(0, $row['total_minutes']);
        $this->assertSame(0, $row['job_count']);
        $this->assertSame(0, $row['avg_minutes_per_job']);
    }

    public function test_rows_are_sorted_by_total_duration_descending_and_the_store_filter_applies(): void
    {
        [$short, $shortUser] = $this->technician('Gita');
        [$long, $longUser] = $this->technician('Hadi');
        [$other, $otherUser] = $this->technician('Indra', $this->otherStore);
        $this->spk([$shortUser], '2026-09-07 09:00:00', '2026-09-07 10:00:00');
        $this->spk([$longUser], '2026-09-07 09:00:00', '2026-09-07 13:00:00');
        $this->spk([$otherUser], '2026-09-07 09:00:00', '2026-09-07 20:00:00', $this->otherStore);

        $this->assertSame([$other->id, $long->id, $short->id], $this->summarize()->pluck('technician_id')->all());
        $this->assertSame([$long->id, $short->id], $this->summarize('2026-09-07', '2026-09-13', $this->store->id)->pluck('technician_id')->all());
    }

    // ------------------------------------------------------------- halaman

    private function page(User $viewer)
    {
        $this->actingAs($viewer, 'web');

        return Livewire::test(TechnicianServiceDurationReport::class)->set('from', '2026-09-07')->set('to', '2026-09-13');
    }

    public function test_page_access_follows_menu_access(): void
    {
        $this->actingAs($this->user('kasir'), 'web');
        $this->assertTrue(TechnicianServiceDurationReport::canAccess());

        $this->actingAs($this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]), 'web');
        $this->assertFalse(TechnicianServiceDurationReport::canAccess());
    }

    public function test_default_range_is_the_current_month_and_garbage_falls_back_to_it(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $garbage = Livewire::test(TechnicianServiceDurationReport::class, ['from' => 'bukan-tanggal', 'to' => '???'])->instance();

        $this->assertSame(now()->startOfMonth()->toDateString(), $garbage->from);
        $this->assertSame(now()->endOfMonth()->toDateString(), $garbage->to);
    }

    public function test_a_store_manager_is_pinned_to_their_own_store_while_full_access_can_filter(): void
    {
        [, $mineUser] = $this->technician('Andi');
        [, $theirsUser] = $this->technician('Budi', $this->otherStore);
        $this->spk([$mineUser], '2026-09-07 09:00:00', '2026-09-07 10:00:00');
        $this->spk([$theirsUser], '2026-09-07 09:00:00', '2026-09-07 10:00:00', $this->otherStore);

        $manager = $this->page($this->user('store_manager'));
        $this->assertSame(['Andi'], $manager->instance()->getRows()->pluck('name')->all());
        $manager->set('storeId', $this->otherStore->id);
        $this->assertSame(['Andi'], $manager->instance()->getRows()->pluck('name')->all(), 'Filter cabang tidak berlaku untuk non full-access.');

        $admin = $this->page($this->user('super_admin'));
        $this->assertEqualsCanonicalizing(['Andi', 'Budi'], $admin->instance()->getRows()->pluck('name')->all());
        $admin->set('storeId', $this->otherStore->id);
        $this->assertSame(['Budi'], $admin->instance()->getRows()->pluck('name')->all());
    }

    public function test_the_page_renders_totals_and_the_empty_state(): void
    {
        $this->page($this->user('super_admin'))->assertSee('Belum ada teknisi aktif untuk cabang ini.');

        [, $user] = $this->technician('Andi');
        $this->spk([$user], '2026-09-07 09:00:00', '2026-09-07 12:00:00');

        $this->page($this->user('super_admin'))->assertSee('Andi')->assertSee('3,0 jam')->assertSee('180 menit');
    }

    // ------------------------------------------------------------- ekspor

    public function test_excel_export_mirrors_the_screen_rows(): void
    {
        [, $user] = $this->technician('Andi');
        $this->technician('Tanpa Akun', null, false);
        $this->spk([$user], '2026-09-07 09:00:00', '2026-09-07 12:00:00');
        $rows = $this->page($this->user('super_admin'))->instance()->getRows();

        $export = new TechnicianServiceDurationExport(['from' => Carbon::parse('2026-09-07'), 'to' => Carbon::parse('2026-09-13'), 'rows' => $rows]);

        $this->assertSame(['Teknisi', 'Cabang', 'Jumlah Job', 'Total Durasi (Jam)', 'Rata-rata per Job (Menit)'], $export->headings());
        $data = collect($export->array())->keyBy(0);
        $this->assertEquals(['Andi', 'Toko A', 1, 3.0, 180], $data['Andi']);
        $this->assertSame('-', $data['Tanpa Akun'][4], 'Tanpa job: rata-rata ditampilkan "-", bukan 0.');
    }

    public function test_pdf_export_downloads_a_file(): void
    {
        [, $user] = $this->technician('Andi');
        $this->spk([$user], '2026-09-07 09:00:00', '2026-09-07 12:00:00');

        $this->page($this->user('super_admin'))->callAction('exportPdf')->assertFileDownloaded();
    }
}
