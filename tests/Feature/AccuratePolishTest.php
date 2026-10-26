<?php

namespace Tests\Feature;

use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\FinanceTransactionResource;
use App\Filament\Resources\FinanceTransactionResource\Pages\CreateFinanceTransaction;
use App\Filament\Resources\FinanceTransactionResource\Pages\ListFinanceTransactions;
use App\Filament\Resources\RecurringBillTemplateResource;
use App\Filament\Resources\RecurringBillTemplateResource\Pages\CreateRecurringBillTemplate;
use App\Filament\Resources\WarrantyResource\Pages\EditWarranty;
use App\Filament\Resources\WarrantyResource\RelationManagers\ClaimsRelationManager;
use App\Models\AccountingPeriod;
use App\Models\Booking;
use App\Models\ChartOfAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceSetting;
use App\Models\FinanceTransaction;
use App\Models\RecurringBillTemplate;
use App\Models\Store;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Support\PeriodStatus;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Empat tambahan dari audit ACCURATE 5 (pass 12): Profil Perusahaan, status periode di halaman Keuangan,
 * tombol Duplikat (Pengeluaran, Template Tagihan, Booking), dan penanganan klaim garansi (penangan, perkiraan selesai, hasil).
 * Waktu dibekukan: 15 Okt 2026.
 */
class AccuratePolishTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['super_admin', 'installer'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(string $name = 'Admin'): User
    {
        return tap(User::create(['name' => $name, 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]), fn (User $u) => $u->assignRole('super_admin'));
    }

    // ------------------------------------------------------------- profil perusahaan

    public function test_company_profile_falls_back_to_the_default_name_until_filled(): void
    {
        $profile = FinanceSetting::companyProfile();

        $this->assertSame(FinanceSetting::DEFAULT_COMPANY_NAME, $profile['name']);
        $this->assertNull($profile['npwp']);
    }

    public function test_the_company_profile_is_saved_from_the_finance_settings_page(): void
    {
        $this->actingAs($this->admin(), 'web');

        Livewire::test(FinanceSettingsPage::class)
            ->set('data.company_name', 'PT Contoh Ginnva')
            ->set('data.company_npwp', '01.234.567.8-901.000')
            ->set('data.company_address', 'Jl. Contoh No. 1, Jakarta')
            ->set('data.company_klu', '45209')
            ->call('save')
            ->assertHasNoErrors();

        $profile = FinanceSetting::companyProfile();
        $this->assertSame('PT Contoh Ginnva', $profile['name']);
        $this->assertSame('01.234.567.8-901.000', $profile['npwp']);
        $this->assertSame('45209', $profile['klu']);
    }

    public function test_an_invalid_npwp_is_rejected_and_nothing_is_saved(): void
    {
        $this->actingAs($this->admin(), 'web');

        Livewire::test(FinanceSettingsPage::class)
            ->set('data.company_npwp', '12345')
            ->call('save')
            ->assertHasErrors(['data.company_npwp']);

        $this->assertNull(FinanceSetting::companyProfile()['npwp']);
    }

    public function test_npwp_accepts_15_or_16_digits_with_separators(): void
    {
        $this->assertTrue(FinanceSetting::isValidNpwp('01.234.567.8-901.000'));
        $this->assertTrue(FinanceSetting::isValidNpwp('0123456789012345'));
        $this->assertFalse(FinanceSetting::isValidNpwp('12345'));
        $this->assertFalse(FinanceSetting::isValidNpwp(''));
    }

    // ------------------------------------------------------------- status periode

    public function test_period_status_reports_this_month_and_last_month(): void
    {
        $admin = $this->admin('Spv Finance');
        AccountingPeriod::create(['period_month' => '2026-09-01', 'closed_by' => $admin->id, 'closed_at' => '2026-10-03 10:00:00']);

        [$current, $previous] = PeriodStatus::summary(now());

        $this->assertFalse($current['closed'], 'Oktober tidak punya baris, jadi terbuka.');
        $this->assertTrue($previous['closed']);
        $this->assertSame('Spv Finance', $previous['closed_by']);
    }

    public function test_the_bar_applies_only_inside_the_finance_cluster(): void
    {
        $this->assertTrue(PeriodStatus::appliesToPath('admin', 'keuangan', 'admin/keuangan'));
        $this->assertTrue(PeriodStatus::appliesToPath('admin', 'keuangan', 'admin/keuangan/laporan-laba-rugi'));
        $this->assertFalse(PeriodStatus::appliesToPath('admin', 'keuangan', 'admin/keuangan-lain'));
        $this->assertFalse(PeriodStatus::appliesToPath('admin', 'keuangan', 'admin/bookings'));
        $this->assertFalse(PeriodStatus::appliesToPath('admin', 'keuangan', 'admin'));
    }

    public function test_the_bar_shows_on_a_finance_page_but_not_on_the_dashboard(): void
    {
        $this->actingAs($this->admin(), 'web');

        $this->get(FinanceSettingsPage::getUrl())->assertOk()->assertSee('Status buku');
        $this->get('/admin')->assertOk()->assertDontSee('Status buku');
    }

    // ------------------------------------------------------------- duplikat

    public function test_duplicating_an_expense_prefills_the_create_form(): void
    {
        $this->actingAs($this->admin(), 'web');
        $category = FinanceCategory::create(['name' => 'Beban ATK', 'type' => 'out', 'chart_of_account_id' => ChartOfAccount::where('code', '6410')->value('id'), 'is_active' => true]);
        $source = FinanceTransaction::create([
            'type' => 'out', 'finance_category_id' => $category->id, 'store_id' => $this->store->id, 'amount' => 150000,
            'transaction_date' => '2026-09-10', 'description' => 'ATK bulanan', 'created_by' => auth()->id(),
        ]);

        Livewire::withQueryParams(['duplikat' => $source->id])->test(CreateFinanceTransaction::class)
            ->assertFormSet([
                'finance_category_id' => $category->id,
                'description' => 'ATK bulanan',
            ])
            // Kolom tanggal Filament menyimpan jam juga ("2026-10-15 09:00:00"); yang dipastikan: hari ini, bukan tanggal transaksi lama.
            ->assertFormSet(fn (array $state) => str_starts_with((string) $state['transaction_date'], '2026-10-15'));
    }

    public function test_without_the_duplicate_parameter_the_form_stays_empty(): void
    {
        $this->actingAs($this->admin(), 'web');

        Livewire::test(CreateFinanceTransaction::class)->assertFormSet(['description' => null]);
    }

    public function test_an_unknown_duplicate_id_is_ignored(): void
    {
        $this->actingAs($this->admin(), 'web');

        Livewire::withQueryParams(['duplikat' => 999999])->test(CreateFinanceTransaction::class)
            ->assertSuccessful()
            ->assertFormSet(['description' => null]);
    }

    public function test_the_duplicate_button_links_to_the_create_page_with_the_record(): void
    {
        $this->actingAs($this->admin(), 'web');
        $category = FinanceCategory::create(['name' => 'Beban ATK', 'type' => 'out', 'chart_of_account_id' => ChartOfAccount::where('code', '6410')->value('id'), 'is_active' => true]);
        $source = FinanceTransaction::create([
            'type' => 'out', 'finance_category_id' => $category->id, 'store_id' => $this->store->id, 'amount' => 1000,
            'transaction_date' => '2026-09-10', 'description' => 'x', 'created_by' => auth()->id(),
        ]);

        Livewire::test(ListFinanceTransactions::class)
            ->assertTableActionExists('duplicate')
            ->assertTableActionHasUrl('duplicate', FinanceTransactionResource::getUrl('create', ['duplikat' => $source->id]), $source);
    }

    public function test_duplicating_a_recurring_template_renames_it_and_skips_the_schedule(): void
    {
        $this->actingAs($this->admin(), 'web');
        $source = RecurringBillTemplate::create([
            'name' => 'Sewa Toko', 'supplier_name' => 'PT Properti Jaya', 'store_id' => $this->store->id,
            'chart_of_account_id' => ChartOfAccount::where('code', '6510')->value('id'),
            'amount' => 5000000, 'day_of_month' => 5, 'next_run_date' => '2026-10-05', 'end_date' => '2027-01-05', 'is_active' => true,
        ]);

        $data = RecurringBillTemplateResource::duplicateData($source);
        $this->assertSame('Sewa Toko (salinan)', $data['name']);
        $this->assertArrayNotHasKey('next_run_date', $data);
        $this->assertArrayNotHasKey('end_date', $data);

        Livewire::withQueryParams(['duplikat' => $source->id])->test(CreateRecurringBillTemplate::class)
            ->assertFormSet(['name' => 'Sewa Toko (salinan)', 'day_of_month' => 5]);
    }

    public function test_a_booking_copy_keeps_the_customer_and_vehicle_but_not_dates_or_money(): void
    {
        $booking = new Booking([
            'customer_name' => 'Budi', 'phone_number' => '081234567890', 'vehicle_plate' => 'B 1234 ABC', 'vehicle_brand' => 'Toyota',
            'store_id' => $this->store->id, 'service_type' => 'ppf', 'preferred_date' => '2026-10-20', 'status' => 'confirmed',
            'notes' => 'catatan lama', 'amount_received' => 500000,
        ]);

        $data = BookingResource::duplicateData($booking);

        $this->assertSame('Budi', $data['customer_name']);
        $this->assertSame('B 1234 ABC', $data['vehicle_plate']);
        $this->assertSame($this->store->id, $data['store_id']);
        foreach (['preferred_date', 'status', 'notes', 'amount_received', 'booking_number'] as $skipped) {
            $this->assertArrayNotHasKey($skipped, $data);
        }
    }

    // ------------------------------------------------------------- penanganan klaim

    private function warranty(): Warranty
    {
        return Warranty::create([
            'warranty_code' => 'GNV-PPF-T' . strtoupper(substr(uniqid(), -6)), 'customer_name' => 'Budi Santoso', 'phone_number' => '081234567890', 'car_plate' => 'B 1234 XYZ',
            'car_type' => 'Toyota Raize', 'product_series' => 'Ginnva PPF Pro', 'product_category' => 'ppf', 'installation_date' => '2026-04-20',
            'expiry_date' => '2031-04-20', 'dealer_name' => 'Toko A', 'store_id' => $this->store->id, 'customer_id' => null,
            'status' => 'active', 'review_status' => 'approved',
        ]);
    }

    private function claim(Warranty $warranty, array $overrides = []): WarrantyClaim
    {
        return WarrantyClaim::create(array_merge(['warranty_id' => $warranty->id, 'category' => 'product_warranty', 'description' => 'Film mengelupas', 'status' => 'pass'], $overrides));
    }

    public function test_resolving_an_approved_claim_records_the_result(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web');
        $warranty = $this->warranty();
        $claim = $this->claim($warranty);

        Livewire::test(ClaimsRelationManager::class, ['ownerRecord' => $warranty, 'pageClass' => EditWarranty::class])
            ->callTableAction('resolve', $claim, ['resolution' => 'repaired', 'resolution_notes' => 'Pasang ulang panel kap'])
            ->assertHasNoTableActionErrors();

        $claim->refresh();
        $this->assertSame('repaired', $claim->resolution);
        $this->assertSame('Pasang ulang panel kap', $claim->resolution_notes);
        $this->assertNotNull($claim->resolved_at);
        $this->assertSame($admin->id, $claim->handled_by, 'Tanpa penangan yang ditentukan, pencatat menjadi penangan.');
    }

    public function test_the_resolve_action_is_only_for_approved_unresolved_claims(): void
    {
        $this->actingAs($this->admin(), 'web');
        $warranty = $this->warranty();
        $pending = $this->claim($warranty, ['status' => 'pending']);
        $done = $this->claim($warranty, ['resolution' => 'replaced', 'resolved_at' => now()]);

        Livewire::test(ClaimsRelationManager::class, ['ownerRecord' => $warranty, 'pageClass' => EditWarranty::class])
            ->assertTableActionHidden('resolve', $pending)
            ->assertTableActionHidden('resolve', $done);
    }

    public function test_an_approved_claim_past_its_estimate_is_overdue_until_resolved(): void
    {
        $warranty = $this->warranty();

        $late = $this->claim($warranty, ['estimated_completion_date' => '2026-10-10']);
        $this->assertTrue($late->isOverdue());

        $onTime = $this->claim($warranty, ['estimated_completion_date' => '2026-10-20']);
        $this->assertFalse($onTime->isOverdue());

        $late->update(['resolution' => 'repaired']);
        $this->assertFalse($late->fresh()->isOverdue());

        $this->assertFalse($this->claim($warranty)->isOverdue(), 'Tanpa perkiraan selesai tidak ada keterlambatan.');
    }
}
