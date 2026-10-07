<?php

namespace Tests\Feature;

use App\Filament\Resources\BankStatementLineResource;
use App\Filament\Resources\BankStatementLineResource\Pages\ListBankStatementLines;
use App\Models\BankStatementImportBatch;
use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\BankReconciliationService;
use App\Services\JournalEntryService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Rekonsiliasi Bank (melengkapi BankReconciliationServiceTest): aturan di service yang tidak boleh
 * hanya bergantung pada dropdown (jurnal draft, arah debit/kredit, akun non-kas, status yang
 * salah), impor file CSV, serta layar admin: aksi tabel, filter, ringkasan, ekspor, dan izin.
 */
class BankStatementLineResourceTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccount $cash;
    private ChartOfAccount $revenue;
    private BankReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('spv_finance', 'web');
        Role::findOrCreate('kasir', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->cash = ChartOfAccount::where('code', '1102')->firstOrFail();
        $this->revenue = ChartOfAccount::where('type', 'pendapatan')->where('is_postable', true)->firstOrFail();
        $this->service = app(BankReconciliationService::class);
    }

    private function user(string $role, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x'], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function admin(): User
    {
        return $this->user('super_admin');
    }

    /** Jurnal kas: debit (uang masuk) atau kredit (uang keluar). */
    private function journal(float $amount, string $date = '2026-09-05', bool $incoming = true, bool $post = true): JournalEntry
    {
        $svc = app(JournalEntryService::class);
        $entry = $svc->create(['entry_date' => $date, 'description' => 'Uji', 'reference_type' => 'manual'], [
            ['chart_of_account_id' => $this->cash->id, $incoming ? 'debit' : 'credit' => $amount],
            ['chart_of_account_id' => $this->revenue->id, $incoming ? 'credit' : 'debit' => $amount],
        ]);

        return $post ? $svc->post($entry, null) : $entry;
    }

    private function cashLine(JournalEntry $entry)
    {
        return $entry->lines()->where('chart_of_account_id', $this->cash->id)->firstOrFail();
    }

    private function statement(float $amount = 100000, string $status = 'unmatched', string $date = '2026-09-05', string $description = 'Mutasi'): BankStatementLine
    {
        return BankStatementLine::create([
            'chart_of_account_id' => $this->cash->id, 'statement_date' => $date,
            'description' => $description, 'amount' => $amount, 'status' => $status,
        ]);
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

    public function test_matching_requires_a_posted_journal_in_the_same_direction(): void
    {
        $draft = $this->journal(100000, post: false);
        $outgoing = $this->journal(100000, incoming: false);
        $line = $this->statement(100000);

        $this->assertRefused(fn () => $this->service->match($line, $this->cashLine($draft)), 'sudah diposting');
        $this->assertRefused(fn () => $this->service->match($line, $this->cashLine($outgoing)), 'berlawanan');
        $this->assertSame('unmatched', $line->fresh()->status);

        $incoming = $this->journal(100000);
        $this->service->match($line, $this->cashLine($incoming));
        $this->assertSame('matched', $line->fresh()->status);
        $this->assertSame($this->cashLine($incoming)->id, $line->fresh()->matched_journal_entry_line_id);
    }

    public function test_outgoing_statement_matches_a_credit_line(): void
    {
        $line = $this->statement(-40000);

        $this->service->match($line, $this->cashLine($this->journal(40000, incoming: false)));

        $this->assertSame('matched', $line->fresh()->status);
    }

    public function test_matching_rejects_other_accounts_and_already_processed_statements(): void
    {
        $entry = $this->journal(100000);
        $revenueLine = $entry->lines()->where('chart_of_account_id', $this->revenue->id)->firstOrFail();
        $line = $this->statement();

        $this->assertRefused(fn () => $this->service->match($line, $revenueLine), 'bukan dari akun yang sama');

        $this->service->match($line, $this->cashLine($entry));
        $this->assertRefused(fn () => $this->service->match($line->fresh(), $this->cashLine($this->journal(100000))), 'sudah diproses');
    }

    public function test_status_transitions_are_guarded(): void
    {
        $matched = $this->statement(100000, 'matched');
        $ignored = $this->statement(5000, 'ignored', description: 'Admin');
        $open = $this->statement(7000, description: 'Lain');

        $this->assertRefused(fn () => $this->service->ignore($matched), 'Belum Cocok');
        $this->assertRefused(fn () => $this->service->ignore($ignored), 'Belum Cocok');
        $this->assertRefused(fn () => $this->service->unmatch($open), 'Cocok');
        $this->assertRefused(fn () => $this->service->unmatch($ignored), 'Cocok');
        $this->assertRefused(fn () => $this->service->unignore($open), 'Diabaikan');
    }

    public function test_unmatch_frees_the_journal_line_to_be_matched_again(): void
    {
        $line = $this->statement();
        $journalLine = $this->cashLine($this->journal(100000));
        $this->service->match($line, $journalLine);

        $this->service->unmatch($line->fresh());

        $fresh = $line->fresh();
        $this->assertSame('unmatched', $fresh->status);
        $this->assertNull($fresh->matched_journal_entry_line_id);

        $other = $this->statement(100000, description: 'Kedua');
        $this->service->match($other, $journalLine);
        $this->assertSame('matched', $other->fresh()->status);
    }

    public function test_auto_match_pairs_a_single_exact_candidate_and_respects_date_amount_and_direction(): void
    {
        $entry = $this->journal(250000, '2026-09-10');
        $this->journal(90000, '2026-09-10', incoming: false);

        $exact = $this->statement(250000, date: '2026-09-10', description: 'Pas');
        $otherDate = $this->statement(250000, date: '2026-09-11', description: 'Beda tanggal');
        $otherAmount = $this->statement(250001, date: '2026-09-10', description: 'Beda nominal');
        $wrongSign = $this->statement(-250000, date: '2026-09-10', description: 'Beda arah');

        $this->assertSame(1, $this->service->autoMatch($this->cash));

        $this->assertSame('matched', $exact->fresh()->status);
        $this->assertSame($this->cashLine($entry)->id, $exact->fresh()->matched_journal_entry_line_id);
        foreach ([$otherDate, $otherAmount, $wrongSign] as $untouched) {
            $this->assertSame('unmatched', $untouched->fresh()->status);
        }
    }

    public function test_auto_match_never_uses_draft_journals_or_lines_already_taken(): void
    {
        $this->journal(60000, post: false);
        $taken = $this->journal(70000);
        $this->service->match($this->statement(70000, description: 'Sudah'), $this->cashLine($taken));

        $a = $this->statement(60000, description: 'Draft');
        $b = $this->statement(70000, description: 'Duplikat');

        $this->assertSame(0, $this->service->autoMatch($this->cash));
        $this->assertSame('unmatched', $a->fresh()->status);
        $this->assertSame('unmatched', $b->fresh()->status);
    }

    public function test_import_is_limited_to_cash_accounts_and_stores_amounts_and_batch(): void
    {
        $this->assertRefused(fn () => $this->service->importRows([['date' => '2026-09-05', 'description' => 'X', 'amount' => 1]], $this->revenue, null), 'akun kas/bank');
        $this->assertSame(0, BankStatementLine::count());

        $result = $this->service->importRows([
            ['date' => '2026-09-05', 'description' => 'Setoran', 'amount' => 100000.5, 'reference' => 'TRX1'],
            ['date' => '2026-09-06', 'description' => 'Biaya admin', 'amount' => -6500],
        ], $this->cash, null);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, BankStatementLine::where('import_batch', $result['batch'])->where('status', 'unmatched')->count());
        $this->assertEquals(100000.5, BankStatementLine::where('description', 'Setoran')->value('amount'));
        $this->assertSame('TRX1', BankStatementLine::where('description', 'Setoran')->value('external_reference'));
    }

    public function test_duplicates_are_detected_per_account_not_across_accounts(): void
    {
        $row = [['date' => '2026-09-05', 'description' => 'Setoran', 'amount' => 50000]];
        $bankB = ChartOfAccount::create(['code' => '1103', 'name' => 'Bank B', 'type' => 'aset', 'normal_balance' => 'debit', 'is_postable' => true, 'is_active' => true, 'is_cash' => true]);

        $this->service->importRows($row, $this->cash, null);
        $other = $this->service->importRows($row, $bankB, null);

        $this->assertSame(1, $other['imported'], 'Akun lain boleh punya mutasi yang sama.');
        $this->assertSame(2, BankStatementLine::count());
    }

    public function test_stale_marker_is_cleared_when_the_line_is_rematched_or_unmatched(): void
    {
        $entry = $this->journal(200000);
        $line = $this->statement(200000);
        $this->service->match($line, $this->cashLine($entry));
        app(JournalEntryService::class)->reverse($entry->fresh(), null, 'Koreksi');
        $this->assertNotNull($line->fresh()->stale_at);

        $this->service->unmatch($line->fresh());

        $this->assertNull($line->fresh()->stale_at);
        $this->assertSame('unmatched', $line->fresh()->status);
    }

    // ------------------------------------------------------------- impor file

    private function importFile(string $contents, string $name = 'mutasi.csv'): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('bank-statement-imports/' . $name, $contents);

        $method = new ReflectionMethod(BankStatementLineResource::class, 'importFile');
        $method->setAccessible(true);
        $method->invoke(null, $this->cash->id, 'bank-statement-imports/' . $name);
    }

    public function test_csv_import_skips_header_counts_invalid_rows_and_archives_the_file(): void
    {
        $this->actingAs($this->admin(), 'web');

        $this->importFile(implode("\n", [
            'Tanggal,Keterangan,Nominal',
            '2026-09-05,Setoran tunai,150000',
            '2026-09-06,Biaya admin,-6500',
            '2026-09-07,Nominal rusak,abc',
            'bukan-tanggal,Tanggal rusak,1000',
            '2026-09-05,Setoran tunai,150000',
        ]));

        $this->assertSame(2, BankStatementLine::count());
        $this->assertEquals(-6500, BankStatementLine::where('description', 'Biaya admin')->value('amount'));

        $batch = BankStatementImportBatch::firstOrFail();
        $this->assertSame(2, $batch->imported_count);
        $this->assertSame(1, $batch->duplicate_count);
        $this->assertSame(2, $batch->invalid_count);
        Storage::disk('local')->assertExists($batch->archived_path);
        Storage::disk('local')->assertMissing('bank-statement-imports/mutasi.csv');
    }

    public function test_importing_the_same_file_twice_adds_nothing(): void
    {
        $this->actingAs($this->admin(), 'web');
        $csv = "Tanggal,Keterangan,Nominal\n2026-09-05,Setoran,50000\n";

        $this->importFile($csv, 'a.csv');
        $this->importFile($csv, 'b.csv');

        $this->assertSame(1, BankStatementLine::count());
        $this->assertSame(2, BankStatementImportBatch::count());
    }

    // ------------------------------------------------------------- layar admin

    public function test_table_actions_match_unmatch_ignore_and_unignore(): void
    {
        $this->actingAs($this->admin(), 'web');
        $entry = $this->journal(100000);
        $line = $this->statement();
        $journalLineId = $this->cashLine($entry)->id;

        Livewire::test(ListBankStatementLines::class)
            ->assertTableActionVisible('match', $line)
            ->assertTableActionHidden('unmatch', $line)
            ->callTableAction('match', $line, data: ['journal_entry_line_id' => $journalLineId]);
        $this->assertSame('matched', $line->fresh()->status);

        Livewire::test(ListBankStatementLines::class)
            ->filterTable('status', 'matched')
            ->assertTableActionHidden('match', $line->fresh())
            ->assertTableActionHidden('ignore', $line->fresh())
            ->assertTableActionHidden('delete', $line->fresh())
            ->callTableAction('unmatch', $line->fresh());
        $this->assertSame('unmatched', $line->fresh()->status);

        Livewire::test(ListBankStatementLines::class)->callTableAction('ignore', $line->fresh());
        $this->assertSame('ignored', $line->fresh()->status);

        Livewire::test(ListBankStatementLines::class)
            ->filterTable('status', 'ignored')
            ->assertTableActionVisible('unignore', $line->fresh())
            ->callTableAction('unignore', $line->fresh());
        $this->assertSame('unmatched', $line->fresh()->status);
    }

    public function test_a_failed_match_from_the_table_is_reported_without_changing_the_statement(): void
    {
        $this->actingAs($this->admin(), 'web');
        $draftLine = $this->cashLine($this->journal(100000, post: false));
        $line = $this->statement();

        Livewire::test(ListBankStatementLines::class)
            ->callTableAction('match', $line, data: ['journal_entry_line_id' => $draftLine->id]);

        $this->assertSame('unmatched', $line->fresh()->status);
        $this->assertNull($line->fresh()->matched_journal_entry_line_id);
    }

    public function test_default_filter_shows_unmatched_and_filters_work(): void
    {
        $this->actingAs($this->admin(), 'web');
        $open = $this->statement(1000, description: 'Terbuka');
        $done = $this->statement(2000, 'matched', description: 'Selesai');
        $skip = $this->statement(3000, 'ignored', description: 'Lewat');
        $stale = $this->statement(4000, 'matched', description: 'Basi');
        $stale->update(['stale_at' => now()]);

        Livewire::test(ListBankStatementLines::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$done, $skip, $stale]);
        Livewire::test(ListBankStatementLines::class)->filterTable('status', 'matched')
            ->assertCanSeeTableRecords([$done, $stale])->assertCanNotSeeTableRecords([$open, $skip]);
        Livewire::test(ListBankStatementLines::class)->filterTable('status', null)->filterTable('stale', true)
            ->assertCanSeeTableRecords([$stale])->assertCanNotSeeTableRecords([$open, $done, $skip]);
        Livewire::test(ListBankStatementLines::class)->searchTable('Terbuka')->assertCanSeeTableRecords([$open]);
    }

    public function test_auto_match_action_reports_through_the_service(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->journal(88000);
        $line = $this->statement(88000);

        Livewire::test(ListBankStatementLines::class)
            ->callTableAction('auto_match', data: ['chart_of_account_id' => $this->cash->id])
            ->assertHasNoTableActionErrors();

        $this->assertSame('matched', $line->fresh()->status);
    }

    public function test_reconciliation_export_downloads_and_the_report_numbers_are_right(): void
    {
        $this->actingAs($this->admin(), 'web');
        $entry = $this->journal(100000, '2026-09-05');
        $this->service->match($this->statement(100000), $this->cashLine($entry));
        $this->statement(-5000, date: '2026-09-06', description: 'Belum');
        $this->statement(9999, date: '2026-10-20', description: 'Luar periode');

        $method = new ReflectionMethod(BankStatementLineResource::class, 'buildReconciliationReport');
        $method->setAccessible(true);
        $report = $method->invoke(null, $this->cash->id, '2026-09-01', '2026-09-30');

        $this->assertCount(2, $report['lines']);
        $this->assertSame(1, $report['matched_count']);
        $this->assertSame(1, $report['unmatched_count']);
        $this->assertEquals(5000, $report['unmatched_total']);
        $this->assertEquals(100000, $report['system_balance']);
        $this->assertSame($entry->entry_number, $report['lines']->first()['journal_entry_number']);

        Excel::fake();
        Livewire::test(ListBankStatementLines::class)->callTableAction('exportReconciliation', data: [
            'chart_of_account_id' => $this->cash->id, 'from' => '2026-09-01', 'to' => '2026-09-30',
        ]);
        Excel::assertDownloaded(fn (string $name) => str_starts_with($name, 'rekonsiliasi-bank-') && str_ends_with($name, '.xlsx'));
    }

    public function test_pdf_export_and_import_history_render(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->service->importRows([['date' => '2026-09-05', 'description' => 'Setoran', 'amount' => 1000]], $this->cash, null, ['original_filename' => 'mutasi-sept.csv']);

        Livewire::test(ListBankStatementLines::class)
            ->callTableAction('exportReconciliationPdf', data: ['chart_of_account_id' => $this->cash->id, 'from' => '2026-09-01', 'to' => '2026-09-30'])
            ->assertHasNoTableActionErrors();

        Livewire::test(ListBankStatementLines::class)->mountTableAction('importHistory')->assertSee('mutasi-sept.csv');
    }

    public function test_access_is_full_access_or_a_finance_supervisor_with_a_menu_grant(): void
    {
        $line = $this->statement();

        $this->actingAs($this->admin(), 'web');
        $this->assertTrue(BankStatementLineResource::canViewAny());
        $this->assertTrue(BankStatementLineResource::canDelete($line));
        $this->assertFalse(BankStatementLineResource::canEdit($line));

        $this->actingAs($this->user('kasir'), 'web');
        $this->assertFalse((bool) BankStatementLineResource::canViewAny());

        $this->actingAs($this->user('spv_finance'), 'web');
        $this->assertFalse((bool) BankStatementLineResource::canViewAny(), 'Tanpa akses menu eksplisit.');

        $spv = $this->user('spv_finance', ['menu_permissions' => [BankStatementLineResource::class => []]]);
        $this->actingAs($spv, 'web');
        $this->assertTrue((bool) BankStatementLineResource::canViewAny());
        $this->assertFalse((bool) BankStatementLineResource::canDelete($line), 'Hapus tidak diberikan default.');

        $spv->update(['menu_permissions' => [BankStatementLineResource::class => ['delete']]]);
        $this->actingAs($spv->fresh(), 'web');
        $this->assertTrue((bool) BankStatementLineResource::canDelete($line));
    }

    public function test_matched_statement_changes_are_written_to_the_activity_log(): void
    {
        $line = $this->statement();
        $this->service->match($line, $this->cashLine($this->journal(100000)));

        $this->assertTrue(
            \Spatie\Activitylog\Models\Activity::where('log_name', 'bank_statement_line')->where('subject_id', $line->id)->exists()
        );
    }
}
