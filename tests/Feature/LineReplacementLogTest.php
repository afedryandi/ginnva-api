<?php

namespace Tests\Feature;

use App\Filament\Resources\NotificationCampaignResource\Pages\ListNotificationCampaigns;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\NotificationCampaign;
use App\Models\NotificationTemplate;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingSaleLineService;
use App\Services\BookingSettlementPaymentService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Histori Aktivitas Fase 3 (2026-10-21): rincian penjualan, pembayaran pelunasan, dan item invoice dicatat satu entri per
 * penggantian pada rekaman induknya; kampanye dan templat notifikasi dicatat otomatis.
 */
class LineReplacementLogTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Mail::fake();
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function booking(): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $this->store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-11-03', 'status' => 'completed',
            'transaction_amount' => 2000000, 'amount_received' => 0,
        ]);
    }

    private function logs(string $logName)
    {
        return Activity::where('log_name', $logName)->orderBy('id')->get();
    }

    // ------------------------------------------------------------- rincian penjualan

    public function test_a_sale_line_replacement_is_logged_once_on_the_booking_with_before_and_after(): void
    {
        $booking = $this->booking();
        $service = app(BookingSaleLineService::class);
        $rows = fn (int $price) => $service->normalize([['service_type' => 'ppf', 'description' => 'Hood', 'quantity' => 1, 'unit_price' => $price]]);

        $service->replace($booking, $rows(1200000));
        $service->replace($booking, $rows(1200000)); // sama persis: tidak dicatat lagi
        $service->replace($booking, $rows(1350000));
        $service->replace($booking, []);

        $logs = $this->logs('booking_sale_line');
        $this->assertCount(3, $logs);
        $this->assertSame([Booking::class, $booking->id], [$logs[0]->subject_type, $logs[0]->subject_id], 'Tampil di tab Riwayat booking.');
        $this->assertSame('updated', $logs[0]->event);

        $this->assertStringStartsWith('Rincian penjualan diisi: 1 baris, Rp 1.200.000', $logs[0]->description);
        $this->assertSame('PPF - Hood: 1 x Rp 1.200.000 = Rp 1.200.000', $logs[0]->properties['attributes']['Baris 1']);

        $this->assertSame('Rincian penjualan diubah: 1 baris, Rp 1.350.000 (sebelumnya 1 baris, Rp 1.200.000)', $logs[1]->description);
        $this->assertSame('PPF - Hood: 1 x Rp 1.200.000 = Rp 1.200.000', $logs[1]->properties['old']['Baris 1']);
        $this->assertSame('PPF - Hood: 1 x Rp 1.350.000 = Rp 1.350.000', $logs[1]->properties['attributes']['Baris 1']);

        $this->assertStringStartsWith('Rincian penjualan dikosongkan', $logs[2]->description);
        $this->assertArrayNotHasKey('Baris 1', $logs[2]->properties['attributes'] ?? []);
    }

    public function test_discounts_and_quantities_are_readable_in_the_line_text(): void
    {
        $booking = $this->booking();
        $service = app(BookingSaleLineService::class);

        $service->replace($booking, $service->normalize([['service_type' => 'detailing', 'description' => 'Poles', 'quantity' => 2.5, 'unit_price' => 100000, 'discount_amount' => 50000]]));

        $this->assertSame('Detailing - Poles: 2,5 x Rp 100.000 - potongan Rp 50.000 = Rp 200.000', $this->logs('booking_sale_line')->sole()->properties['attributes']['Baris 1']);
    }

    // ------------------------------------------------------------- pembayaran pelunasan

    public function test_a_payment_replacement_is_logged_on_the_booking(): void
    {
        $booking = $this->booking();
        $service = app(BookingSettlementPaymentService::class);
        $method = array_key_first(Booking::PAYMENT_METHOD_LABELS);

        $service->replace($booking, $service->normalize([['method' => $method, 'amount' => 500000]]), null);
        $service->replace($booking, $service->normalize([['method' => $method, 'amount' => 500000]]), null);
        $service->replace($booking, $service->normalize([['method' => $method, 'amount' => 750000]]), null);

        $logs = $this->logs('booking_settlement_payment');
        $this->assertCount(2, $logs, 'Penggantian yang isinya sama tidak dicatat.');
        $this->assertSame($booking->id, $logs[0]->subject_id);
        $this->assertSame("{$method}: Rp 500.000", $logs[0]->properties['attributes']['Baris 1']);
        $this->assertSame('Pembayaran pelunasan diubah: 1 baris, Rp 750.000 (sebelumnya 1 baris, Rp 500.000)', $logs[1]->description);
    }

    // ------------------------------------------------------------- item invoice

    public function test_invoice_item_changes_are_logged_on_the_invoice(): void
    {
        $service = app(InvoiceService::class);
        $items = [
            ['name' => 'PPF Full Body', 'quantity' => 1, 'unit' => 'paket', 'price' => 10000000, 'discount_percent' => 10],
            ['name' => 'Kaca Film', 'quantity' => 2, 'unit' => 'set', 'price' => 1500000],
        ];
        $invoice = $service->create(['store_id' => $this->store->id, 'customer_name' => 'Budi', 'issue_date' => '2026-11-03'], $items, null);

        $service->update($invoice->fresh(), [], $items); // sama persis
        $service->update($invoice->fresh(), [], [['name' => 'Jasa Detailing', 'quantity' => 1, 'unit' => 'paket', 'price' => 2000000]]);

        $log = $this->logs('invoice_item')->sole();
        $this->assertSame([Invoice::class, $invoice->id], [$log->subject_type, $log->subject_id]);
        $this->assertSame('Item invoice diubah: 1 baris, Rp 2.000.000 (sebelumnya 2 baris, Rp 12.000.000)', $log->description);
        $this->assertSame('PPF Full Body: 1 paket x Rp 10.000.000 - diskon 10% = Rp 9.000.000', $log->properties['old']['Baris 1']);
        $this->assertSame('Kaca Film: 2 set x Rp 1.500.000 = Rp 3.000.000', $log->properties['old']['Baris 2']);
        $this->assertSame('Jasa Detailing: 1 paket x Rp 2.000.000 = Rp 2.000.000', $log->properties['attributes']['Baris 1']);
    }

    // ------------------------------------------------------------- kampanye dan templat

    public function test_campaigns_and_templates_are_logged_automatically(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]);
        // Pelaku dibaca dari guard bawaan aplikasi ('api'), sama seperti pada permintaan nyata.
        $this->actingAs($admin, 'api');

        $campaign = NotificationCampaign::create(['audience' => 'customer', 'category' => 'info', 'title' => 'Libur', 'body' => 'Toko tutup', 'status' => 'scheduled', 'scheduled_at' => now()->addDay()]);
        $campaign->update(['status' => 'cancelled', 'message' => 'Dibatalkan oleh Admin.']);

        $template = NotificationTemplate::create(['name' => 'Libur', 'audience' => 'customer', 'category' => 'info', 'title' => 'Libur', 'body' => 'Toko tutup']);
        $template->update(['body' => 'Toko tutup 3 hari']);
        $template->delete();

        $campaignLogs = $this->logs('notification_campaign');
        $this->assertSame(['created', 'updated'], $campaignLogs->pluck('event')->all());
        $this->assertSame('Kampanye notifikasi "Libur" dibuat (terjadwal)', $campaignLogs[0]->description);
        $this->assertSame('Kampanye notifikasi "Libur" diubah: Dibatalkan', $campaignLogs[1]->description);
        $this->assertSame($admin->id, $campaignLogs[1]->causer_id);

        $this->assertSame(['created', 'updated', 'deleted'], $this->logs('notification_template')->pluck('event')->all());
    }
}
