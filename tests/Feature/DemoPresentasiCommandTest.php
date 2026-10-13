<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\FinanceTransaction;
use App\Models\Payable;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\FinanceCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Perintah demo:presentasi (data demo lintas menu untuk presentasi) harus selesai tanpa langkah gagal, menghasilkan jurnal
 * yang seimbang, dan --cleanup harus mengembalikan semuanya.
 */
class DemoPresentasiCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(FinanceCategorySeeder::class);
        foreach (['installer', 'installer_leader', 'store_manager'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Store::create(['city' => 'Jakarta', 'address' => 'Jl. Demo 1', 'name' => 'Toko Demo A', 'is_active' => true]);
        Store::create(['city' => 'Bandung', 'address' => 'Jl. Demo 2', 'name' => 'Toko Demo B', 'is_active' => true]);
    }

    public function test_generate_builds_balanced_data_and_cleanup_removes_it(): void
    {
        $this->artisan('demo:presentasi', ['--skip-others' => true, '--bookings' => 16])
            ->doesntExpectOutputToContain('GAGAL')
            ->assertSuccessful();

        $this->assertGreaterThan(10, Booking::withoutGlobalScopes()->where('booking_number', 'like', 'DEMO-PRS-%')->count());
        $this->assertGreaterThan(0, Booking::withoutGlobalScopes()->where('booking_number', 'like', 'DEMO-PRS-%')->whereNotNull('journal_entry_id')->count());
        $this->assertGreaterThan(0, FinanceTransaction::where('description', 'like', '[DEMO]%')->count());
        $this->assertGreaterThan(0, Payable::withoutGlobalScopes()->where('notes', 'like', '[DEMO]%')->count());

        // Setiap jurnal yang dibuat harus seimbang.
        $unbalanced = DB::table('journal_entry_lines')
            ->select('journal_entry_id', DB::raw('SUM(debit) as d'), DB::raw('SUM(credit) as c'))
            ->groupBy('journal_entry_id')
            ->havingRaw('ROUND(SUM(debit), 2) <> ROUND(SUM(credit), 2)')
            ->count();
        $this->assertSame(0, $unbalanced);

        $this->artisan('demo:presentasi', ['--skip-others' => true, '--cleanup' => true])->assertSuccessful();

        $this->assertSame(0, Booking::withoutGlobalScopes()->where('booking_number', 'like', 'DEMO-PRS-%')->count());
        $this->assertSame(0, FinanceTransaction::where('description', 'like', '[DEMO]%')->count());
        $this->assertSame(0, Payable::withoutGlobalScopes()->where('notes', 'like', '[DEMO]%')->count());
        $this->assertSame(0, User::where('email', 'like', '%@presentasi.ginnva.test')->count());
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_refuses_to_run_twice_without_cleanup(): void
    {
        $this->artisan('demo:presentasi', ['--skip-others' => true, '--bookings' => 10])->assertSuccessful();

        $this->artisan('demo:presentasi', ['--skip-others' => true])->assertFailed();
    }
}
