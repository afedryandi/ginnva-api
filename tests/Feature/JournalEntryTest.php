<?php

namespace Tests\Feature;

use App\Filament\Resources\JournalEntryResource;
use App\Filament\Resources\JournalEntryResource\Pages\CreateJournalEntry;
use App\Filament\Resources\JournalEntryResource\Pages\EditJournalEntry;
use App\Filament\Resources\JournalEntryResource\Pages\ListJournalEntries;
use App\Filament\Resources\JournalEntryResource\Pages\ViewJournalEntry;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Models\User;
use App\Services\JournalEntryService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Jurnal Umum: pembuatan draft (nomor berurutan, validasi baris: minimal 2,
 * debit ATAU kredit, tidak negatif, balance, akun aktif & bisa diposting),
 * periode tertutup, posting (hanya draft & balance), jurnal posted terkunci,
 * jurnal pembalik (cermin debit/kredit, sekali saja, aturan tanggal), dan layar
 * admin: form, "Simpan & Posting", pemisahan tugas spv_finance (tidak boleh
 * memposting/membalik jurnal buatan sendiri), jurnal otomatis tidak dibalik
 * dari sini, filter, ekspor, dan izin.
 */
class JournalEntryTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccount $expense;
    private ChartOfAccount $cash;
    private JournalEntryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('spv_finance', 'web');
        Role::findOrCreate('kasir', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->expense = ChartOfAccount::where('code', '6210')->firstOrFail();
        $this->cash = ChartOfAccount::where('code', '1101')->firstOrFail();
        $this->service = app(JournalEntryService::class);
    }

    private function user(string $role, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x'], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function admin(string $name = 'Admin'): User
    {
        return $this->user('super_admin', ['name' => $name]);
    }

    private function spv(array $permissions = ['create', 'update', 'delete']): User
    {
        return $this->user('spv_finance', ['menu_permissions' => [JournalEntryResource::class => $permissions]]);
    }

    private function lines(float $amount = 100000): array
    {
        return [
            ['chart_of_account_id' => $this->expense->id, 'debit' => $amount],
            ['chart_of_account_id' => $this->cash->id, 'credit' => $amount],
        ];
    }

    private function draft(array $header = [], ?array $lines = null): JournalEntry
    {
        return $this->service->create(array_merge([
            'entry_date' => now()->toDateString(), 'description' => 'Beban listrik', 'created_by' => null,
        ], $header), $lines ?? $this->lines());
    }

    private function posted(array $header = [], ?array $lines = null, ?int $by = null): JournalEntry
    {
        return $this->service->post($this->draft($header, $lines), $by);
    }

    private function formLines(float $amount = 100000): array
    {
        return [
            ['chart_of_account_id' => $this->expense->id, 'debit' => $amount, 'credit' => 0, 'description' => null],
            ['chart_of_account_id' => $this->cash->id, 'debit' => 0, 'credit' => $amount, 'description' => null],
        ];
    }

    private function assertRefused(callable $action, string $messageFragment): void
    {
        try {
            $action();
            $this->fail('Seharusnya ditolak: ' . $messageFragment);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($messageFragment, $e->getMessage());
        }
    }

    // ------------------------------------------------------------- service: buat

    public function test_a_draft_gets_a_sequential_number_and_normalised_lines(): void
    {
        $first = $this->draft();
        $second = $this->draft();

        $this->assertMatchesRegularExpression('/^JE-\d{6}-0001$/', $first->entry_number);
        $this->assertStringEndsWith('-0002', $second->entry_number);
        $this->assertSame('draft', $first->status);
        $this->assertCount(2, $first->lines);
        $this->assertEquals(100000, $first->totalDebit());
        $this->assertTrue($first->isBalanced());
    }

    public function test_line_rules_are_enforced_on_creation(): void
    {
        $cases = [
            'minimal 2 baris' => [[['chart_of_account_id' => $this->expense->id, 'debit' => 1000]]],
            'tidak balance' => [[['chart_of_account_id' => $this->expense->id, 'debit' => 1000], ['chart_of_account_id' => $this->cash->id, 'credit' => 900]]],
            'negatif' => [[['chart_of_account_id' => $this->expense->id, 'debit' => -1000], ['chart_of_account_id' => $this->cash->id, 'credit' => -1000]]],
            'debit dan kredit sekaligus' => [[['chart_of_account_id' => $this->expense->id, 'debit' => 1000, 'credit' => 500], ['chart_of_account_id' => $this->cash->id, 'credit' => 500]]],
            'dua-duanya kosong' => [[['chart_of_account_id' => $this->expense->id, 'debit' => 0, 'credit' => 0], ['chart_of_account_id' => $this->cash->id, 'credit' => 1000]]],
            'maksimal Rp' => [$this->lines(JournalEntryService::MAX_AMOUNT + 1000)],
        ];

        foreach ($cases as $fragment => [$lines]) {
            $this->assertRefused(fn () => $this->draft([], $lines), $fragment);
        }

        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->count(), 'Tidak ada jurnal setengah jadi yang tersisa.');
    }

    public function test_only_active_postable_existing_accounts_can_be_used(): void
    {
        $header = ChartOfAccount::where('is_postable', false)->firstOrFail();
        $inactive = ChartOfAccount::where('code', '6110')->firstOrFail();
        $inactive->update(['is_active' => false]);

        foreach ([$header->id, $inactive->id, 999999] as $bad) {
            $lines = [['chart_of_account_id' => $bad, 'debit' => 1000], ['chart_of_account_id' => $this->cash->id, 'credit' => 1000]];
            $this->assertRefused(fn () => $this->draft([], $lines), $bad === 999999 ? 'tidak ditemukan' : 'tidak bisa dipakai jurnal');
        }
    }

    public function test_a_closed_period_blocks_create_update_and_post(): void
    {
        $draftInMay = $this->draft(['entry_date' => '2026-05-10']);
        AccountingPeriod::create(['period_month' => '2026-05-01', 'closed_by' => $this->admin()->id, 'closed_at' => now()]);

        $this->assertRefused(fn () => $this->draft(['entry_date' => '2026-05-20']), 'sudah ditutup');
        $this->assertRefused(fn () => $this->service->update($draftInMay, ['entry_date' => '2026-05-11', 'description' => 'x'], $this->lines()), 'sudah ditutup');
        $this->assertRefused(fn () => $this->service->post($draftInMay, null), 'sudah ditutup');
    }

    // ------------------------------------------------------------- service: ubah & posting

    public function test_a_draft_can_be_rewritten_but_a_posted_entry_cannot(): void
    {
        $draft = $this->draft();

        $updated = $this->service->update($draft, ['entry_date' => now()->toDateString(), 'description' => 'Diubah'], $this->lines(250000));
        $this->assertSame('Diubah', $updated->description);
        $this->assertEquals(250000, $updated->totalDebit());
        $this->assertCount(2, $updated->lines, 'Baris lama diganti, bukan ditumpuk.');

        $posted = $this->service->post($updated, null);
        $this->assertRefused(fn () => $this->service->update($posted, ['entry_date' => now()->toDateString(), 'description' => 'x'], $this->lines()), 'terkunci');
    }

    public function test_posting_stamps_who_and_when_and_only_works_on_a_balanced_draft(): void
    {
        $admin = $this->admin();
        $posted = $this->service->post($this->draft(), $admin->id);

        $this->assertSame('posted', $posted->status);
        $this->assertSame($admin->id, $posted->posted_by);
        $this->assertNotNull($posted->posted_at);
        $this->assertRefused(fn () => $this->service->post($posted, $admin->id), 'Cuma jurnal berstatus Draft');

        $lonely = JournalEntry::create(['entry_number' => 'JE-T-1', 'entry_date' => now()->toDateString(), 'description' => 'Satu baris', 'status' => 'draft']);
        $lonely->lines()->create(['chart_of_account_id' => $this->expense->id, 'debit' => 1000, 'credit' => 0]);
        $this->assertRefused(fn () => $this->service->post($lonely, null), 'minimal 2 baris');

        $skewed = JournalEntry::create(['entry_number' => 'JE-T-2', 'entry_date' => now()->toDateString(), 'description' => 'Tidak balance', 'status' => 'draft']);
        $skewed->lines()->create(['chart_of_account_id' => $this->expense->id, 'debit' => 1000, 'credit' => 0]);
        $skewed->lines()->create(['chart_of_account_id' => $this->cash->id, 'debit' => 0, 'credit' => 900]);
        $this->assertRefused(fn () => $this->service->post($skewed, null), 'tidak balance');
    }

    public function test_a_posted_entry_cannot_be_edited_or_deleted_at_model_level(): void
    {
        $posted = $this->posted();

        $this->assertRefused(fn () => $posted->update(['description' => 'Diam-diam diubah']), 'terkunci');
        $this->assertRefused(fn () => $posted->update(['entry_date' => now()->subDay()->toDateString()]), 'terkunci');
        $this->assertRefused(fn () => $posted->delete(), 'tidak boleh dihapus');
    }

    // ------------------------------------------------------------- service: pembalik

    public function test_a_reversal_mirrors_the_lines_and_links_back_to_the_original(): void
    {
        $original = $this->posted();

        $reversal = $this->service->reverse($original, null, 'Salah catat');

        $this->assertSame('posted', $reversal->status);
        $this->assertSame('reversal', $reversal->reference_type);
        $this->assertSame($original->id, (int) $reversal->reference_id);
        $this->assertStringContainsString('Salah catat', $reversal->description);
        $reversed = $reversal->lines->keyBy('chart_of_account_id');
        $this->assertEquals(100000, $reversed[$this->expense->id]->credit);
        $this->assertEquals(100000, $reversed[$this->cash->id]->debit);
        $this->assertEquals(0, $this->expense->fresh()->balance() + $this->cash->fresh()->balance() * 0, 'Saldo beban kembali nol.');
        $this->assertEquals(0, $this->expense->fresh()->balance());
        $this->assertTrue($original->fresh()->reversal()->exists());
    }

    public function test_reversal_rules_draft_twice_reversal_of_reversal_and_dates(): void
    {
        $draft = $this->draft();
        $this->assertRefused(fn () => $this->service->reverse($draft, null), 'sudah diposting');

        $original = $this->posted(['entry_date' => now()->subDays(5)->toDateString()]);
        $this->assertRefused(fn () => $this->service->reverse($original, null, null, now()->subDays(10)->toDateString()), 'sebelum tanggal jurnal asli');
        $this->assertRefused(fn () => $this->service->reverse($original, null, null, now()->addDays(3)->toDateString()), 'masa depan');

        $reversal = $this->service->reverse($original, null);
        $this->assertRefused(fn () => $this->service->reverse($original->fresh(), null), 'sudah pernah dibalik');
        $this->assertRefused(fn () => $this->service->reverse($reversal, null), 'tidak bisa dibalik lagi');
    }

    // ------------------------------------------------------------- Filament: buat

    public function test_admin_saves_a_draft_through_the_form(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web');

        Livewire::test(CreateJournalEntry::class)
            ->fillForm(['entry_date' => now()->toDateString(), 'description' => 'Beban listrik Oktober', 'lines' => $this->formLines(125000)])
            ->call('create')->assertHasNoFormErrors();

        $entry = JournalEntry::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('draft', $entry->status);
        $this->assertSame($admin->id, $entry->created_by);
        $this->assertEquals(125000, $entry->totalDebit());
    }

    public function test_the_form_rejects_unbalanced_lines_missing_fields_and_future_dates(): void
    {
        $this->actingAs($this->admin(), 'web');
        $unbalanced = [
            ['chart_of_account_id' => $this->expense->id, 'debit' => 1000, 'credit' => 0, 'description' => null],
            ['chart_of_account_id' => $this->cash->id, 'debit' => 0, 'credit' => 900, 'description' => null],
        ];

        Livewire::test(CreateJournalEntry::class)->fillForm(['entry_date' => now()->toDateString(), 'description' => 'X', 'lines' => $unbalanced])->call('create');
        Livewire::test(CreateJournalEntry::class)->fillForm(['entry_date' => now()->toDateString(), 'description' => '', 'lines' => $this->formLines()])->call('create')->assertHasFormErrors(['description' => 'required']);
        Livewire::test(CreateJournalEntry::class)->fillForm(['entry_date' => now()->addDays(3)->toDateString(), 'description' => 'Masa depan', 'lines' => $this->formLines()])->call('create')->assertHasFormErrors(['entry_date']);
        Livewire::test(CreateJournalEntry::class)->fillForm(['entry_date' => now()->toDateString(), 'description' => 'Satu baris', 'lines' => [$this->formLines()[0]]])->call('create')->assertHasFormErrors(['lines']);

        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->count());
    }

    public function test_save_and_post_posts_immediately_for_full_access_but_stays_draft_for_the_supervisors_own_entry(): void
    {
        $this->actingAs($this->admin(), 'web');
        Livewire::test(CreateJournalEntry::class)
            ->fillForm(['entry_date' => now()->toDateString(), 'description' => 'Langsung posting', 'lines' => $this->formLines()])
            ->call('createAndPost');
        $this->assertSame('posted', JournalEntry::withoutGlobalScopes()->where('description', 'Langsung posting')->value('status'));

        $this->actingAs($this->spv(), 'web');
        Livewire::test(CreateJournalEntry::class)
            ->fillForm(['entry_date' => now()->toDateString(), 'description' => 'Buatan spv', 'lines' => $this->formLines()])
            ->call('createAndPost');
        $this->assertSame('draft', JournalEntry::withoutGlobalScopes()->where('description', 'Buatan spv')->value('status'), 'Pemisahan tugas: pembuat tidak memposting sendiri.');
    }

    public function test_a_draft_can_be_edited_while_a_posted_entry_redirects_to_the_view_page(): void
    {
        $this->actingAs($this->admin(), 'web');
        $draft = $this->draft();
        $posted = $this->posted(['description' => 'Sudah terkunci']);

        Livewire::test(EditJournalEntry::class, ['record' => $draft->getKey()])
            ->fillForm(['description' => 'Draft diperbarui', 'lines' => $this->formLines(300000)])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Draft diperbarui', $draft->fresh()->description);
        $this->assertEquals(300000, $draft->fresh()->totalDebit());

        Livewire::test(EditJournalEntry::class, ['record' => $posted->getKey()])
            ->assertRedirect(JournalEntryResource::getUrl('view', ['record' => $posted->getKey()]));
        Livewire::test(ViewJournalEntry::class, ['record' => $posted->getKey()])->assertSuccessful()->assertSee($posted->entry_number);
    }

    // ------------------------------------------------------------- Filament: posting & pembalik

    public function test_a_supervisor_cannot_post_or_reverse_their_own_entry_but_can_for_others(): void
    {
        $spv = $this->spv();
        $other = $this->admin();
        $own = $this->draft(['created_by' => $spv->id]);
        $theirs = $this->draft(['created_by' => $other->id]);
        $this->actingAs($spv, 'web');

        $this->assertFalse(JournalEntryResource::canPost($own));
        $this->assertTrue(JournalEntryResource::canPost($theirs));

        Livewire::test(ListJournalEntries::class)
            ->assertTableActionHidden('post', $own)
            ->assertTableActionVisible('post', $theirs)
            ->callTableAction('post', $theirs);
        $this->assertSame('posted', $theirs->fresh()->status);
        $this->assertSame($spv->id, $theirs->fresh()->posted_by);
        $this->assertGreaterThanOrEqual(1, $other->notifications()->count(), 'Direksi diberi tahu saat spv_finance memposting.');

        $ownPosted = $this->posted(['created_by' => $spv->id], null, $other->id);
        $this->assertFalse(JournalEntryResource::canReverse($ownPosted));
    }

    public function test_reversal_from_the_menu_requires_a_reason_and_creates_the_mirror_entry(): void
    {
        $this->actingAs($this->admin(), 'web');
        $original = $this->posted();

        Livewire::test(ListJournalEntries::class)
            ->assertTableActionVisible('reverse', $original)
            ->callTableAction('reverse', $original, data: ['reversal_date' => now()->toDateString(), 'note' => ''])
            ->assertHasTableActionErrors(['note' => 'required']);

        Livewire::test(ListJournalEntries::class)
            ->callTableAction('reverse', $original, data: ['reversal_date' => now()->toDateString(), 'note' => 'Salah akun'])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($original->fresh()->reversal()->exists());
        Livewire::test(ListJournalEntries::class)->assertTableActionHidden('reverse', $original->fresh());
    }

    public function test_automatic_journals_are_reversed_through_their_source_module_not_from_here(): void
    {
        $this->actingAs($this->admin(), 'web');
        $auto = $this->posted(['reference_type' => 'finance_transaction', 'reference_id' => 1]);

        $this->assertFalse(JournalEntryResource::canReverse($auto));
        Livewire::test(ListJournalEntries::class)
            ->assertTableActionHidden('reverse', $auto)
            ->assertTableActionVisible('reverseViaSource', $auto);
    }

    public function test_only_drafts_can_be_deleted_from_the_menu(): void
    {
        $this->actingAs($this->admin(), 'web');
        $draft = $this->draft();
        $posted = $this->posted();

        $this->assertTrue((bool) JournalEntryResource::canDelete($draft));
        $this->assertFalse((bool) JournalEntryResource::canDelete($posted));

        Livewire::test(ListJournalEntries::class)->assertTableActionHidden('delete', $posted)->callTableAction('delete', $draft);

        $this->assertNull(JournalEntry::withoutGlobalScopes()->find($draft->id));
        $this->assertNotNull(JournalEntry::withoutGlobalScopes()->find($posted->id));
    }

    // ------------------------------------------------------------- daftar, ekspor, izin

    public function test_list_filters_and_the_export_download(): void
    {
        $this->actingAs($this->admin(), 'web');
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $draft = $this->draft(['description' => 'Draft']);
        $manual = $this->posted(['description' => 'Manual lama', 'entry_date' => now()->subMonths(2)->toDateString(), 'store_id' => $store->id]);
        $auto = $this->posted(['description' => 'Otomatis', 'reference_type' => 'finance_transaction', 'reference_id' => 5], [
            ['chart_of_account_id' => ChartOfAccount::where('code', '6110')->value('id'), 'debit' => 500],
            ['chart_of_account_id' => $this->cash->id, 'credit' => 500],
        ]);

        Livewire::test(ListJournalEntries::class)->assertSuccessful()
            ->filterTable('status', 'draft')->assertCanSeeTableRecords([$draft])->assertCanNotSeeTableRecords([$manual, $auto]);
        Livewire::test(ListJournalEntries::class)->filterTable('sumber', 'finance_transaction')
            ->assertCanSeeTableRecords([$auto])->assertCanNotSeeTableRecords([$manual, $draft]);
        Livewire::test(ListJournalEntries::class)->filterTable('sumber', 'manual')
            ->assertCanSeeTableRecords([$draft, $manual])->assertCanNotSeeTableRecords([$auto]);
        Livewire::test(ListJournalEntries::class)->filterTable('akun', ChartOfAccount::where('code', '6110')->value('id'))
            ->assertCanSeeTableRecords([$auto])->assertCanNotSeeTableRecords([$draft, $manual]);
        Livewire::test(ListJournalEntries::class)
            ->filterTable('entry_date', ['from' => now()->subMonth()->toDateString(), 'until' => now()->toDateString()])
            ->assertCanSeeTableRecords([$draft, $auto])->assertCanNotSeeTableRecords([$manual]);

        Excel::fake();
        Livewire::test(ListJournalEntries::class)->callTableAction('exportJournals', data: ['from' => now()->startOfMonth()->toDateString(), 'until' => now()->toDateString()]);
        Excel::assertDownloaded('jurnal-umum-' . now()->format('Ymd') . '.xlsx');
    }

    public function test_access_is_full_access_or_a_finance_supervisor_with_explicit_grants(): void
    {
        $entry = $this->draft();

        $this->actingAs($this->admin(), 'web');
        $this->assertTrue(JournalEntryResource::canViewAny());
        $this->assertTrue(JournalEntryResource::canCreate());
        $this->assertTrue(JournalEntryResource::canEdit($entry));

        $this->actingAs($this->user('kasir'), 'web');
        $this->assertFalse((bool) JournalEntryResource::canViewAny());

        $this->actingAs($this->user('spv_finance'), 'web');
        $this->assertTrue((bool) JournalEntryResource::canViewAny());
        $this->assertFalse((bool) JournalEntryResource::canCreate());
        $this->assertFalse((bool) JournalEntryResource::canEdit($entry));
        $this->assertFalse((bool) JournalEntryResource::canDelete($entry));

        $this->actingAs($this->spv(), 'web');
        $this->assertTrue((bool) JournalEntryResource::canCreate());
        $this->assertTrue((bool) JournalEntryResource::canEdit($entry));
        $this->assertTrue((bool) JournalEntryResource::canDelete($entry));
    }
}
