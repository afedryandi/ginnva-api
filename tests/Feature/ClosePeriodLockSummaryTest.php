<?php

namespace Tests\Feature;

use App\Filament\Pages\ClosePeriodPage;
use App\Models\User;
use App\Services\AccountingPeriodService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bagian "Batas penguncian" di Tutup Periode: periode tertutup terbaru (siapa dan kapan) dan aktivitas tutup/buka kembali terakhir.
 */
class ClosePeriodLockSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function admin(string $name): User
    {
        return tap(User::create(['name' => $name, 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]), fn (User $u) => $u->assignRole('super_admin'));
    }

    public function test_it_says_so_when_nothing_has_been_closed(): void
    {
        $this->actingAs($this->admin('Direksi Satu'), 'web');

        $summary = Livewire::test(ClosePeriodPage::class)->assertSee('Batas penguncian')->instance()->getLockSummary();

        $this->assertNull($summary['latest_closed']);
        $this->assertNull($summary['last_event']);
    }

    public function test_it_names_the_latest_closed_month_and_who_closed_it(): void
    {
        $first = $this->admin('Penutup Pertama');
        $second = $this->admin('Penutup Kedua');
        $this->actingAs($first, 'web');
        $service = app(AccountingPeriodService::class);

        $older = now()->subMonthsNoOverflow(3)->startOfMonth();
        $newer = now()->subMonthsNoOverflow(2)->startOfMonth();
        $service->close($older, $first->id);
        $service->close($newer, $second->id);

        $page = Livewire::test(ClosePeriodPage::class);
        $summary = $page->instance()->getLockSummary();

        $this->assertSame($newer->toDateString(), $summary['latest_closed']->period_month->toDateString());
        $this->assertSame('Penutup Kedua', $summary['latest_closed']->closer->name);
        $this->assertSame('closed', $summary['last_event']->action);
        $page->assertSee('Periode tertutup terbaru')->assertSee('Penutup Kedua');
    }

    public function test_a_reopen_becomes_the_last_activity_and_moves_the_boundary_back(): void
    {
        $closer = $this->admin('Penutup');
        $reopener = $this->admin('Pembuka Kembali');
        $this->actingAs($closer, 'web');
        $service = app(AccountingPeriodService::class);

        $older = now()->subMonthsNoOverflow(3)->startOfMonth();
        $newer = now()->subMonthsNoOverflow(2)->startOfMonth();
        $service->close($older, $closer->id);
        $newerPeriod = $service->close($newer, $closer->id);
        $service->reopen($newerPeriod, $reopener->id, 'Koreksi jurnal');

        $summary = Livewire::test(ClosePeriodPage::class)->instance()->getLockSummary();

        $this->assertSame($older->toDateString(), $summary['latest_closed']->period_month->toDateString());
        $this->assertSame('reopened', $summary['last_event']->action);
        $this->assertSame('Pembuka Kembali', $summary['last_event']->user->name);
    }
}
