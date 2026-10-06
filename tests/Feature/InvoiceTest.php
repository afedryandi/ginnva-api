<?php

namespace Tests\Feature;

use App\Filament\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Store;
use App\Models\User;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Invoice: perhitungan total (diskon baris & transaksi, ongkir, biaya lain),
 * status & pembayaran bertahap, aturan edit/void, nomor unik, portal
 * customer (tanpa draft, hanya milik sendiri, unduh PDF), dan halaman Filament.
 */
class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private InvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->service = app(InvoiceService::class);
    }

    private function data(array $overrides = []): array
    {
        return array_merge([
            'store_id'      => $this->store->id,
            'customer_name' => 'Budi',
            'issue_date'    => now()->toDateString(),
        ], $overrides);
    }

    private function items(): array
    {
        return [
            ['name' => 'PPF Full Body', 'quantity' => 1, 'unit' => 'paket', 'price' => 10_000_000, 'discount_percent' => 10],
            ['name' => 'Kaca Film', 'quantity' => 2, 'unit' => 'set', 'price' => 1_500_000],
        ];
    }

    private function invoice(array $dataOverrides = [], string $status = 'unpaid'): Invoice
    {
        return $this->service->create($this->data($dataOverrides), $this->items(), null, $status);
    }

    // ------------------------------------------------------------- perhitungan

    public function test_totals_apply_line_discount_and_costs(): void
    {
        // Baris 1: 10jt - 10% = 9jt; baris 2: 2 x 1,5jt = 3jt; subtotal 12jt.
        $invoice = $this->invoice(['shipping_cost' => 100_000, 'other_cost' => 50_000]);

        $this->assertEquals(12_000_000, (float) $invoice->subtotal);
        $this->assertEquals(12_150_000, (float) $invoice->total);
        $this->assertCount(2, $invoice->items);
        $this->assertEquals(9_000_000, (float) $invoice->items->firstWhere('name', 'PPF Full Body')->total);
    }

    public function test_transaction_discount_percent_and_rupiah(): void
    {
        $percent = $this->invoice(['transaction_discount_type' => 'percent', 'transaction_discount_value' => 5]);
        $this->assertEquals(11_400_000, (float) $percent->total); // 12jt - 5%

        $rupiah = $this->invoice(['transaction_discount_type' => 'rp', 'transaction_discount_value' => 500_000]);
        $this->assertEquals(11_500_000, (float) $rupiah->total);

        $tooBig = $this->invoice(['transaction_discount_type' => 'rp', 'transaction_discount_value' => 99_000_000]);
        $this->assertEquals(0.0, (float) $tooBig->total, 'Total tidak boleh negatif.');
    }

    public function test_invoice_requires_at_least_one_item(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->create($this->data(), [], null);
    }

    public function test_invoice_numbers_are_unique_and_sequential_per_store_per_day(): void
    {
        $numbers = collect(range(1, 3))->map(fn () => $this->invoice()->invoice_number)->all();

        $this->assertCount(3, array_unique($numbers));
        $this->assertStringStartsWith("INV/{$this->store->id}/", $numbers[0]);
        $this->assertStringEndsWith('0001', $numbers[0]);
        $this->assertStringEndsWith('0003', $numbers[2]);
    }

    // ------------------------------------------------------------- status & pembayaran

    public function test_installment_payments_accumulate_and_auto_mark_paid(): void
    {
        $invoice = $this->invoice(); // total 12jt

        $this->service->recordPayment($invoice, 5_000_000);
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertEquals(7_000_000, $invoice->fresh()->remainingAmount());

        $this->service->recordPayment($invoice->fresh(), 7_000_000);
        $fresh = $invoice->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertEquals(12_000_000, (float) $fresh->amount_paid);
    }

    public function test_payment_validation_and_overpayment_are_rejected(): void
    {
        $invoice = $this->invoice();

        try {
            $this->service->recordPayment($invoice, 0);
            $this->fail('Nominal 0 harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('lebih dari 0', $e->getMessage());
        }

        try {
            $this->service->recordPayment($invoice, 12_000_001);
            $this->fail('Kelebihan bayar harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('melebihi sisa tagihan', $e->getMessage());
        }

        $this->assertEquals(0.0, (float) $invoice->fresh()->amount_paid);
    }

    public function test_mark_paid_fills_full_amount_and_blocks_further_changes(): void
    {
        $invoice = $this->invoice();
        $this->service->markPaid($invoice);

        $fresh = $invoice->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertEquals((float) $fresh->total, (float) $fresh->amount_paid);

        foreach ([
            fn () => $this->service->markPaid($fresh),
            fn () => $this->service->recordPayment($fresh, 1),
            fn () => $this->service->update($fresh, $this->data(), $this->items()),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Invoice lunas tidak boleh diubah lagi.');
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_update_recalculates_totals_and_replaces_items(): void
    {
        $invoice = $this->invoice();

        $updated = $this->service->update($invoice, ['shipping_cost' => 0], [
            ['name' => 'Jasa Detailing', 'quantity' => 1, 'unit' => 'paket', 'price' => 2_000_000],
        ]);

        $this->assertCount(1, $updated->items);
        $this->assertEquals(2_000_000, (float) $updated->total);
    }

    public function test_void_is_final_and_idempotence_is_rejected(): void
    {
        $invoice = $this->invoice();
        $this->service->void($invoice);

        $this->assertSame('void', $invoice->fresh()->status);

        $this->expectException(RuntimeException::class);
        $this->service->void($invoice->fresh());
    }

    // ------------------------------------------------------------- portal customer

    public function test_customer_portal_lists_only_own_non_draft_invoices(): void
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $stranger = Customer::create(['name' => 'Siti', 'phone_number' => '0813' . random_int(1000000, 9999999)]);

        $mine = $this->invoice(['customer_id' => $customer->id]);
        $draft = $this->invoice(['customer_id' => $customer->id], 'draft');
        $others = $this->invoice(['customer_id' => $stranger->id]);

        $ids = collect($this->actingAs($customer, 'customer')->getJson('/api/customer/invoices')->assertSuccessful()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($draft->id), 'Draft tidak boleh terlihat customer.');
        $this->assertFalse($ids->contains($others->id));

        $this->actingAs($customer, 'customer')->getJson("/api/customer/invoices/{$mine->id}")
            ->assertSuccessful()->assertJsonPath('data.invoice_number', $mine->invoice_number);
        $this->actingAs($customer, 'customer')->getJson("/api/customer/invoices/{$draft->id}")->assertStatus(404);
        $this->actingAs($customer, 'customer')->getJson("/api/customer/invoices/{$others->id}")->assertStatus(404);
    }

    public function test_customer_can_download_own_invoice_pdf_only(): void
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $stranger = Customer::create(['name' => 'Siti', 'phone_number' => '0813' . random_int(1000000, 9999999)]);
        $mine = $this->invoice(['customer_id' => $customer->id]);

        $response = $this->actingAs($customer, 'customer')->get("/api/customer/invoices/{$mine->id}/download")->assertSuccessful();
        $this->assertStringStartsWith('%PDF', $response->streamedContent());

        $this->actingAs($stranger, 'customer')->get("/api/customer/invoices/{$mine->id}/download")->assertStatus(404);
    }

    public function test_invoice_portal_requires_login(): void
    {
        $this->getJson('/api/customer/invoices')->assertStatus(401);
    }

    public function test_pdf_template_renders(): void
    {
        $invoice = $this->invoice()->load(['items', 'store']);

        $output = Pdf::loadView('pdf.invoice', ['invoice' => $invoice])->setPaper('a4', 'portrait')->output();

        $this->assertStringStartsWith('%PDF', $output);
    }

    // ------------------------------------------------------------- Filament

    public function test_filament_pages_and_table_actions_work(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        $invoice = $this->invoice();

        Livewire::test(ListInvoices::class)->assertSuccessful()->assertCanSeeTableRecords([$invoice]);
        Livewire::test(CreateInvoice::class)->assertSuccessful();
        Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()])->assertSuccessful();

        Livewire::test(ListInvoices::class)
            ->callTableAction('record_payment', $invoice, data: ['amount' => 2_000_000]);
        $this->assertEquals(2_000_000, (float) $invoice->fresh()->amount_paid);

        Livewire::test(ListInvoices::class)->callTableAction('void', $invoice);
        $this->assertSame('void', $invoice->fresh()->status);
    }
}
