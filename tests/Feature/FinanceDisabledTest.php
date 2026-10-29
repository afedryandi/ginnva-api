<?php

namespace Tests\Feature;

use App\Filament\Clusters\KeuanganCluster;
use App\Filament\Pages\TrialBalanceReport;
use App\Filament\Resources\JournalEntryResource;
use App\Filament\Resources\SupplierResource;
use App\Filament\Resources\TransactionApprovalRequestResource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Receivable;
use App\Models\Refund;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingPostingService;
use App\Services\DownPaymentService;
use App\Services\RefundService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pembukuan dipegang Accurate 5 (keputusan 2026-10-29), jadi modul Keuangan dimatikan dengan saklar FEATURE_FINANCE (config('features.finance')).
 * Dengan saklar mati: klaster Keuangan tidak bisa dibuka, endpoint /finance di aplikasi staf ditolak, dan fitur lain (booking, DP, refund, stok
 * terbuang, pelepasan aset, penggajian) tetap berjalan tetapi TIDAK membuat jurnal atau piutang. Laporan penjualan membaca bookings.sale_date.
 * Tes lain berjalan dengan saklar menyala (phpunit.xml).
 */
class FinanceDisabledTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        config(['features.finance' => false]);
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seed(ChartOfAccountSeeder::class);
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
    }

    private function admin(): User
    {
        return tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id]), fn (User $u) => $u->assignRole('super_admin'));
    }

    private function booking(array $overrides = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-OFF-' . strtoupper(uniqid()),
            'customer_id' => $customer->id,
            'store_id' => $this->store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->toDateString(),
            'status' => 'completed',
            'transaction_amount' => 2_000_000,
            'amount_received' => 1_500_000,
        ], $overrides));
    }

    public function test_the_finance_cluster_and_its_screens_are_closed_even_to_full_access(): void
    {
        $this->actingAs($this->admin(), 'web');

        $this->assertFalse(KeuanganCluster::canAccess());
        $this->assertFalse(JournalEntryResource::canViewAny());
        $this->assertFalse(TrialBalanceReport::canAccess());
    }

    public function test_the_finance_screens_open_again_when_the_switch_is_on(): void
    {
        config(['features.finance' => true]);
        $this->actingAs($this->admin(), 'web');

        $this->assertTrue(KeuanganCluster::canAccess());
        $this->assertTrue(JournalEntryResource::canViewAny());
    }

    public function test_supplier_and_transaction_approvals_stay_available(): void
    {
        $this->actingAs($this->admin(), 'web');

        $this->assertTrue(SupplierResource::canViewAny());
        $this->assertTrue(TransactionApprovalRequestResource::canViewAny());
        $this->assertNotSame(KeuanganCluster::class, SupplierResource::getCluster());
        $this->assertNotSame(KeuanganCluster::class, TransactionApprovalRequestResource::getCluster());
    }

    public function test_the_staff_app_finance_endpoints_are_refused_and_the_menu_is_hidden(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'api')->getJson('/api/staff/finance/overview')->assertNotFound();
        $this->actingAs($admin, 'api')->getJson('/api/staff/finance/approvals')->assertNotFound();

        config(['features.finance' => true]);
        $this->actingAs($admin, 'api')->getJson('/api/staff/finance/overview')->assertSuccessful();
    }

    public function test_processing_a_sale_creates_no_journal_or_receivable_but_records_the_sale_date(): void
    {
        $booking = $this->booking();

        $this->assertNull(app(BookingPostingService::class)->sync($booking));

        $booking->refresh();
        $this->assertNull($booking->journal_entry_id);
        $this->assertSame(now()->toDateString(), $booking->sale_date->toDateString());
        $this->assertTrue($booking->isSaleProcessed());
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->count());
        $this->assertSame(0, Receivable::withoutGlobalScopes()->count());
    }

    public function test_clearing_the_amount_clears_the_sale_date(): void
    {
        $booking = $this->booking();
        app(BookingPostingService::class)->sync($booking);

        $booking->update(['transaction_amount' => null, 'amount_received' => null]);
        app(BookingPostingService::class)->sync($booking->fresh());

        $this->assertNull($booking->fresh()->sale_date);
        $this->assertFalse($booking->fresh()->isSaleProcessed());
    }

    public function test_sold_scopes_follow_the_sale_date(): void
    {
        $inRange = $this->booking();
        $inRange->update(['sale_date' => '2026-10-10']);
        $outside = $this->booking();
        $outside->update(['sale_date' => '2026-09-10']);
        $unprocessed = $this->booking(['transaction_amount' => null, 'amount_received' => null]);

        $this->assertSame([$inRange->id], Booking::query()->soldBetween('2026-10-01', '2026-10-31')->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$inRange->id, $outside->id], Booking::query()->sold()->pluck('id')->all());
        $this->assertSame([$unprocessed->id], Booking::query()->unsold()->pluck('id')->all());
        $this->assertSame([$outside->id], Booking::query()->soldBefore('2026-10-01')->pluck('id')->all());
    }

    public function test_a_down_payment_is_recorded_without_a_journal_and_can_be_refunded(): void
    {
        $booking = $this->booking(['status' => 'confirmed', 'transaction_amount' => null, 'amount_received' => null]);
        $service = app(DownPaymentService::class);

        $dp = $service->receive($booking, 500_000, 'DP awal', null);

        $this->assertNull($dp->journal_entry_id);
        $this->assertNull($dp->payment_account_id);
        $this->assertEquals(500_000, (float) $booking->fresh()->outstanding_down_payment);

        $service->refund($dp, null, 'Batal');

        $this->assertNotNull($dp->fresh()->refunded_at);
        $this->assertNull($dp->fresh()->refund_journal_entry_id);
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->count());
    }

    public function test_a_refund_needs_a_processed_sale_and_creates_no_journal(): void
    {
        $unprocessed = $this->booking(['transaction_amount' => null, 'amount_received' => null]);

        try {
            app(RefundService::class)->process($unprocessed, 100_000, 'Salah', null);
            $this->fail('Refund booking yang belum diproses seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('belum diproses', $e->getMessage());
        }

        $booking = $this->booking();
        app(BookingPostingService::class)->sync($booking);

        $refund = app(RefundService::class)->process($booking->fresh(), 300_000, 'Sebagian', null);

        $this->assertInstanceOf(Refund::class, $refund);
        $this->assertNull($refund->journal_entry_id);
        $this->assertEquals(0.0, (float) $refund->receivable_reduced);
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->count());
    }

    public function test_a_refund_cannot_exceed_the_money_received(): void
    {
        $booking = $this->booking(['transaction_amount' => 2_000_000, 'amount_received' => 500_000]);
        app(BookingPostingService::class)->sync($booking);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/melebihi uang yang sudah diterima/');

        app(RefundService::class)->process($booking->fresh(), 800_000, null, null);
    }
}
