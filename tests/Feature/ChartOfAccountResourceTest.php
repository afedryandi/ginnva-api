<?php

namespace Tests\Feature;

use App\Filament\Resources\ChartOfAccountResource;
use App\Filament\Resources\ChartOfAccountResource\Pages\CreateChartOfAccount;
use App\Filament\Resources\ChartOfAccountResource\Pages\EditChartOfAccount;
use App\Filament\Resources\ChartOfAccountResource\Pages\ListChartOfAccounts;
use App\Models\ChartOfAccount;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bagan Akun (layar admin): daftar & filter, buat akun (kode 4-6 digit unik,
 * digit pertama sesuai klasifikasi, saldo normal otomatis), induk harus akun
 * header berklasifikasi sama, kunci pada akun sistem / yang sudah berjurnal
 * (juga bila nilai dikirim langsung), nonaktifkan hanya bila saldo nol, hapus
 * hanya akun yang belum dipakai, ekspor, dan izin per aksi. Aturan inti model
 * sudah diuji di ChartOfAccountGuardTest.
 */
class ChartOfAccountResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('spv_finance', 'web');
        Role::findOrCreate('kasir', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(string $role, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x'], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function asAdmin(): User
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');

        return $admin;
    }

    private function account(string $code, string $type = 'beban_operasional', array $extra = []): ChartOfAccount
    {
        return ChartOfAccount::create(array_merge([
            'code' => $code, 'name' => "Akun {$code}", 'type' => $type, 'normal_balance' => ChartOfAccount::normalBalanceFor($type),
            'is_postable' => true, 'is_active' => true,
        ], $extra));
    }

    private function form(array $overrides = []): array
    {
        return array_merge(['code' => '6480', 'name' => 'Beban Uji', 'type' => 'beban_operasional', 'is_postable' => true, 'is_active' => true], $overrides);
    }

    private function journal(ChartOfAccount $account, float $debit, float $credit): void
    {
        $entryId = DB::table('journal_entries')->insertGetId([
            'entry_number' => 'JU-' . uniqid(), 'entry_date' => now()->toDateString(), 'description' => 'uji', 'status' => 'posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_entry_lines')->insert([
            'journal_entry_id' => $entryId, 'chart_of_account_id' => $account->id, 'debit' => $debit, 'credit' => $credit,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------- daftar

    public function test_list_renders_with_balances_and_filters(): void
    {
        $this->asAdmin();
        // Kode unik jauh dari seeder: daftar 10 baris/halaman dan ratusan akun seeder, jadi cari dulu '69'.
        $expense = $this->account('6970');
        $off = $this->account('6971', 'beban_operasional', ['is_active' => false]);
        $header = $this->account('6960', 'beban_operasional', ['is_postable' => false]);
        $this->journal($expense, 250000, 0);

        $row = ChartOfAccountResource::getEloquentQuery()->findOrFail($expense->id);

        Livewire::test(ListChartOfAccounts::class)->assertSuccessful()
            ->searchTable('69')
            ->assertCanSeeTableRecords([$expense, $off, $header])
            ->assertTableColumnStateSet('saldo', 250000.0, $row)
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$off])->assertCanNotSeeTableRecords([$expense]);

        Livewire::test(ListChartOfAccounts::class)->searchTable('69')->filterTable('is_postable', false)
            ->assertCanSeeTableRecords([$header])->assertCanNotSeeTableRecords([$expense]);
        Livewire::test(ListChartOfAccounts::class)->searchTable('69')->filterTable('type', 'aset')
            ->assertCanNotSeeTableRecords([$expense, $off, $header]);
    }

    public function test_the_balance_shown_follows_the_normal_balance_of_the_account(): void
    {
        $this->asAdmin();
        $liability = $this->account('2900', 'kewajiban');
        $this->journal($liability, 0, 400000);

        $row = ChartOfAccountResource::getEloquentQuery()->findOrFail($liability->id);

        Livewire::test(ListChartOfAccounts::class)->assertTableColumnStateSet('saldo', 400000.0, $row);
    }

    // ------------------------------------------------------------- buat

    public function test_admin_creates_an_account_and_the_normal_balance_follows_the_type(): void
    {
        $this->asAdmin();

        Livewire::test(CreateChartOfAccount::class)->fillForm($this->form())->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateChartOfAccount::class)->fillForm($this->form(['code' => '2900', 'type' => 'kewajiban', 'name' => 'Hutang Uji']))->call('create')->assertHasNoFormErrors();

        $this->assertSame('debit', ChartOfAccount::where('code', '6480')->value('normal_balance'));
        $this->assertSame('kredit', ChartOfAccount::where('code', '2900')->value('normal_balance'));
        $this->assertTrue(Activity::where('log_name', 'chart_of_account')->where('event', 'created')->where('description', 'like', '%6480%')->exists());
    }

    public function test_the_code_must_be_unique_numeric_and_start_with_the_digit_of_its_classification(): void
    {
        $this->asAdmin();
        $create = fn (array $over) => Livewire::test(CreateChartOfAccount::class)->fillForm($this->form($over))->call('create');

        $create(['code' => '6210'])->assertHasFormErrors(['code']);                       // sudah ada
        $create(['code' => '64'])->assertHasFormErrors(['code']);                         // terlalu pendek
        $create(['code' => '64A0'])->assertHasFormErrors(['code']);                       // bukan angka
        $create(['code' => '1999'])->assertHasFormErrors(['code']);                       // digit 1 = Aset, bukan Beban Operasional
        $create(['code' => '6481', 'type' => 'aset'])->assertHasFormErrors(['code']);     // digit 6 bukan Aset
        $create(['name' => ''])->assertHasFormErrors(['name' => 'required']);
        $create(['type' => null])->assertHasFormErrors(['type' => 'required']);

        $this->assertNull(ChartOfAccount::where('name', 'Beban Uji')->first());
    }

    public function test_digit_seven_serves_both_other_income_and_other_expense(): void
    {
        $this->asAdmin();

        Livewire::test(CreateChartOfAccount::class)->fillForm($this->form(['code' => '7310', 'type' => 'pendapatan_lain', 'name' => 'Lain A']))->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateChartOfAccount::class)->fillForm($this->form(['code' => '7410', 'type' => 'beban_lain', 'name' => 'Lain B']))->call('create')->assertHasNoFormErrors();

        $this->assertSame(2, ChartOfAccount::whereIn('code', ['7310', '7410'])->count());
    }

    // ------------------------------------------------------------- induk

    public function test_a_parent_must_be_a_header_of_the_same_classification_even_when_sent_directly(): void
    {
        $this->asAdmin();
        $header = $this->account('6000', 'beban_operasional', ['is_postable' => false]);
        $detail = $this->account('6481');
        $otherTypeHeader = $this->account('2000', 'kewajiban', ['is_postable' => false]);

        Livewire::test(CreateChartOfAccount::class)->fillForm($this->form(['parent_id' => $header->id]))->call('create')->assertHasNoFormErrors();
        $this->assertSame($header->id, ChartOfAccount::where('code', '6480')->value('parent_id'));

        foreach ([['6482', $detail->id], ['6483', $otherTypeHeader->id]] as [$code, $badParent]) {
            Livewire::test(CreateChartOfAccount::class)->fillForm($this->form(['code' => $code, 'parent_id' => $badParent]))->call('create');
            $this->assertNull(ChartOfAccount::where('code', $code)->first(), "Induk {$badParent} tidak sah untuk {$code}.");
        }
    }

    public function test_changing_to_an_invalid_parent_through_the_model_is_refused(): void
    {
        $this->asAdmin();
        $account = $this->account('6480');
        $detail = $this->account('6481');

        $this->expectException(RuntimeException::class);
        $account->update(['parent_id' => $detail->id]);
    }

    // ------------------------------------------------------------- ubah

    public function test_name_description_and_flags_of_a_normal_account_can_be_edited(): void
    {
        $this->asAdmin();
        $account = $this->account('6480');

        Livewire::test(EditChartOfAccount::class, ['record' => $account->getKey()])
            ->fillForm(['name' => 'Beban Baru', 'description' => 'Keterangan', 'is_cash' => false, 'is_contra' => true, 'cash_flow_category' => 'operasional'])
            ->call('save')->assertHasNoFormErrors();

        $fresh = $account->fresh();
        $this->assertSame('Beban Baru', $fresh->name);
        $this->assertTrue($fresh->is_contra);
        $this->assertSame('operasional', $fresh->cash_flow_category);
        $this->assertTrue(Activity::where('log_name', 'chart_of_account')->where('subject_id', $account->id)->where('event', 'updated')->exists());
    }

    public function test_the_code_cannot_be_changed_even_when_a_new_one_is_sent(): void
    {
        $this->asAdmin();
        $account = $this->account('6480');

        Livewire::test(EditChartOfAccount::class, ['record' => $account->getKey()])->fillForm(['code' => '6999'])->call('save');

        $this->assertSame('6480', $account->fresh()->code);
    }

    public function test_system_accounts_cannot_be_reclassified_or_deactivated_even_with_a_crafted_request(): void
    {
        $this->asAdmin();
        $cash = ChartOfAccount::where('code', '1101')->firstOrFail();

        Livewire::test(EditChartOfAccount::class, ['record' => $cash->getKey()])
            ->fillForm(['type' => 'beban_operasional', 'is_active' => false, 'is_postable' => false])
            ->call('save');

        $fresh = $cash->fresh();
        $this->assertSame('aset', $fresh->type);
        $this->assertTrue($fresh->is_active);
        $this->assertTrue($fresh->is_postable);
    }

    public function test_an_account_with_a_journal_keeps_its_classification(): void
    {
        $this->asAdmin();
        $account = $this->account('6480');
        $this->journal($account, 1000, 0);

        Livewire::test(EditChartOfAccount::class, ['record' => $account->getKey()])->fillForm(['type' => 'aset'])->call('save');

        $this->assertSame('beban_operasional', $account->fresh()->type, 'Laporan historis tidak boleh berubah.');
    }

    public function test_deactivating_needs_a_zero_balance(): void
    {
        $this->asAdmin();
        $withBalance = $this->account('6480');
        $this->journal($withBalance, 5000, 0);
        $zero = $this->account('6481');
        $this->journal($zero, 5000, 0);
        $this->journal($zero, 0, 5000);

        Livewire::test(EditChartOfAccount::class, ['record' => $withBalance->getKey()])->fillForm(['is_active' => false])->call('save');
        Livewire::test(EditChartOfAccount::class, ['record' => $zero->getKey()])->fillForm(['is_active' => false])->call('save')->assertHasNoFormErrors();

        $this->assertTrue($withBalance->fresh()->is_active);
        $this->assertFalse($zero->fresh()->is_active);
    }

    // ------------------------------------------------------------- hapus

    public function test_only_unused_custom_accounts_can_be_deleted(): void
    {
        $this->asAdmin();
        $free = $this->account('6480');
        $used = $this->account('6481');
        $this->journal($used, 1000, 0);
        $system = ChartOfAccount::where('code', '1101')->firstOrFail();
        $parent = $this->account('6000', 'beban_operasional', ['is_postable' => false]);
        $this->account('6001', 'beban_operasional', ['parent_id' => $parent->id]);

        foreach ([$used, $system, $parent] as $blocked) {
            $this->assertFalse((bool) ChartOfAccountResource::canDelete($blocked), "{$blocked->code} tidak boleh dihapus.");
        }
        $this->assertTrue((bool) ChartOfAccountResource::canDelete($free));

        Livewire::test(ListChartOfAccounts::class)->assertTableActionHidden('delete', $used)->callTableAction('delete', $free);

        $this->assertNull(ChartOfAccount::find($free->id));
        $this->assertNotNull(ChartOfAccount::find($used->id));
    }

    // ------------------------------------------------------------- ekspor & izin

    public function test_the_export_action_downloads_a_dated_workbook(): void
    {
        $this->asAdmin();
        Excel::fake();

        Livewire::test(ListChartOfAccounts::class)->callTableAction('exportAccounts');

        Excel::assertDownloaded('bagan-akun-' . now()->format('Ymd') . '.xlsx');
    }

    public function test_access_is_full_access_or_a_finance_supervisor_with_explicit_grants(): void
    {
        $account = $this->account('6480');

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(ChartOfAccountResource::canViewAny());
        $this->assertTrue(ChartOfAccountResource::canCreate());
        $this->assertTrue(ChartOfAccountResource::canEdit($account));

        $this->actingAs($this->user('kasir'), 'web');
        $this->assertFalse((bool) ChartOfAccountResource::canViewAny());

        $this->actingAs($this->user('spv_finance'), 'web');
        $this->assertTrue((bool) ChartOfAccountResource::canViewAny());
        $this->assertFalse((bool) ChartOfAccountResource::canCreate());
        $this->assertFalse((bool) ChartOfAccountResource::canEdit($account));
        $this->assertFalse((bool) ChartOfAccountResource::canDelete($account));

        $granted = $this->user('spv_finance', ['menu_permissions' => [ChartOfAccountResource::class => ['create', 'update', 'delete']]]);
        $this->actingAs($granted, 'web');
        $this->assertTrue((bool) ChartOfAccountResource::canCreate());
        $this->assertTrue((bool) ChartOfAccountResource::canEdit($account));
        $this->assertTrue((bool) ChartOfAccountResource::canDelete($account));
    }
}
