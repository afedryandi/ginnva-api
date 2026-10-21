<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\FilmProduct;
use App\Models\JournalEntry;
use App\Models\PaymentAccount;
use App\Models\Store;
use App\Models\TransactionApprovalRequest;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Penjualan Fase 5 (2026-11-13): API pencatatan penjualan dan pembayaran dari aplikasi staf. Aturan sama dengan Proses Referral di panel:
 * akses penuh langsung sah; staf lain masuk antrean persetujuan kecuali diskon dalam batas nego; pencatatan hanya sekali dan hanya untuk
 * booking selesai di tokonya.
 */
class BookingSaleApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['super_admin', 'kasir', 'installer', 'partner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->seed(ChartOfAccountSeeder::class);
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?Store $store = null): User
    {
        return tap(User::create(['name' => ucfirst($role), 'email' => $role . uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id]), fn (User $u) => $u->assignRole($role));
    }

    private function booking(array $overrides = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $this->store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-11-12', 'status' => 'completed',
        ], $overrides));
    }

    private function body(array $over = []): array
    {
        return array_merge([
            'transaction_amount' => 1000000,
            'sale_lines' => [['service_type' => 'ppf', 'quantity' => 1, 'unit_price' => 1000000]],
            'settlement_payments' => [['method' => 'tunai', 'amount' => 400000], ['method' => 'transfer', 'amount' => 600000]],
        ], $over);
    }

    private function sell(Booking $booking, array $body, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user('super_admin'), 'api')->postJson("/api/staff/bookings/{$booking->id}/sale", $body);
    }

    private function cashAccount(string $code, string $name, array $methods): PaymentAccount
    {
        $coa = ChartOfAccount::create([
            'code' => $code, 'name' => $name, 'type' => 'aset', 'normal_balance' => 'debit', 'parent_id' => ChartOfAccount::where('code', '1100')->value('id'),
            'is_postable' => true, 'is_active' => true, 'is_cash' => true, 'is_contra' => false,
        ]);

        return PaymentAccount::create(['name' => $name, 'kind' => 'bank', 'chart_of_account_id' => $coa->id, 'store_id' => null, 'methods' => $methods, 'is_active' => true]);
    }

    public function test_requires_login(): void
    {
        $booking = $this->booking();

        $this->getJson("/api/staff/bookings/{$booking->id}/sale")->assertStatus(401);
        $this->postJson("/api/staff/bookings/{$booking->id}/sale", [])->assertStatus(401);
    }

    public function test_show_returns_state_and_form_options(): void
    {
        $this->cashAccount('1104', 'BCA Operasional', ['transfer']);
        $booking = $this->booking(['product_ppf' => true, 'product_premium_wash' => true]);

        $data = $this->actingAs($this->user('kasir'), 'api')->getJson("/api/staff/bookings/{$booking->id}/sale")
            ->assertSuccessful()->json('data');

        $this->assertTrue($data['can_record']);
        $this->assertFalse($data['processed']);
        $this->assertSame(['ppf', 'premium_wash'], $data['suggested_service_types']);
        $this->assertArrayHasKey('transfer', $data['methods']);
        $this->assertSame('PPF', $data['service_types']['ppf']);
        $this->assertSame('BCA Operasional', $data['accounts'][0]['name']);
        $this->assertFalse($data['lines_required']);
    }

    public function test_full_access_records_directly_with_lines_payments_and_journal(): void
    {
        $this->cashAccount('1103', 'Kas Toko A', ['tunai']);
        $this->cashAccount('1104', 'BCA Operasional', ['transfer']);
        $booking = $this->booking();

        $response = $this->sell($booking, $this->body())->assertSuccessful();

        $response->assertJsonPath('status', 'posted');
        $booking = $booking->fresh();
        $this->assertNotNull($booking->journal_entry_id);
        $this->assertEquals(1000000.0, (float) $booking->transaction_amount);
        $this->assertSame(1, $booking->saleLines()->count());
        $this->assertSame(2, $booking->settlementPayments()->count());

        $debits = JournalEntry::findOrFail($booking->journal_entry_id)->lines()->with('account')->get()
            ->groupBy(fn ($l) => $l->account->code)->map(fn ($g) => round((float) $g->sum('debit'), 2))->filter(fn ($v) => $v > 0)->all();
        $this->assertEqualsWithDelta(['1103' => 400000.0, '1104' => 600000.0], $debits, 0.01);
    }

    public function test_a_clerk_with_a_large_discount_goes_to_the_approval_queue_and_posts_nothing(): void
    {
        $product = FilmProduct::create(['sku' => 'PPF-X', 'name' => 'PPF X', 'product_type' => 'ppf', 'position' => 'front', 'base_price' => 2000000, 'is_active' => true]);
        $booking = $this->booking(['film_product_id' => $product->id]);

        $this->sell($booking, $this->body(), $this->user('kasir'))
            ->assertSuccessful()
            ->assertJsonPath('status', 'pending');

        $this->assertNull($booking->fresh()->journal_entry_id);
        $this->assertSame(1, TransactionApprovalRequest::where('booking_id', $booking->id)->where('status', 'pending')->count());
    }

    public function test_a_clerk_within_the_negotiation_cap_records_directly(): void
    {
        $product = FilmProduct::create(['sku' => 'PPF-Y', 'name' => 'PPF Y', 'product_type' => 'ppf', 'position' => 'front', 'base_price' => 1000000, 'is_active' => true]);
        $booking = $this->booking(['film_product_id' => $product->id]);

        // 4% di bawah harga acuan 1.000.000: masih dalam batas nego 5%.
        $this->sell($booking, $this->body([
            'transaction_amount' => 960000,
            'sale_lines' => [['service_type' => 'ppf', 'quantity' => 1, 'unit_price' => 1000000]],
            'settlement_payments' => [['method' => 'tunai', 'amount' => 960000]],
        ]), $this->user('kasir'))
            ->assertSuccessful()
            ->assertJsonPath('status', 'posted');

        $this->assertNotNull($booking->fresh()->journal_entry_id);
    }

    public function test_overpayment_is_refused_with_a_title_and_saves_nothing(): void
    {
        $booking = $this->booking();

        $this->sell($booking, $this->body(['settlement_payments' => [['method' => 'tunai', 'amount' => 1200000]]]))
            ->assertStatus(422)
            ->assertJsonPath('title', 'Pembayaran belum benar');

        $this->assertNull($booking->fresh()->journal_entry_id);
        $this->assertSame(0, $booking->settlementPayments()->count());
    }

    public function test_required_lines_are_enforced_when_configured(): void
    {
        config(['booking.sale_lines_required_from' => '2026-11-01']);
        $booking = $this->booking();

        $this->sell($booking, $this->body(['sale_lines' => []]))
            ->assertStatus(422)
            ->assertJsonPath('title', 'Rincian harga wajib diisi');
    }

    public function test_it_records_only_once_and_only_for_completed_bookings(): void
    {
        $booking = $this->booking();
        $this->sell($booking, $this->body())->assertSuccessful();
        $this->sell($booking->fresh(), $this->body())->assertStatus(422);

        $pending = $this->booking(['status' => 'confirmed']);
        $this->sell($pending, $this->body())->assertStatus(422);
    }

    public function test_other_store_installer_and_partner_are_refused(): void
    {
        $booking = $this->booking();

        // Pembatas toko global pada Booking membuat booking toko lain tidak terlihat sama sekali: 404 (tidak membocorkan keberadaannya).
        $this->sell($booking, $this->body(), $this->user('kasir', $this->otherStore))->assertStatus(404);
        $this->sell($booking, $this->body(), $this->user('installer'))->assertStatus(403);
        $this->sell($booking, $this->body(), $this->user('partner'))->assertStatus(403);
        $this->assertNull($booking->fresh()->journal_entry_id);
    }
}
