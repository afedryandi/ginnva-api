<?php

namespace Tests\Feature;

use App\Filament\Pages\ClosePeriodPage;
use App\Filament\Resources\JournalEntryResource;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\JournalEntryService;
use App\Support\GuideCatalog;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Panduan Spv Finance (finance-harian) harus sama dengan wewenang yang benar-benar ditegakkan: hanya Spv Finance dan akses penuh
 * yang melihatnya, Tutup Periode tertutup untuk Spv Finance, dan jurnal buatan sendiri tidak bisa diposting sendiri.
 */
class FinanceRoleGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['spv_finance', 'store_manager', 'super_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(string $role, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x'], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function ids(User $user): array
    {
        return collect(GuideCatalog::forUser($user))->pluck('id')->all();
    }

    public function test_the_finance_guide_is_only_for_finance_supervisors_and_full_access(): void
    {
        $this->assertContains('finance-harian', $this->ids($this->user('spv_finance')));
        $this->assertContains('finance-harian', $this->ids($this->user('super_admin')));
        $this->assertNotContains('finance-harian', $this->ids($this->user('store_manager')));
    }

    public function test_close_period_stays_closed_to_finance_supervisors(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(ClosePeriodPage::canAccess());

        $this->actingAs($this->user('spv_finance'), 'web');
        $this->assertFalse(ClosePeriodPage::canAccess(), 'Panduan menyebut Tutup Periode hanya untuk akses penuh.');
    }

    public function test_a_finance_supervisor_cannot_post_their_own_journal_but_can_post_anothers(): void
    {
        $spv = $this->user('spv_finance', ['menu_permissions' => [JournalEntryResource::class => ['create', 'update', 'delete']]]);
        $other = $this->user('super_admin');
        $lines = [
            ['chart_of_account_id' => ChartOfAccount::where('code', '6210')->value('id'), 'debit' => 100000],
            ['chart_of_account_id' => ChartOfAccount::where('code', '1101')->value('id'), 'credit' => 100000],
        ];
        $service = app(JournalEntryService::class);
        $own = $service->create(['entry_date' => now()->toDateString(), 'description' => 'Milik sendiri', 'created_by' => $spv->id], $lines);
        $theirs = $service->create(['entry_date' => now()->toDateString(), 'description' => 'Milik orang lain', 'created_by' => $other->id], $lines);

        $this->actingAs($spv, 'web');
        $this->assertFalse(JournalEntryResource::canPost($own), 'Panduan menyebut jurnal buatan sendiri tidak bisa diposting sendiri.');
        $this->assertTrue(JournalEntryResource::canPost($theirs));
    }

    public function test_a_finance_supervisor_without_explicit_rights_cannot_create_journals(): void
    {
        $this->actingAs($this->user('spv_finance', ['menu_access' => ['JournalEntryResource']]), 'web');

        $this->assertFalse(JournalEntryResource::canCreate(), 'Panduan menyebut hak membuat jurnal tidak ada bawaan.');
    }
}
