<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Models\AccountingPeriod;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Models\User;
use App\Services\AccountingPeriodService;
use App\Services\BookingPostingService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Penjualan Fase 3 (2026-11-12): pendapatan diakui pada tanggal pekerjaan selesai. Waktu dibekukan: Kamis 12 Nov 2026.
 * Bendera FEATURE_REVENUE_ON_COMPLETION mati = perilaku lama (hari kasir menyimpan).
 */
class RevenueOnCompletionTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('kasir', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function flag(bool $on): void
    {
        config(['features.revenue_on_completion' => $on]);
    }

    private function completed(?string $completedAt, float $amount = 1000000, array $overrides = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        $booking = Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $this->store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-28', 'status' => 'completed',
            'transaction_amount' => $amount, 'amount_received' => $amount,
        ], $overrides));

        // Hook mengisi completed_at = sekarang saat dibuat sebagai completed; timpa sesuai skenario.
        $booking->update(['completed_at' => $completedAt]);

        return $booking->fresh();
    }

    private function entry(Booking $booking): JournalEntry
    {
        return JournalEntry::findOrFail($booking->fresh()->journal_entry_id);
    }

    private function closePeriod(string $month): void
    {
        $user = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']);
        AccountingPeriod::create(['period_month' => $month, 'closed_by' => $user->id, 'closed_at' => now()]);
    }

    private function admin(): User
    {
        return tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin'));
    }

    /** Pendapatan bersih (kredit - debit) akun 4100 pada satu bulan, dari jurnal posted. */
    private function revenueInMonth(string $yearMonth): float
    {
        return (float) DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.chart_of_account_id')
            ->where('e.status', 'posted')->where('a.code', '4100')
            ->where('e.entry_date', 'like', $yearMonth . '%')
            ->selectRaw('COALESCE(SUM(l.credit) - SUM(l.debit), 0) as net')->value('net');
    }

    public function test_with_the_flag_off_the_journal_is_dated_today_as_before(): void
    {
        $this->flag(false);
        $booking = $this->completed('2026-10-30 14:00:00');

        app(BookingPostingService::class)->sync($booking);

        $this->assertSame('2026-11-12', $this->entry($booking)->entry_date->toDateString());
    }

    public function test_with_the_flag_on_the_journal_is_dated_the_completion_day(): void
    {
        $this->flag(true);
        $booking = $this->completed('2026-10-30 14:00:00');

        app(BookingPostingService::class)->sync($booking);

        $entry = $this->entry($booking);
        $this->assertSame('2026-10-30', $entry->entry_date->toDateString());
        $this->assertStringNotContainsString('periode ditutup', $entry->description);
        $this->assertEquals(1000000.0, $this->revenueInMonth('2026-10'));
        $this->assertEquals(0.0, $this->revenueInMonth('2026-11'));
    }

    public function test_a_closed_period_falls_back_to_today_with_a_note(): void
    {
        $this->flag(true);
        $this->closePeriod('2026-10-01');
        $booking = $this->completed('2026-10-30 14:00:00');

        app(BookingPostingService::class)->sync($booking);

        $entry = $this->entry($booking);
        $this->assertSame('2026-11-12', $entry->entry_date->toDateString());
        $this->assertStringContainsString('periode ditutup', $entry->description);
        $this->assertStringContainsString('30 Okt 2026', $entry->description);
    }

    public function test_a_missing_or_future_completion_date_uses_today(): void
    {
        $this->flag(true);

        $none = $this->completed(null);
        app(BookingPostingService::class)->sync($none);
        $this->assertSame('2026-11-12', $this->entry($none)->entry_date->toDateString());

        $future = $this->completed('2026-11-20 09:00:00');
        app(BookingPostingService::class)->sync($future);
        $this->assertSame('2026-11-12', $this->entry($future)->entry_date->toDateString());
    }

    public function test_completed_at_is_set_once_when_the_status_becomes_completed(): void
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $this->store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-11-12', 'status' => 'confirmed',
        ]);
        $this->assertNull($booking->completed_at);

        $booking->update(['status' => 'completed', 'current_stage' => 'completed']);
        $first = $booking->fresh()->completed_at;
        $this->assertNotNull($first);
        $this->assertSame('2026-11-12 10:00:00', $first->toDateTimeString());

        Carbon::setTestNow('2026-11-13 09:00:00');
        $booking->fresh()->update(['notes' => 'catatan']);
        $this->assertSame('2026-11-12 10:00:00', $booking->fresh()->completed_at->toDateTimeString(), 'Tidak ditimpa.');
    }

    public function test_correcting_the_amount_across_months_keeps_the_pair_in_the_original_month(): void
    {
        $this->flag(true);
        $booking = $this->completed('2026-10-30 14:00:00', 1000000);
        $service = app(BookingPostingService::class);
        $service->sync($booking);

        $booking->update(['transaction_amount' => 1200000, 'amount_received' => 1200000]);
        $service->sync($booking->fresh());

        $this->assertEquals(1200000.0, $this->revenueInMonth('2026-10'), 'Jurnal lama dan pembaliknya sama-sama di Oktober.');
        $this->assertEquals(0.0, $this->revenueInMonth('2026-11'), 'Tidak ada sisa di November.');
    }

    public function test_with_the_flag_off_a_correction_is_reversed_today_as_before(): void
    {
        $this->flag(false);
        $booking = $this->completed('2026-10-30 14:00:00', 1000000);
        $service = app(BookingPostingService::class);
        $service->sync($booking);
        $original = $this->entry($booking);

        $booking->update(['transaction_amount' => 1200000, 'amount_received' => 1200000]);
        $service->sync($booking->fresh());

        $reversal = JournalEntry::where('reference_type', 'reversal')->where('reference_id', $original->id)->firstOrFail();
        $this->assertSame('2026-11-12', $reversal->entry_date->toDateString());
    }

    public function test_the_checklist_warns_about_completed_but_unprocessed_sales_only_with_the_flag_on(): void
    {
        $this->completed('2026-10-30 14:00:00');

        $this->flag(false);
        $this->assertNotContains('unprocessed_sales', array_column(app(AccountingPeriodService::class)->checklist(Carbon::parse('2026-10-01')), 'key'));

        $this->flag(true);
        $items = collect(app(AccountingPeriodService::class)->checklist(Carbon::parse('2026-10-01')));
        $this->assertSame(1, $items->firstWhere('key', 'unprocessed_sales')['count']);
        $this->assertSame('warn', $items->firstWhere('key', 'unprocessed_sales')['severity']);
    }

    public function test_the_correction_action_is_for_full_access_and_before_processing_only(): void
    {
        $this->actingAs($this->admin(), 'web');
        $booking = $this->completed('2026-10-30 14:00:00', 1000000, ['transaction_amount' => null, 'amount_received' => null]);

        Livewire::test(ListBookings::class)->removeTableFilter('upcoming')
            ->assertTableActionVisible('correct_completed_at', $booking);

        // Sudah diproses (punya jurnal): disembunyikan.
        $processed = $this->completed('2026-10-30 14:00:00');
        app(BookingPostingService::class)->sync($processed);
        Livewire::test(ListBookings::class)->removeTableFilter('upcoming')
            ->assertTableActionHidden('correct_completed_at', $processed->fresh());

        // Bukan akses penuh: disembunyikan.
        $kasir = tap(User::create(['name' => 'Kasir', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id]), fn (User $u) => $u->assignRole('kasir'));
        $this->actingAs($kasir, 'web');
        Livewire::test(ListBookings::class)->removeTableFilter('upcoming')
            ->assertTableActionHidden('correct_completed_at', $booking);
    }

    public function test_the_correction_action_updates_the_date_and_refuses_a_closed_period(): void
    {
        $this->actingAs($this->admin(), 'web');
        $booking = $this->completed('2026-11-10 09:00:00', 1000000, ['transaction_amount' => null, 'amount_received' => null]);

        Livewire::test(ListBookings::class)->removeTableFilter('upcoming')
            ->callTableAction('correct_completed_at', $booking, data: ['completed_at' => '2026-11-05 08:30:00'])
            ->assertNotified('Tanggal selesai diperbarui');
        $this->assertSame('2026-11-05 08:30:00', $booking->fresh()->completed_at->toDateTimeString());

        $this->closePeriod('2026-10-01');
        Livewire::test(ListBookings::class)->removeTableFilter('upcoming')
            ->callTableAction('correct_completed_at', $booking, data: ['completed_at' => '2026-10-20 08:30:00'])
            ->assertNotified('Periode sudah ditutup');
        $this->assertSame('2026-11-05 08:30:00', $booking->fresh()->completed_at->toDateTimeString());
    }
}
