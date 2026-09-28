<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\ChartOfAccountImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Audit Bagan Akun 2026-09-28: guard integritas ChartOfAccount::booted()
 * (aktif hanya kalau ada user login -- seeder/migrasi/job dilewati) dan
 * ChartOfAccountImportService.
 */
class ChartOfAccountGuardTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $code, string $type = 'beban_operasional', bool $postable = true, ?int $parentId = null): ChartOfAccount
    {
        return ChartOfAccount::create([
            'code' => $code,
            'name' => "Akun {$code}",
            'type' => $type,
            'normal_balance' => ChartOfAccount::normalBalanceFor($type),
            'is_postable' => $postable,
            'is_active' => true,
            'parent_id' => $parentId,
        ]);
    }

    private function postLine(ChartOfAccount $account, float $debit, float $credit, string $status = 'posted'): void
    {
        $entryId = DB::table('journal_entries')->insertGetId([
            'entry_number' => 'JU-' . uniqid(),
            'entry_date' => now()->toDateString(),
            'description' => 'test',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('journal_entry_lines')->insert([
            'journal_entry_id' => $entryId,
            'chart_of_account_id' => $account->id,
            'debit' => $debit,
            'credit' => $credit,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function login(): void
    {
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']));
    }

    public function test_type_cannot_change_once_account_has_journal(): void
    {
        $account = $this->account('6210');
        $this->postLine($account, 100, 0);
        $this->login();

        $this->expectException(RuntimeException::class);
        $account->update(['type' => 'pendapatan']);
    }

    public function test_system_account_cannot_be_deleted_or_deactivated(): void
    {
        $account = $this->account('1101', 'aset');
        $this->login();

        try {
            $account->update(['is_active' => false]);
            $this->fail('Akun sistem tidak boleh dinonaktifkan');
        } catch (RuntimeException) {
            $this->assertTrue($account->fresh()->is_active);
        }

        $this->expectException(RuntimeException::class);
        $account->delete();
    }

    public function test_account_in_use_cannot_be_deleted(): void
    {
        $parent = $this->account('6000', 'beban_operasional', false);
        $this->account('6001', 'beban_operasional', true, $parent->id);
        $this->login();

        $this->expectException(RuntimeException::class);
        $parent->delete();
    }

    public function test_account_with_balance_cannot_be_deactivated_but_zero_balance_can(): void
    {
        $account = $this->account('6210');
        $this->postLine($account, 500, 0);
        $this->postLine($account, 0, 500, 'draft'); // draft tidak dihitung
        $this->login();

        try {
            $account->update(['is_active' => false]);
            $this->fail('Akun bersaldo tidak boleh dinonaktifkan');
        } catch (RuntimeException) {
            $this->assertEquals(500.0, $account->balance());
        }

        $zero = $this->account('6220');
        $zero->update(['is_active' => false]);
        $this->assertFalse($zero->fresh()->is_active);
    }

    public function test_parent_cycle_is_rejected(): void
    {
        $a = $this->account('6000', 'beban_operasional', false);
        $b = $this->account('6001', 'beban_operasional', false, $a->id);
        $this->login();

        $this->expectException(RuntimeException::class);
        $a->update(['parent_id' => $b->id]);
    }

    public function test_guards_are_skipped_without_logged_in_user(): void
    {
        $account = $this->account('1101', 'aset');

        $account->update(['is_active' => false]); // seeder/migrasi/tinker

        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_import_creates_new_accounts_skips_existing_and_is_all_or_nothing(): void
    {
        $this->account('6000', 'beban_operasional', false);

        $ok = app(ChartOfAccountImportService::class)->import([
            ['kode' => '6000', 'nama' => 'Sudah ada', 'klasifikasi' => 'beban_operasional', 'kode_induk' => '', 'postable' => 'tidak'],
            ['kode' => '6010', 'nama' => 'Baru', 'klasifikasi' => 'beban_operasional', 'kode_induk' => '6000', 'postable' => 'ya'],
        ]);
        $this->assertSame(1, $ok['created']);
        $this->assertSame(1, $ok['skipped']);
        $this->assertSame('Akun 6000', ChartOfAccount::where('code', '6000')->value('name'));

        $bad = app(ChartOfAccountImportService::class)->import([
            ['kode' => '6020', 'nama' => 'Valid', 'klasifikasi' => 'beban_operasional'],
            ['kode' => '4999', 'nama' => 'Salah tipe', 'klasifikasi' => 'beban_operasional'],
        ]);
        $this->assertSame(0, $bad['created']);
        $this->assertNotEmpty($bad['errors']);
        $this->assertNull(ChartOfAccount::where('code', '6020')->first());
    }
}
