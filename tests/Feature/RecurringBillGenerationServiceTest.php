<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\RecurringBillTemplate;
use App\Services\RecurringBillGenerationService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Audit Majoo f48 ("Template tagihan rutin"), dibangun 2026-09-22 atas
 * keputusan user: auto-generate langsung terposting (tidak perlu
 * approval ulang tiap bulan). Fokus test: next_run_date maju dgn benar
 * (termasuk clamp akhir bulan), template non-aktif tidak digenerate,
 * dan "mengejar" beberapa bulan tertinggal sekaligus kalau cron sempat
 * tidak jalan.
 */
class RecurringBillGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);
    }

    private function makeTemplate(array $overrides = []): RecurringBillTemplate
    {
        $expenseAccount = ChartOfAccount::where('code', '6210')->firstOrFail(); // "Beban Sewa Toko", akun postable

        return RecurringBillTemplate::create(array_merge([
            'name' => 'Sewa Toko',
            'supplier_name' => 'Pemilik Ruko',
            'chart_of_account_id' => $expenseAccount->id,
            'amount' => 5_000_000,
            'day_of_month' => 5,
            'next_run_date' => '2026-09-05',
            'is_active' => true,
        ], $overrides));
    }

    public function test_generates_payable_and_advances_next_run_date(): void
    {
        $template = $this->makeTemplate();

        $generated = app(RecurringBillGenerationService::class)->runDue(Carbon::parse('2026-09-05'));

        $this->assertCount(1, $generated);
        $this->assertEquals(5_000_000.00, (float) $generated->first()->amount);
        $this->assertEquals('recurring_bill_template', $generated->first()->source_type);
        $this->assertNotNull($generated->first()->journal_entry_id);

        $template->refresh();
        $this->assertTrue($template->next_run_date->isSameDay(Carbon::parse('2026-10-05')));
    }

    public function test_clamps_day_of_month_to_end_of_shorter_month(): void
    {
        $template = $this->makeTemplate(['day_of_month' => 31, 'next_run_date' => '2026-01-31']);

        app(RecurringBillGenerationService::class)->runDue(Carbon::parse('2026-01-31'));

        $template->refresh();
        $this->assertTrue($template->next_run_date->isSameDay(Carbon::parse('2026-02-28')));
    }

    public function test_inactive_template_is_not_generated(): void
    {
        $this->makeTemplate(['is_active' => false]);

        $generated = app(RecurringBillGenerationService::class)->runDue(Carbon::parse('2026-09-05'));

        $this->assertCount(0, $generated);
    }

    public function test_catches_up_multiple_missed_months_in_one_run(): void
    {
        $this->makeTemplate(['next_run_date' => '2026-07-05']);

        // Cron baru jalan lagi di bulan September -- Juli, Agustus,
        // September semuanya harus ter-generate, bukan cuma yang
        // terakhir.
        $generated = app(RecurringBillGenerationService::class)->runDue(Carbon::parse('2026-09-10'));

        $this->assertCount(3, $generated);
    }

    public function test_failing_template_records_error_and_does_not_block_others(): void
    {
        $bad = $this->makeTemplate(['name' => 'Rusak']);
        $good = $this->makeTemplate(['name' => 'Sehat']);
        // Akun tujuan template "Rusak" dinonaktifkan setelah template dibuat.
        ChartOfAccount::whereKey($bad->chart_of_account_id)->update(['is_active' => false]);
        // ...tapi dua template memakai akun yang sama; beri "Sehat" akun lain yang valid.
        $other = ChartOfAccount::where('type', 'beban_operasional')->where('is_postable', true)->where('is_active', true)->firstOrFail();
        $good->update(['chart_of_account_id' => $other->id]);

        $service = app(RecurringBillGenerationService::class);
        $generated = $service->runDue(Carbon::parse('2026-09-05'));

        $this->assertCount(1, $generated, 'Template sehat tetap ter-generate walau template lain gagal.');
        $this->assertCount(1, $service->getFailures());
        $this->assertNotNull($bad->fresh()->last_error);
        $this->assertTrue($bad->fresh()->next_run_date->isSameDay(Carbon::parse('2026-09-05')), 'Template gagal tidak boleh maju jadwalnya.');
        $this->assertNull($good->fresh()->last_error);
        $this->assertNotNull($good->fresh()->last_run_at);
    }

    public function test_running_twice_does_not_create_duplicate_bills(): void
    {
        $this->makeTemplate();
        $service = app(RecurringBillGenerationService::class);

        $first = $service->runDue(Carbon::parse('2026-09-05'));
        $second = $service->runDue(Carbon::parse('2026-09-05'));

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
    }

    public function test_non_expense_account_is_rejected_at_generation(): void
    {
        $revenue = ChartOfAccount::where('type', 'pendapatan')->where('is_postable', true)->firstOrFail();
        $template = $this->makeTemplate(['chart_of_account_id' => $revenue->id]);

        $service = app(RecurringBillGenerationService::class);
        $generated = $service->runDue(Carbon::parse('2026-09-05'));

        $this->assertCount(0, $generated);
        $this->assertCount(1, $service->getFailures());
        $this->assertNotNull($template->fresh()->last_error);
    }

    public function test_template_that_generated_bills_cannot_be_deleted_by_a_user(): void
    {
        $template = $this->makeTemplate();
        app(RecurringBillGenerationService::class)->runDue(Carbon::parse('2026-09-05'));

        $user = \App\Models\User::create(['name' => 'Direksi', 'email' => 'dir@test.local', 'password' => 'x']);
        $this->actingAs($user);

        $this->assertFalse($template->fresh()->delete());
        $this->assertNotNull(RecurringBillTemplate::find($template->id));
    }

    public function test_max_occurrences_stops_and_deactivates_template(): void
    {
        $template = $this->makeTemplate(['next_run_date' => '2026-07-05', 'max_occurrences' => 2]);

        $generated = app(RecurringBillGenerationService::class)->runDue(Carbon::parse('2026-09-10'));

        $this->assertCount(2, $generated, 'Hanya 2 tagihan (batas), bukan 3 bulan yang terlewat.');
        $this->assertFalse($template->fresh()->is_active);
    }

    public function test_end_date_stops_template(): void
    {
        $template = $this->makeTemplate(['next_run_date' => '2026-07-05', 'end_date' => '2026-08-31']);

        $generated = app(RecurringBillGenerationService::class)->runDue(Carbon::parse('2026-09-10'));

        $this->assertCount(2, $generated, 'Juli & Agustus saja; September melewati tanggal berakhir.');
        $this->assertFalse($template->fresh()->is_active);
    }

    public function test_paused_months_are_skipped_without_creating_bills(): void
    {
        $template = $this->makeTemplate(['next_run_date' => '2026-07-05', 'paused_until' => '2026-08-31']);

        $generated = app(RecurringBillGenerationService::class)->runDue(Carbon::parse('2026-09-10'));

        $this->assertCount(1, $generated, 'Juli & Agustus dilewati (jeda), hanya September dibuat.');
        $this->assertTrue($template->fresh()->next_run_date->isSameDay(Carbon::parse('2026-10-05')));
    }

    public function test_run_now_generates_next_schedule_and_advances(): void
    {
        $template = $this->makeTemplate(['next_run_date' => '2027-01-05']);

        $payable = app(RecurringBillGenerationService::class)->runNow($template);

        $this->assertNotNull($payable);
        $this->assertTrue($template->fresh()->next_run_date->isSameDay(Carbon::parse('2027-02-05')));
    }

    public function test_upcoming_schedule_respects_limits(): void
    {
        $template = $this->makeTemplate(['next_run_date' => '2026-09-05', 'max_occurrences' => 3]);

        $this->assertCount(3, $template->upcomingSchedule(5));
    }

    public function test_future_template_not_generated_yet(): void
    {
        $this->makeTemplate(['next_run_date' => '2026-12-05']);

        $generated = app(RecurringBillGenerationService::class)->runDue(Carbon::parse('2026-09-05'));

        $this->assertCount(0, $generated);
    }
}
