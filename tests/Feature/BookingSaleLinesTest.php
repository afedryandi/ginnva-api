<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSaleLine;
use App\Models\Customer;
use App\Models\FilmProduct;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingPostingService;
use App\Services\BookingRevenueSplitter;
use App\Services\BookingSaleLineService;
use App\Services\TransactionApprovalService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Penjualan Fase 1 (2026-11-10): rincian harga per layanan. Pendapatan per jenis layanan mengikuti nilai baris, bukan dibagi rata;
 * booking tanpa rincian tetap dibagi rata; jurnal, laporan, dan jalur persetujuan memakai rincian yang sama.
 */
class BookingSaleLinesTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        config(['booking.sale_lines_required_from' => null]);
        parent::tearDown();
    }

    private function booking(array $flags, float $amount = 1000000, array $overrides = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create(array_merge(
            ['product_ppf' => false, 'product_kaca_film' => false, 'product_detailing' => false, 'product_premium_wash' => false],
            $flags,
            [
                'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $this->store->id,
                'service_type' => 'PPF', 'preferred_date' => '2026-11-03', 'status' => 'completed',
                'transaction_amount' => $amount, 'amount_received' => $amount,
            ],
            $overrides
        ));
    }

    private function lines(Booking $booking, array $rows): void
    {
        $service = app(BookingSaleLineService::class);
        $service->replace($booking, $service->normalize($rows));
    }

    /** @return array<string, float> kode akun pendapatan => kredit */
    private function revenue(Booking $booking): array
    {
        return JournalEntry::findOrFail($booking->fresh()->journal_entry_id)->lines()->with('account')->get()
            ->filter(fn ($l) => str_starts_with($l->account->code, '4'))
            ->mapWithKeys(fn ($l) => [$l->account->code => (float) $l->credit])->all();
    }

    private function admin(): User
    {
        return tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin'));
    }

    public function test_revenue_is_split_by_line_value_not_equally(): void
    {
        $booking = $this->booking(['product_ppf' => true, 'product_premium_wash' => true], 15000000);
        $this->lines($booking, [
            ['service_type' => 'ppf', 'unit_price' => 15000000],
            ['service_type' => 'premium_wash', 'unit_price' => 150000],
        ]);

        $shares = BookingRevenueSplitter::shares($booking->fresh(), 15000000);

        $this->assertEqualsWithDelta(14851485.15, $shares['ppf'], 0.01);
        $this->assertEqualsWithDelta(148514.85, $shares['premium_wash'], 0.01);
        $this->assertEqualsWithDelta(15000000.0, array_sum($shares), 0.001, 'Jumlah bagian selalu persis nominal.');
    }

    public function test_a_booking_without_lines_is_still_split_equally(): void
    {
        $booking = $this->booking(['product_ppf' => true, 'product_premium_wash' => true], 1000000);

        $this->assertSame(['ppf' => 500000.0, 'premium_wash' => 500000.0], BookingRevenueSplitter::shares($booking, 1000000));
    }

    public function test_the_revenue_journal_follows_the_lines(): void
    {
        $booking = $this->booking(['product_ppf' => true, 'product_kaca_film' => true], 1000000);
        $this->lines($booking, [
            ['service_type' => 'ppf', 'unit_price' => 750000],
            ['service_type' => 'kaca_film', 'unit_price' => 250000],
        ]);

        app(BookingPostingService::class)->sync($booking->fresh());

        $this->assertEqualsWithDelta(['4100' => 750000.0, '4200' => 250000.0], $this->revenue($booking), 0.01);
    }

    public function test_a_transaction_level_discount_is_shared_in_proportion(): void
    {
        // Rincian 1.000.000 (PPF 800.000 + wash 200.000), nominal bersih 900.000 (nego 100.000): bagian ikut diskalakan.
        $booking = $this->booking(['product_ppf' => true, 'product_premium_wash' => true], 900000);
        $this->lines($booking, [
            ['service_type' => 'ppf', 'unit_price' => 800000],
            ['service_type' => 'premium_wash', 'unit_price' => 200000],
        ]);

        app(BookingPostingService::class)->sync($booking->fresh());

        $this->assertEqualsWithDelta(['4100' => 720000.0, '4600' => 180000.0], $this->revenue($booking), 0.01);
    }

    public function test_lines_decide_the_types_even_when_the_flags_disagree(): void
    {
        $booking = $this->booking(['product_ppf' => true], 500000);
        $this->lines($booking, [['service_type' => 'detailing', 'unit_price' => 500000]]);

        $this->assertSame(['detailing' => 500000.0], BookingRevenueSplitter::shares($booking->fresh(), 500000));
    }

    public function test_line_total_is_always_recomputed_and_reference_price_is_recorded(): void
    {
        $booking = $this->booking(['product_ppf' => true], 1000000, ['vehicle_size' => 'M']);
        $product = FilmProduct::create(['sku' => 'PPF-X', 'name' => 'PPF X', 'product_type' => 'ppf', 'position' => 'front', 'base_price' => 900000, 'is_active' => true]);

        $this->lines($booking, [['service_type' => 'ppf', 'film_product_id' => $product->id, 'quantity' => 2, 'unit_price' => 500000, 'discount_amount' => 100000]]);

        $line = $booking->saleLines()->first();
        $this->assertEquals(900000.0, (float) $line->line_total);
        $this->assertEquals(900000.0, (float) $line->reference_price);

        $line->update(['unit_price' => 600000]);
        $this->assertEquals(1100000.0, (float) $line->fresh()->line_total);
    }

    public function test_replace_overwrites_and_an_empty_list_clears(): void
    {
        $booking = $this->booking(['product_ppf' => true]);
        $this->lines($booking, [['service_type' => 'ppf', 'unit_price' => 100000], ['service_type' => 'detailing', 'unit_price' => 50000]]);
        $this->assertSame(2, $booking->saleLines()->count());

        $this->lines($booking, [['service_type' => 'ppf', 'unit_price' => 200000]]);
        $this->assertSame(1, $booking->saleLines()->count());

        $this->lines($booking, []);
        $this->assertSame(0, BookingSaleLine::where('booking_id', $booking->id)->count());
    }

    public function test_normalize_rejects_bad_rows_with_a_readable_message(): void
    {
        $service = app(BookingSaleLineService::class);

        foreach ([
            [['service_type' => 'ppf', 'unit_price' => -5], 'harga satuan'],
            [['service_type' => 'ppf', 'unit_price' => 'abc'], 'harga satuan'],
            [['service_type' => 'ppf', 'unit_price' => 100000, 'quantity' => 0], 'jumlah'],
            [['service_type' => 'ppf', 'unit_price' => 100000, 'discount_amount' => 150000], 'potongan'],
            [['service_type' => 'apa-ini', 'unit_price' => 100000], 'jenis layanan'],
            [['service_type' => '', 'unit_price' => 100000], 'jenis layanan'],
        ] as [$row, $needle]) {
            try {
                $service->normalize([$row]);
                $this->fail('Seharusnya ditolak: ' . json_encode($row));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }

        $this->assertSame([], $service->normalize([['service_type' => '', 'unit_price' => '']]), 'Baris kosong total diabaikan.');
    }

    public function test_required_only_for_bookings_created_on_or_after_the_configured_date(): void
    {
        $booking = $this->booking(['product_ppf' => true]);

        $this->assertFalse(BookingSaleLineService::isRequiredFor($booking), 'Bawaan: tidak wajib.');

        config(['booking.sale_lines_required_from' => '2026-11-10']);
        $this->assertTrue(BookingSaleLineService::isRequiredFor($booking));

        config(['booking.sale_lines_required_from' => '2026-11-11']);
        $this->assertFalse(BookingSaleLineService::isRequiredFor($booking), 'Booking yang dibuat sebelum tanggal berlaku tidak dipaksa.');
    }

    public function test_the_approval_path_saves_the_lines_before_posting(): void
    {
        $staff = tap(User::create(['name' => 'Staff', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id]), fn (User $u) => $u->assignRole('super_admin'));
        $booking = $this->booking(['product_ppf' => true, 'product_kaca_film' => true], 0, ['transaction_amount' => null, 'amount_received' => null]);
        $service = app(TransactionApprovalService::class);

        $request = $service->submitBookingReferral($booking, 1000000, 1000000, [
            'sale_lines' => [
                ['service_type' => 'ppf', 'unit_price' => 900000, 'quantity' => 1, 'discount_amount' => 0],
                ['service_type' => 'kaca_film', 'unit_price' => 100000, 'quantity' => 1, 'discount_amount' => 0],
            ],
        ], $staff->id);

        $this->assertArrayHasKey('sale_lines', $request->payload);

        $service->approve($request, $this->admin()->id);

        $this->assertSame(2, $booking->saleLines()->count());
        $this->assertEqualsWithDelta(['4100' => 900000.0, '4200' => 100000.0], $this->revenue($booking), 0.01);
    }

    public function test_the_reports_use_the_same_split(): void
    {
        $booking = $this->booking(['product_ppf' => true, 'product_premium_wash' => true], 1000000);
        $this->lines($booking, [
            ['service_type' => 'ppf', 'unit_price' => 900000],
            ['service_type' => 'premium_wash', 'unit_price' => 100000],
        ]);
        app(BookingPostingService::class)->sync($booking->fresh());

        $this->actingAs($this->admin(), 'web');
        $page = \Livewire\Livewire::test(\App\Filament\Pages\LayananReport::class)
            ->set('data.from', '2026-11-01')->set('data.to', '2026-11-30');
        $result = $page->instance()->getResult();

        $this->assertEqualsWithDelta(900000.0, $result['byType']['ppf']['revenue'], 0.01);
        $this->assertEqualsWithDelta(100000.0, $result['byType']['premium_wash']['revenue'], 0.01);
    }
}
