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

    public function test_future_template_not_generated_yet(): void
    {
        $this->makeTemplate(['next_run_date' => '2026-12-05']);

        $generated = app(RecurringBillGenerationService::class)->runDue(Carbon::parse('2026-09-05'));

        $this->assertCount(0, $generated);
    }
}
