<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Services\AccountingPeriodService;
use App\Services\JournalEntryService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Audit Tutup Periode 2026-09-29: penutupan berurutan, buka kembali dari yang terbaru, catatan
 * penutupan, dan penolakan bulan berjalan / jurnal draft. (Belum pernah dijalankan lokal -- tidak ada PHP.)
 */
class AccountingPeriodServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccountingPeriodService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);
        $this->service = app(AccountingPeriodService::class);
    }

    private function monthsAgo(int $n): Carbon
    {
        return now()->subMonthsNoOverflow($n)->startOfMonth();
    }

    private function postOn(Carbon $date, bool $draft = false): void
    {
        $journal = app(JournalEntryService::class);
        $entry = $journal->create([
            'entry_date' => $date->copy()->addDays(2)->toDateString(),
            'store_id' => null,
            'description' => 'Uji periode',
            'reference_type' => 'manual',
        ], [
            ['chart_of_account_id' => ChartOfAccount::where('code', '1101')->value('id'), 'debit' => 100_000],
            ['chart_of_account_id' => ChartOfAccount::where('type', 'pendapatan')->where('is_postable', true)->value('id'), 'credit' => 100_000],
        ]);

        if (! $draft) {
            $journal->post($entry, null);
        }
    }

    public function test_cannot_close_current_month(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service->close(now()->startOfMonth(), null);
    }

    public function test_must_close_previous_month_first_when_earlier_journals_exist(): void
    {
        $this->postOn($this->monthsAgo(3));
        $this->postOn($this->monthsAgo(2));

        // Bulan -3 masih terbuka & ada jurnal sebelumnya: menutup bulan -2 harus ditolak.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/berurutan/');

        $this->service->close($this->monthsAgo(2), null);
    }

    public function test_sequential_close_works_and_stores_notes(): void
    {
        $this->postOn($this->monthsAgo(3));
        $this->postOn($this->monthsAgo(2));

        $first = $this->service->close($this->monthsAgo(3), null, 'Tutup buku bulan pertama');
        $this->service->close($this->monthsAgo(2), null);

        $this->assertSame('Tutup buku bulan pertama', $first->fresh()->notes);
        $this->assertTrue(AccountingPeriod::isClosedFor($this->monthsAgo(2)));
    }

    public function test_reopen_must_start_from_newest_closed_period(): void
    {
        $this->postOn($this->monthsAgo(3));
        $older = $this->service->close($this->monthsAgo(3), null);
        $newer = $this->service->close($this->monthsAgo(2), null);

        try {
            $this->service->reopen($older, null, 'Koreksi');
            $this->fail('Membuka periode lama saat yang baru masih tertutup harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('paling baru', $e->getMessage());
        }

        $this->service->reopen($newer, null, 'Koreksi');
        $this->service->reopen($older->fresh(), null, 'Koreksi');

        $this->assertFalse(AccountingPeriod::isClosedFor($this->monthsAgo(3)));
    }

    public function test_close_stores_snapshot_and_history_survives_reopen(): void
    {
        $this->postOn($this->monthsAgo(2));

        $period = $this->service->close($this->monthsAgo(2), null, 'Tutup uji');

        $this->assertSame(1, $period->fresh()->snapshot['journal_count']);
        $this->assertEquals(100_000.0, $period->fresh()->snapshot['total_debit']);
        $this->assertEquals(100_000.0, $period->fresh()->snapshot['net_income']);

        $this->service->reopen($period->fresh(), null, 'Koreksi');

        // Baris periode hilang, tapi riwayat (tutup + buka kembali) tetap ada.
        $this->assertFalse(AccountingPeriod::isClosedFor($this->monthsAgo(2)));
        $this->assertSame(['closed', 'reopened'], \App\Models\AccountingPeriodEvent::orderBy('id')->pluck('action')->all());
    }

    public function test_warnings_require_acknowledgement(): void
    {
        $this->postOn($this->monthsAgo(2));

        // Mutasi rekening koran yang belum dicocokkan di bulan itu = peringatan (bukan pemblokir).
        \App\Models\BankStatementLine::create([
            'chart_of_account_id' => ChartOfAccount::where('code', '1102')->value('id'),
            'statement_date' => $this->monthsAgo(2)->addDays(3)->toDateString(),
            'description' => 'Setoran belum dicocokkan',
            'amount' => 50_000,
            'status' => 'unmatched',
        ]);

        $items = $this->service->checklist($this->monthsAgo(2));
        $this->assertSame('warn', collect($items)->firstWhere('key', 'bank')['severity']);

        try {
            $this->service->close($this->monthsAgo(2), null);
            $this->fail('Peringatan yang belum dikonfirmasi harus menolak penutupan.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('dikonfirmasi', $e->getMessage());
        }

        // Rollback: periode belum tertutup setelah penolakan.
        $this->assertFalse(AccountingPeriod::isClosedFor($this->monthsAgo(2)));

        $period = $this->service->close($this->monthsAgo(2), null, null, true);
        $this->assertContains('bank', $period->fresh()->snapshot['acknowledged_warnings']);
    }

    public function test_draft_journal_blocks_closing(): void
    {
        $this->postOn($this->monthsAgo(2), true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Draft/');

        $this->service->close($this->monthsAgo(2), null);
    }
}
