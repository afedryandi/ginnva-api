<?php

namespace Tests\Feature;

use App\Filament\Pages\ClosePeriodPage;
use App\Models\AccountingPeriod;
use App\Models\AccountingPeriodEvent;
use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingPeriodService;
use App\Services\JournalEntryService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tutup Periode (melengkapi AccountingPeriodServiceTest): pemblokir (draft, tidak seimbang), bulan
 * ganda/masa depan, efek penguncian ke Jurnal Umum, jejak buka-tutup, dan layar admin: izin, tampilan,
 * aksi Tutup / Buka Kembali / Tutup Beberapa Bulan, konfirmasi peringatan, dan notifikasi ke direksi lain.
 */
class ClosePeriodPageTest extends TestCase
{
    use RefreshDatabase;

    private AccountingPeriodService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('spv_finance', 'web');
        Role::findOrCreate('kasir', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->service = app(AccountingPeriodService::class);
    }

    private function user(string $role, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x'], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function monthsAgo(int $n): Carbon
    {
        return now()->subMonthsNoOverflow($n)->startOfMonth();
    }

    private function postOn(Carbon $month, bool $draft = false, float $amount = 100000): JournalEntry
    {
        $svc = app(JournalEntryService::class);
        $entry = $svc->create(['entry_date' => $month->copy()->addDays(2)->toDateString(), 'description' => 'Uji periode', 'reference_type' => 'manual'], [
            ['chart_of_account_id' => ChartOfAccount::where('code', '1101')->value('id'), 'debit' => $amount],
            ['chart_of_account_id' => ChartOfAccount::where('type', 'pendapatan')->where('is_postable', true)->value('id'), 'credit' => $amount],
        ]);

        return $draft ? $entry : $svc->post($entry, null);
    }

    private function args(Carbon $month): array
    {
        return ['year' => $month->year, 'month' => $month->month];
    }

    private function assertRefused(callable $action, string $fragment): void
    {
        try {
            $action();
            $this->fail('Seharusnya ditolak: ' . $fragment);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    // ------------------------------------------------------------- service

    public function test_closing_records_who_when_and_a_history_event(): void
    {
        $admin = $this->user('super_admin');
        $this->postOn($this->monthsAgo(2));

        $period = $this->service->close($this->monthsAgo(2), $admin->id, 'Tutup uji');

        $this->assertSame($admin->id, $period->closed_by);
        $this->assertNotNull($period->closed_at);
        $this->assertEquals(100000, $period->snapshot['closing_cash']);
        $event = AccountingPeriodEvent::firstOrFail();
        $this->assertSame('closed', $event->action);
        $this->assertSame($admin->id, $event->user_id);
        $this->assertSame('Tutup uji', $event->note);
    }

    public function test_a_closed_month_cannot_be_closed_again_and_future_months_are_refused(): void
    {
        $this->postOn($this->monthsAgo(2));
        $this->service->close($this->monthsAgo(2), null);

        $this->assertRefused(fn () => $this->service->close($this->monthsAgo(2), null), 'sudah ditutup');
        $this->assertRefused(fn () => $this->service->close(now()->addMonth()->startOfMonth(), null), 'sudah lewat');
        $this->assertSame(1, AccountingPeriod::count());
    }

    public function test_unbalanced_posted_totals_block_closing_and_leave_no_period_behind(): void
    {
        $month = $this->monthsAgo(2);
        $entry = JournalEntry::create(['entry_number' => 'JE-X-1', 'entry_date' => $month->copy()->addDays(3)->toDateString(), 'description' => 'Miring', 'status' => 'posted']);
        $entry->lines()->create(['chart_of_account_id' => ChartOfAccount::where('code', '1101')->value('id'), 'debit' => 1000, 'credit' => 0]);
        $entry->lines()->create(['chart_of_account_id' => ChartOfAccount::where('type', 'pendapatan')->where('is_postable', true)->value('id'), 'debit' => 0, 'credit' => 900]);

        $this->assertSame('block', collect($this->service->checklist($month))->firstWhere('key', 'imbalance')['severity']);
        $this->assertRefused(fn () => $this->service->close($month, null), 'tidak seimbang');
        $this->assertFalse(AccountingPeriod::isClosedFor($month));
        $this->assertSame(0, AccountingPeriodEvent::count());
    }

    public function test_a_closed_period_locks_journals_and_reopening_unlocks_them(): void
    {
        $month = $this->monthsAgo(2);
        $this->postOn($month);
        $period = $this->service->close($month, null);

        $this->assertRefused(fn () => $this->postOn($month), 'sudah ditutup');

        $this->service->reopen($period->fresh(), null, 'Koreksi akhir');

        $this->assertSame('posted', $this->postOn($month)->status);
    }

    public function test_reopening_requires_a_reason_and_is_logged_with_it(): void
    {
        $admin = $this->user('super_admin');
        $this->postOn($this->monthsAgo(2));
        $period = $this->service->close($this->monthsAgo(2), $admin->id);

        $this->assertRefused(fn () => $this->service->reopen($period, $admin->id, '  '), 'Alasan');
        $this->assertTrue(AccountingPeriod::isClosedFor($this->monthsAgo(2)));

        $this->service->reopen($period, $admin->id, 'Salah akun');

        $activity = Activity::where('log_name', 'accounting_period')->where('description', 'like', '%Salah akun%')->first();
        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
        $reopened = AccountingPeriodEvent::where('action', 'reopened')->firstOrFail();
        $this->assertSame('Salah akun', $reopened->note);
        $this->assertSame($admin->id, $reopened->user_id);
    }

    // ------------------------------------------------------------- layar admin

    public function test_only_full_access_users_can_open_the_page(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(ClosePeriodPage::canAccess());

        $this->actingAs($this->user('spv_finance'), 'web');
        $this->assertFalse(ClosePeriodPage::canAccess());

        $this->actingAs($this->user('kasir'), 'web');
        $this->assertFalse(ClosePeriodPage::canAccess());
    }

    public function test_the_page_lists_months_with_status_snapshot_and_history(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $month = $this->monthsAgo(1);
        $this->postOn($month);
        $this->service->close($month, null, 'Catatan tutup');

        $component = Livewire::test(ClosePeriodPage::class)->set('data.year', $month->year);
        $component->assertSuccessful()->assertSee('Ditutup')->assertSee('Catatan tutup')->assertSee('Saat ditutup: 1 jurnal');

        $months = collect($component->instance()->getMonths())->keyBy('month');
        $this->assertCount(12, $months);
        $this->assertTrue($months[$month->month]['is_closed']);
        $this->assertSame(1, $months[$month->month]['posted_count']);
        $this->assertTrue($months[$month->month]['balanced']);
        $this->assertSame(1, $component->instance()->getEvents()->count());
    }

    public function test_close_action_closes_the_month_and_notifies_other_admins_only(): void
    {
        $actor = $this->user('super_admin');
        $other = $this->user('direksi');
        $month = $this->monthsAgo(2);
        $this->postOn($month);
        $this->actingAs($actor, 'web');

        Livewire::test(ClosePeriodPage::class)
            ->callAction('closePeriod', data: ['notes' => 'Tutup via layar'], arguments: $this->args($month))
            ->assertHasNoActionErrors();

        $this->assertTrue(AccountingPeriod::isClosedFor($month));
        $this->assertSame($actor->id, AccountingPeriod::firstOrFail()->closed_by);
        $this->assertSame('Tutup via layar', AccountingPeriod::firstOrFail()->notes);
        $this->assertGreaterThanOrEqual(1, $other->notifications()->count());
        $this->assertSame(0, $actor->notifications()->count(), 'Pelaku tidak diberi notifikasi tentang aksinya sendiri.');
    }

    public function test_close_action_is_refused_with_a_draft_and_for_the_current_month(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $month = $this->monthsAgo(2);
        $this->postOn($month, draft: true);

        Livewire::test(ClosePeriodPage::class)->callAction('closePeriod', data: [], arguments: $this->args($month));
        $this->assertFalse(AccountingPeriod::isClosedFor($month));

        Livewire::test(ClosePeriodPage::class)->callAction('closePeriod', data: [], arguments: $this->args(now()));
        $this->assertFalse(AccountingPeriod::isClosedFor(now()));
        $this->assertSame(0, AccountingPeriodEvent::count());
    }

    public function test_warnings_must_be_acknowledged_in_the_close_dialog(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $month = $this->monthsAgo(2);
        $this->postOn($month);
        BankStatementLine::create([
            'chart_of_account_id' => ChartOfAccount::where('code', '1102')->value('id'),
            'statement_date' => $month->copy()->addDays(4)->toDateString(),
            'description' => 'Belum dicocokkan', 'amount' => 5000, 'status' => 'unmatched',
        ]);

        Livewire::test(ClosePeriodPage::class)
            ->callAction('closePeriod', data: ['acknowledge' => false], arguments: $this->args($month))
            ->assertHasActionErrors(['acknowledge']);
        $this->assertFalse(AccountingPeriod::isClosedFor($month));

        Livewire::test(ClosePeriodPage::class)
            ->callAction('closePeriod', data: ['acknowledge' => true], arguments: $this->args($month))
            ->assertHasNoActionErrors();
        $this->assertTrue(AccountingPeriod::isClosedFor($month));
        $this->assertContains('bank', AccountingPeriod::firstOrFail()->snapshot['acknowledged_warnings']);
    }

    public function test_reopen_action_needs_a_reason_notifies_others_and_unlocks(): void
    {
        $actor = $this->user('super_admin');
        $other = $this->user('direksi');
        $month = $this->monthsAgo(2);
        $this->postOn($month);
        $this->service->close($month, $actor->id);
        $this->actingAs($actor, 'web');

        Livewire::test(ClosePeriodPage::class)
            ->callAction('reopenPeriod', data: ['reason' => ''], arguments: $this->args($month))
            ->assertHasActionErrors(['reason' => 'required']);
        $this->assertTrue(AccountingPeriod::isClosedFor($month));

        Livewire::test(ClosePeriodPage::class)
            ->callAction('reopenPeriod', data: ['reason' => 'Ada koreksi'], arguments: $this->args($month))
            ->assertHasNoActionErrors();

        $this->assertFalse(AccountingPeriod::isClosedFor($month));
        $this->assertGreaterThanOrEqual(1, $other->notifications()->count());
        $this->assertSame(['closed', 'reopened'], AccountingPeriodEvent::orderBy('id')->pluck('action')->all());
    }

    public function test_reopen_action_refuses_an_older_period_while_a_newer_one_is_closed(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->postOn($this->monthsAgo(3));
        $this->service->close($this->monthsAgo(3), null);
        $this->service->close($this->monthsAgo(2), null);

        Livewire::test(ClosePeriodPage::class)
            ->callAction('reopenPeriod', data: ['reason' => 'Coba'], arguments: $this->args($this->monthsAgo(3)));

        $this->assertTrue(AccountingPeriod::isClosedFor($this->monthsAgo(3)));
        $this->assertSame(0, AccountingPeriodEvent::where('action', 'reopened')->count());
    }

    public function test_close_several_months_runs_oldest_first_and_stops_at_the_first_failure(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->postOn($this->monthsAgo(3));
        $this->postOn($this->monthsAgo(2));
        $this->postOn($this->monthsAgo(1));

        Livewire::test(ClosePeriodPage::class)
            ->callAction('closeUntil', data: ['until' => $this->monthsAgo(2)->toDateString(), 'acknowledge' => true, 'notes' => 'Massal'])
            ->assertHasNoActionErrors();

        $this->assertTrue(AccountingPeriod::isClosedFor($this->monthsAgo(3)));
        $this->assertTrue(AccountingPeriod::isClosedFor($this->monthsAgo(2)));
        $this->assertFalse(AccountingPeriod::isClosedFor($this->monthsAgo(1)), 'Tidak melewati bulan yang dipilih.');
        $this->assertSame(['Massal', 'Massal'], AccountingPeriod::orderBy('period_month')->pluck('notes')->all());
    }

    public function test_close_several_months_stops_at_a_draft_and_keeps_earlier_closures(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->postOn($this->monthsAgo(3));
        $this->postOn($this->monthsAgo(2), draft: true);

        Livewire::test(ClosePeriodPage::class)
            ->callAction('closeUntil', data: ['until' => $this->monthsAgo(1)->toDateString(), 'acknowledge' => true]);

        $this->assertTrue(AccountingPeriod::isClosedFor($this->monthsAgo(3)));
        $this->assertFalse(AccountingPeriod::isClosedFor($this->monthsAgo(2)));
        $this->assertFalse(AccountingPeriod::isClosedFor($this->monthsAgo(1)));
    }
}
