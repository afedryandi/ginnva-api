<?php

namespace Tests\Feature;

use App\Filament\Resources\RecurringBillTemplateResource;
use App\Filament\Resources\RecurringBillTemplateResource\Pages\CreateRecurringBillTemplate;
use App\Filament\Resources\RecurringBillTemplateResource\Pages\ListRecurringBillTemplates;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Payable;
use App\Models\RecurringBillTemplate;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\RecurringBillGenerationService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Template Tagihan Rutin: generate otomatis Hutang Usaha + jurnal (Debit akun
 * beban, Kredit 2110) dari template bulanan — jatuh tempo, kejar-tayang
 * (catch-up), jeda, tanggal berakhir, batas jumlah, anti-duplikat, periode
 * tertutup, kegagalan terisolasi & dilaporkan, jalankan manual — serta
 * pengelolaan template di Filament (validasi, filter, aksi, izin).
 * Waktu dibekukan: 15 Okt 2026.
 */
class RecurringBillTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private int $expenseAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->expenseAccountId = ChartOfAccount::where('code', '6510')->value('id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(string $name = 'Admin'): User
    {
        return tap(User::create(['name' => $name, 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin'));
    }

    private function template(array $overrides = []): RecurringBillTemplate
    {
        return RecurringBillTemplate::create(array_merge([
            'name' => 'Sewa Toko', 'supplier_name' => 'PT Properti Jaya', 'store_id' => $this->store->id, 'chart_of_account_id' => $this->expenseAccountId,
            'amount' => 5000000, 'day_of_month' => 5, 'next_run_date' => '2026-10-05', 'is_active' => true,
        ], $overrides));
    }

    private function run(): \Illuminate\Support\Collection
    {
        return app(RecurringBillGenerationService::class)->runDue(now());
    }

    // ------------------------------------------------------------- generate

    public function test_a_due_template_creates_a_payable_with_a_balanced_journal_and_advances_one_month(): void
    {
        $template = $this->template();

        $generated = $this->run();

        $this->assertCount(1, $generated);
        $payable = $generated->first();
        $this->assertEquals(5000000, $payable->amount);
        $this->assertSame('2026-10-05', $payable->due_date->toDateString());
        $this->assertSame('PT Properti Jaya', $payable->supplier_name);
        $this->assertSame('recurring_bill_template', $payable->source_type);
        $this->assertSame($template->id, (int) $payable->source_id);
        $this->assertStringContainsString('(auto-generate)', $payable->notes);

        $lines = JournalEntry::withoutGlobalScopes()->with('lines')->findOrFail($payable->journal_entry_id)->lines;
        $this->assertEquals(5000000, $lines->firstWhere('chart_of_account_id', $this->expenseAccountId)->debit);
        $this->assertEquals(5000000, $lines->firstWhere('chart_of_account_id', ChartOfAccount::where('code', '2110')->value('id'))->credit);

        $fresh = $template->fresh();
        $this->assertSame('2026-11-05', $fresh->next_run_date->toDateString());
        $this->assertNotNull($fresh->last_run_at);
        $this->assertNull($fresh->last_error);
    }

    public function test_a_supplier_is_created_from_the_name_or_reused_case_insensitively(): void
    {
        Supplier::create(['name' => 'PT Properti Jaya']);
        $this->template(['supplier_name' => '  pt   properti   jaya ']);

        $payable = $this->run()->first();

        $this->assertSame(1, Supplier::count());
        $this->assertSame(Supplier::firstOrFail()->id, $payable->supplier_id);
    }

    public function test_future_and_inactive_templates_are_not_run(): void
    {
        $this->template(['next_run_date' => '2026-10-16']);
        $this->template(['name' => 'Mati', 'is_active' => false]);

        $this->assertCount(0, $this->run());
        $this->assertSame(0, Payable::withoutGlobalScopes()->count());
    }

    public function test_missed_months_are_caught_up_one_payable_per_month(): void
    {
        $template = $this->template(['next_run_date' => '2026-07-05']);

        $generated = $this->run();

        $this->assertEquals(['2026-07-05', '2026-08-05', '2026-09-05', '2026-10-05'], $generated->map(fn ($p) => $p->due_date->toDateString())->all());
        $this->assertSame('2026-11-05', $template->fresh()->next_run_date->toDateString());
    }

    public function test_running_twice_never_duplicates_a_month(): void
    {
        $template = $this->template();

        $this->run();
        $template->update(['next_run_date' => '2026-10-05']); // jadwal dimundurkan manual -> bulan yang sama
        $second = $this->run();

        $this->assertCount(0, $second, 'Bulan yang sudah pernah dibuat hanya memajukan jadwal.');
        $this->assertSame(1, Payable::withoutGlobalScopes()->where('source_id', $template->id)->count());
        $this->assertSame('2026-11-05', $template->fresh()->next_run_date->toDateString());
    }

    public function test_the_day_of_month_clamps_to_short_months(): void
    {
        $template = $this->template(['day_of_month' => 31, 'next_run_date' => '2026-01-31']);

        $feb = $template->nextAfter(Carbon::parse('2026-01-31'));
        $mar = $template->nextAfter($feb);

        $this->assertSame('2026-02-28', $feb->toDateString());
        $this->assertSame('2026-03-31', $mar->toDateString(), 'Kembali ke tanggal 31 begitu bulannya cukup panjang.');
    }

    public function test_months_inside_a_pause_are_skipped_without_a_catch_up_burst(): void
    {
        $template = $this->template(['next_run_date' => '2026-08-05', 'paused_until' => '2026-09-30']);

        $generated = $this->run();

        $this->assertEquals(['2026-10-05'], $generated->map(fn ($p) => $p->due_date->toDateString())->all(), 'Agustus & September dilewati.');
        $this->assertSame('2026-11-05', $template->fresh()->next_run_date->toDateString());
    }

    public function test_an_expired_template_is_deactivated_and_admins_are_told(): void
    {
        $admin = $this->admin();
        $template = $this->template(['end_date' => '2026-09-30']);

        $generated = $this->run();

        $this->assertCount(0, $generated);
        $this->assertFalse($template->fresh()->is_active);
        $this->assertStringContainsString('selesai', $admin->notifications()->first()->data['title']);
    }

    public function test_the_occurrence_cap_stops_the_template_and_cancelled_bills_do_not_count(): void
    {
        $template = $this->template(['next_run_date' => '2026-07-05', 'max_occurrences' => 2]);

        $this->assertCount(2, $this->run(), 'Hanya 2 dari 4 bulan yang tertinggal.');
        $this->assertFalse($template->fresh()->is_active);

        $other = $this->template(['name' => 'Cicilan', 'next_run_date' => '2026-09-05', 'max_occurrences' => 2]);
        $this->run();
        Payable::withoutGlobalScopes()->where('source_id', $other->id)->first()->update(['status' => 'cancelled']);
        $this->assertSame(1, $other->fresh()->generatedCount(), 'Tagihan yang dibatalkan tidak dihitung ke batas.');
    }

    public function test_a_closed_accounting_period_posts_the_journal_today_while_keeping_the_original_due_date(): void
    {
        $admin = $this->admin();
        AccountingPeriod::create(['period_month' => '2026-08-01', 'closed_by' => $admin->id, 'closed_at' => now()]);
        $this->template(['next_run_date' => '2026-08-05']);

        $generated = $this->run()->first();

        $this->assertSame('2026-08-05', $generated->due_date->toDateString());
        $entry = JournalEntry::withoutGlobalScopes()->findOrFail($generated->journal_entry_id);
        $this->assertSame(now()->toDateString(), $entry->entry_date->toDateString());
    }

    public function test_one_failing_template_is_reported_without_blocking_the_others(): void
    {
        $admin = $this->admin();
        $bad = $this->template(['name' => 'Rusak']);
        ChartOfAccount::whereKey($this->expenseAccountId)->update(['is_active' => false]);
        $goodAccount = ChartOfAccount::where('code', '6110')->value('id');
        $good = $this->template(['name' => 'Sehat', 'chart_of_account_id' => $goodAccount]);
        $service = app(RecurringBillGenerationService::class);

        $generated = $service->runDue(now());

        $this->assertCount(1, $generated);
        $this->assertSame($good->id, (int) $generated->first()->source_id);
        $this->assertCount(1, $service->getFailures());
        $freshBad = $bad->fresh();
        $this->assertNotNull($freshBad->last_error);
        $this->assertNotNull($freshBad->last_error_at);
        $this->assertSame('2026-10-05', $freshBad->next_run_date->toDateString(), 'Jadwal tetap, diulang besok.');
        $this->assertTrue($admin->notifications()->get()->contains(fn ($n) => str_contains($n->data['title'], 'gagal dibuat')));
    }

    public function test_the_command_reports_counts_and_fails_when_a_template_fails(): void
    {
        $this->template();
        $this->artisan('billing:generate-recurring')->expectsOutputToContain('1 tagihan rutin di-generate.')->assertSuccessful();

        $this->template(['name' => 'Rusak', 'chart_of_account_id' => ChartOfAccount::where('code', '1101')->value('id')]);
        $this->artisan('billing:generate-recurring')->expectsOutputToContain('GAGAL template')->assertFailed();
    }

    public function test_run_now_forces_the_next_schedule_even_when_paused_but_refuses_inactive_templates(): void
    {
        $paused = $this->template(['paused_until' => '2026-12-31']);
        $payable = app(RecurringBillGenerationService::class)->runNow($paused);

        $this->assertNotNull($payable);
        $this->assertSame('2026-11-05', $paused->fresh()->next_run_date->toDateString());

        $inactive = $this->template(['name' => 'Mati', 'is_active' => false]);
        $this->expectException(RuntimeException::class);
        app(RecurringBillGenerationService::class)->runNow($inactive);
    }

    public function test_the_upcoming_schedule_marks_paused_months_and_respects_the_limits(): void
    {
        $template = $this->template(['next_run_date' => '2026-10-05', 'paused_until' => '2026-11-30']);
        $dates = collect($template->upcomingSchedule(4));

        $this->assertEquals([['2026-10-05', true], ['2026-11-05', true], ['2026-12-05', false], ['2027-01-05', false]], $dates->map(fn ($r) => [$r['date']->toDateString(), $r['skipped']])->all());

        $capped = $this->template(['name' => 'Terbatas', 'max_occurrences' => 2, 'end_date' => '2026-12-31']);
        $this->assertCount(2, $capped->upcomingSchedule(5));

        $ended = $this->template(['name' => 'Berakhir', 'end_date' => '2026-11-30']);
        $this->assertCount(2, $ended->upcomingSchedule(5), 'Okt & Nov saja.');
    }

    public function test_changing_a_template_tells_the_other_admins_but_not_the_editor(): void
    {
        $editor = $this->admin('Editor');
        $other = $this->admin('Lain');
        $template = $this->template();
        $this->actingAs($editor, 'web');

        $template->update(['amount' => 6000000]);

        $this->assertSame(0, $editor->notifications()->count());
        $this->assertSame(1, $other->notifications()->count());
        $this->assertStringContainsString('nominal', $other->notifications()->first()->data['body']);

        $template->update(['name' => 'Nama baru']);
        $this->assertSame(1, $other->notifications()->count(), 'Perubahan nama saja tidak dianggap berdampak.');
    }

    // ------------------------------------------------------------- Filament

    private function form(array $overrides = []): array
    {
        $supplier = Supplier::firstOrCreate(['name' => 'PT Properti Jaya']);

        return array_merge([
            'name' => 'Sewa Toko', 'supplier_id' => $supplier->id, 'store_id' => $this->store->id, 'chart_of_account_id' => $this->expenseAccountId,
            'amount' => 5000000, 'day_of_month' => 5, 'next_run_date' => '2026-11-05', 'is_active' => true,
        ], $overrides);
    }

    public function test_access_is_full_access_only_unless_view_is_granted_explicitly(): void
    {
        $template = $this->template();

        $this->actingAs($this->admin(), 'web');
        $this->assertTrue(RecurringBillTemplateResource::canViewAny());
        $this->assertTrue(RecurringBillTemplateResource::canCreate());
        $this->assertTrue(RecurringBillTemplateResource::canEdit($template));

        $plain = User::create(['name' => 'Kasir', 'email' => 'k@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        $plain->assignRole('kasir');
        $this->actingAs($plain, 'web');
        $this->assertFalse((bool) RecurringBillTemplateResource::canViewAny());

        $viewer = User::create(['name' => 'Pengamat', 'email' => 'v@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_permissions' => [RecurringBillTemplateResource::class => ['view']]]);
        $viewer->assignRole('kasir');
        $this->actingAs($viewer, 'web');
        $this->assertTrue((bool) RecurringBillTemplateResource::canViewAny());
        $this->assertFalse(RecurringBillTemplateResource::canCreate());
        $this->assertFalse(RecurringBillTemplateResource::canEdit($template));
    }

    public function test_admin_creates_a_template_with_the_supplier_name_filled_automatically(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web');

        Livewire::test(CreateRecurringBillTemplate::class)->fillForm($this->form())->call('create')->assertHasNoFormErrors();

        $template = RecurringBillTemplate::firstOrFail();
        $this->assertSame('PT Properti Jaya', $template->supplier_name);
        $this->assertSame($admin->id, $template->created_by);
        $this->assertSame('2026-11-05', $template->next_run_date->toDateString());
    }

    public function test_the_form_validates_the_day_the_account_amount_and_dates(): void
    {
        $this->actingAs($this->admin(), 'web');
        $create = fn (array $over) => Livewire::test(CreateRecurringBillTemplate::class)->fillForm($this->form($over))->call('create');

        $create(['day_of_month' => 10])->assertHasFormErrors(['next_run_date']);
        $create(['day_of_month' => 0])->assertHasFormErrors(['day_of_month']);
        $create(['day_of_month' => 32])->assertHasFormErrors(['day_of_month']);
        $create(['amount' => 0])->assertHasFormErrors(['amount']);
        $create(['amount' => null])->assertHasFormErrors(['amount' => 'required']);
        $create(['chart_of_account_id' => ChartOfAccount::where('code', '1101')->value('id')])->assertHasFormErrors(['chart_of_account_id']);
        $create(['end_date' => '2026-10-01'])->assertHasFormErrors(['end_date']);
        $create(['max_occurrences' => 0])->assertHasFormErrors(['max_occurrences']);
        $create(['name' => ''])->assertHasFormErrors(['name' => 'required']);
        $create(['next_run_date' => '2026-05-05'])->assertHasFormErrors(['next_run_date']);

        $this->assertSame(0, RecurringBillTemplate::count());
    }

    public function test_list_filters_and_the_run_now_and_skip_actions(): void
    {
        $this->actingAs($this->admin(), 'web');
        $due = $this->template(['name' => 'Segera', 'next_run_date' => '2026-10-18']);
        $far = $this->template(['name' => 'Jauh', 'next_run_date' => '2026-12-05']);
        $off = $this->template(['name' => 'Mati', 'is_active' => false]);
        $failed = $this->template(['name' => 'Gagal', 'next_run_date' => '2026-12-05']);
        RecurringBillTemplate::whereKey($failed->id)->update(['last_error' => 'Akun tidak valid', 'last_error_at' => now()]);

        Livewire::test(ListRecurringBillTemplates::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$due, $far, $off, $failed])
            ->filterTable('is_active', false)->assertCanSeeTableRecords([$off])->assertCanNotSeeTableRecords([$due]);
        Livewire::test(ListRecurringBillTemplates::class)->filterTable('due_soon')
            ->assertCanSeeTableRecords([$due])->assertCanNotSeeTableRecords([$far, $off]);
        Livewire::test(ListRecurringBillTemplates::class)->filterTable('failed')
            ->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$due]);

        Livewire::test(ListRecurringBillTemplates::class)
            ->assertTableActionHidden('run_now', $off)
            ->callTableAction('run_now', $far);
        $this->assertSame(1, Payable::withoutGlobalScopes()->where('source_id', $far->id)->count());
        $this->assertSame('2027-01-05', $far->fresh()->next_run_date->toDateString());

        Livewire::test(ListRecurringBillTemplates::class)->callTableAction('skip_next', $due);
        $this->assertSame('2026-11-18', $due->fresh()->next_run_date->toDateString());
        $this->assertSame(0, Payable::withoutGlobalScopes()->where('source_id', $due->id)->count(), 'Dilewati = tanpa tagihan.');
    }

    public function test_a_template_with_generated_bills_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $this->actingAs($this->admin(), 'web');
        $used = $this->template(['name' => 'Dipakai']);
        $this->run();
        $unused = $this->template(['name' => 'Belum Dipakai', 'next_run_date' => '2026-12-05']);

        Livewire::test(ListRecurringBillTemplates::class)
            ->assertTableActionHidden('delete', $used->fresh())
            ->callTableAction('delete', $unused);

        $this->assertNull(RecurringBillTemplate::find($unused->id));
        $this->assertNotNull(RecurringBillTemplate::find($used->id));
        $this->assertFalse($used->fresh()->delete(), 'Model juga menolak hapus kalau sudah punya tagihan.');
    }
}
