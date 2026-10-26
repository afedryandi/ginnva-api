<?php

namespace Tests\Feature;

use App\Filament\Resources\BankStatementLineResource\Pages\ListBankStatementLines;
use App\Models\AccountingPeriod;
use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\JournalEntry;
use App\Models\PaymentAccount;
use App\Models\Store;
use App\Models\User;
use App\Services\BankLineBookingService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Catat sebagai Transaksi" di Rekonsiliasi Bank: mutasi yang belum ada jurnalnya (biaya admin, bunga) menjadi Transaksi Keuangan
 * yang diposting dan langsung dicocokkan, dalam satu transaksi basis data. "Hari ini" dibekukan di 20 Oktober 2026.
 */
class BankLineBookingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private ChartOfAccount $bank;
    private FinanceCategory $fee;
    private FinanceCategory $interest;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'kasir'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->bank = ChartOfAccount::where('code', '1102')->firstOrFail();
        PaymentAccount::create(['name' => 'BCA Utama', 'kind' => 'bank', 'chart_of_account_id' => $this->bank->id, 'store_id' => null, 'methods' => ['transfer'], 'is_active' => true]);
        $this->fee = FinanceCategory::create(['name' => 'Biaya Admin Bank', 'type' => 'out', 'chart_of_account_id' => ChartOfAccount::where('code', '6410')->value('id'), 'is_active' => true]);
        $this->interest = FinanceCategory::create(['name' => 'Bunga Bank', 'type' => 'in', 'chart_of_account_id' => ChartOfAccount::where('code', '7100')->value('id'), 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]), fn (User $u) => $u->assignRole('super_admin'));
    }

    private function line(float $amount, string $description = 'BIAYA ADM', string $date = '2026-10-15'): BankStatementLine
    {
        return BankStatementLine::create(['chart_of_account_id' => $this->bank->id, 'statement_date' => $date, 'description' => $description, 'amount' => $amount, 'status' => 'unmatched']);
    }

    private function book(BankStatementLine $line, FinanceCategory $category, ?string $description = null)
    {
        return app(BankLineBookingService::class)->book($line, $category->id, $this->store->id, $description, $this->admin()->id);
    }

    public function test_money_out_becomes_an_expense_posted_and_matched(): void
    {
        $line = $this->line(-6500);

        $transaction = $this->book($line, $this->fee);

        $this->assertSame('out', $transaction->type);
        $this->assertEquals(6500, (float) $transaction->amount);
        $this->assertSame('2026-10-15', $transaction->transaction_date->toDateString(), 'Tanggal mengikuti mutasi bank.');
        $this->assertSame('BIAYA ADM', $transaction->description, 'Tanpa keterangan baru, memakai keterangan mutasi.');

        $entry = JournalEntry::with('lines')->findOrFail($transaction->fresh()->journal_entry_id);
        $this->assertTrue($entry->isPosted());
        $this->assertTrue($entry->isBalanced());
        $this->assertEquals(6500, (float) $entry->lines->firstWhere('chart_of_account_id', $this->bank->id)->credit, 'Uang keluar mengkredit bank.');

        $line->refresh();
        $this->assertSame('matched', $line->status);
        $this->assertSame($entry->lines->firstWhere('chart_of_account_id', $this->bank->id)->id, $line->matched_journal_entry_line_id);
    }

    public function test_money_in_becomes_an_income_posted_and_matched(): void
    {
        $line = $this->line(12000, 'BUNGA');

        $transaction = $this->book($line, $this->interest, 'Bunga September');

        $this->assertSame('in', $transaction->type);
        $this->assertSame('Bunga September', $transaction->description);
        $entry = JournalEntry::with('lines')->findOrFail($transaction->fresh()->journal_entry_id);
        $this->assertEquals(12000, (float) $entry->lines->firstWhere('chart_of_account_id', $this->bank->id)->debit, 'Uang masuk mendebit bank.');
        $this->assertSame('matched', $line->fresh()->status);
    }

    public function test_the_category_type_must_follow_the_direction_of_the_money(): void
    {
        $line = $this->line(-6500);

        try {
            $this->book($line, $this->interest);
            $this->fail('Kategori pemasukan untuk uang keluar harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pengeluaran', $e->getMessage());
        }

        $this->assertSame('unmatched', $line->fresh()->status);
        $this->assertSame(0, FinanceTransaction::count());
    }

    public function test_an_inactive_category_is_rejected(): void
    {
        $line = $this->line(-6500);
        $this->fee->update(['is_active' => false]);

        $this->expectException(RuntimeException::class);
        $this->book($line, $this->fee);
    }

    public function test_an_already_processed_line_is_rejected(): void
    {
        $line = $this->line(-6500);
        $this->book($line, $this->fee);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah diproses');
        $this->book($line->fresh(), $this->fee);
    }

    public function test_without_a_registered_payment_account_nothing_is_saved(): void
    {
        PaymentAccount::query()->delete();
        $line = $this->line(-6500);

        try {
            $this->book($line, $this->fee);
            $this->fail('Harus gagal tanpa rekening terdaftar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('rekening', $e->getMessage());
        }

        $this->assertSame('unmatched', $line->fresh()->status);
        $this->assertSame(0, FinanceTransaction::count());
    }

    public function test_a_closed_period_rolls_everything_back(): void
    {
        $line = $this->line(-6500, date: '2026-09-15');
        AccountingPeriod::create(['period_month' => '2026-09-01', 'closed_by' => $this->admin()->id, 'closed_at' => now()]);

        try {
            $this->book($line, $this->fee);
            $this->fail('Harus gagal di periode tertutup.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ditutup', $e->getMessage());
        }

        $this->assertSame('unmatched', $line->fresh()->status);
        $this->assertSame(0, FinanceTransaction::count(), 'Tidak ada transaksi setengah jadi.');
        $this->assertSame(0, JournalEntry::where('reference_type', 'finance_transaction')->count());
    }

    public function test_the_button_is_for_full_access_and_unmatched_lines_only(): void
    {
        $this->actingAs($this->admin(), 'web');
        $open = $this->line(-6500);
        $done = $this->line(-1000, 'SUDAH');
        $done->update(['status' => 'ignored']);

        Livewire::test(ListBankStatementLines::class)
            ->assertTableActionVisible('book', $open)
            ->assertTableActionHidden('book', $done)
            ->callTableAction('book', $open, ['finance_category_id' => $this->fee->id, 'store_id' => $this->store->id, 'description' => 'Biaya admin Oktober'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('matched', $open->fresh()->status);
        $this->assertSame('Biaya admin Oktober', FinanceTransaction::firstOrFail()->description);
    }
}
