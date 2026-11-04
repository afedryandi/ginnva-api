<?php

namespace Tests\Feature;

use App\Filament\Pages\PaymentBookReport;
use App\Models\Booking;
use App\Models\BookingSettlementPayment;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\DownPaymentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Buku Pembayaran: DP kini mencatat metode, kolom Rekening hanya saat Keuangan menyala, filter tersimpan di URL, nomor booking bisa
 * diklik, dan baris Total. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class PaymentBookReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin'));
    }

    private function booking(): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create([
            'booking_number' => 'BKG-PB-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $this->store->id, 'service_type' => 'PPF',
            'product_ppf' => true, 'preferred_date' => '2026-10-09', 'status' => 'confirmed',
        ]);
    }

    public function test_a_down_payment_keeps_its_method_and_joins_the_per_method_breakdown(): void
    {
        config(['features.finance' => false]);
        $booking = $this->booking();
        app(DownPaymentService::class)->receive($booking, 300000, null, null, null, 'transfer');
        BookingSettlementPayment::create(['booking_id' => $booking->id, 'method' => 'tunai', 'amount' => 200000, 'paid_at' => '2026-10-08']);
        $this->actingAs($this->admin(), 'web');

        $result = Livewire::test(PaymentBookReport::class)->instance()->getResult();

        $this->assertEquals(500000.0, $result['total']);
        $this->assertEquals(['Transfer Bank' => 300000.0, 'Tunai' => 200000.0], $result['byMethod']);
        $this->assertSame($booking->id, $result['rows']->firstWhere('kind', 'Uang Muka (DP)')['booking_id']);
    }

    public function test_an_unknown_method_is_refused_and_a_dp_without_method_shows_a_dash(): void
    {
        config(['features.finance' => false]);
        $booking = $this->booking();

        try {
            app(DownPaymentService::class)->receive($booking, 100000, null, null, null, 'bitcoin');
            $this->fail('Metode yang tidak dikenal harus ditolak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Metode pembayaran DP tidak dikenal', $e->getMessage());
        }

        app(DownPaymentService::class)->receive($booking, 100000, null, null);
        $this->actingAs($this->admin(), 'web');

        $rows = Livewire::test(PaymentBookReport::class)->instance()->getResult()['rows'];
        $this->assertCount(1, $rows, 'DP yang ditolak tidak tercatat.');
        $this->assertSame('—', $rows->first()['method']);
    }

    public function test_without_the_finance_module_the_account_column_is_gone_from_the_screen_and_the_export(): void
    {
        config(['features.finance' => false]);
        $booking = $this->booking();
        app(DownPaymentService::class)->receive($booking, 300000, null, null, null, 'tunai');
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(PaymentBookReport::class);
        $page->assertSee('Daftar pembayaran')->assertSee($booking->booking_number)->assertDontSee('Rekening')->assertDontSee('Kas di Tangan');

        $data = (fn () => $this->reportExportData())->call($page->instance());
        $this->assertSame(['Tanggal', 'Jenis', 'Referensi', 'Pelanggan', 'Metode', 'Nominal', 'Catatan'], $data['headings']);
        $this->assertSame(300000.0, $data['totals'][5]);
        $this->assertSame([5], $data['right']);
    }

    public function test_the_filters_come_from_the_url_and_bad_values_fall_back(): void
    {
        $this->actingAs($this->admin(), 'web');

        $fresh = Livewire::test(PaymentBookReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [Carbon::parse($fresh->get('data.from'))->toDateString(), Carbon::parse($fresh->get('data.to'))->toDateString()]);

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '2026-09-01'])->test(PaymentBookReport::class);
        $this->assertSame(['2026-10-01', '2026-10-01'], [Carbon::parse($bad->get('data.from'))->toDateString(), Carbon::parse($bad->get('data.to'))->toDateString()]);

        $reversed = Livewire::withQueryParams(['from' => '2026-09-30', 'to' => '2026-09-01'])->test(PaymentBookReport::class);
        $this->assertSame('2026-09-30', Carbon::parse($reversed->get('data.to'))->toDateString());

        $page = Livewire::test(PaymentBookReport::class)->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString(), '"Sampai" sebelum "Dari" dikoreksi.');

        $page->set('data.preset', 'last_month');
        $this->assertSame(['2026-09-01', '2026-09-30'], [Carbon::parse($page->get('data.from'))->toDateString(), Carbon::parse($page->get('data.to'))->toDateString()]);
    }
}