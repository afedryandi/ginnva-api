<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Satu-satunya jalur resmi create/update Invoice + item-itemnya (2026-09-15,
 * analog "Daftar Invoice" Majoo). V1 murni dokumen tagihan/cetak --
 * SENGAJA TIDAK posting ke Jurnal Umum (beda dari Booking/Proses
 * Referral), dan TIDAK ada perhitungan pajak/PPN sama sekali (masih
 * menunggu keputusan bisnis, lihat migrasi create_invoices_table).
 */
class InvoiceService
{
    /**
     * @param  array{store_id:int, booking_id:?int, customer_id:?int, customer_name:string, customer_phone:?string, customer_email:?string, billing_address:?string, shipping_address:?string, issue_date:string, due_date:?string, notes:?string, terms_conditions:?string, shipping_cost:?float, other_cost:?float, transaction_discount_type:?string, transaction_discount_value:?float}  $data
     * @param  array<int, array{film_product_id:?int, name:string, quantity:float, unit:string, price:float, discount_percent:?float, note:?string}>  $items
     *
     * @throws RuntimeException kalau tidak ada item sama sekali.
     */
    public function create(array $data, array $items, ?int $createdBy, string $status = 'unpaid'): Invoice
    {
        if (empty($items)) {
            throw new RuntimeException('Invoice harus punya minimal 1 baris produk.');
        }

        return DB::transaction(function () use ($data, $items, $createdBy, $status) {
            $invoiceNumber = Invoice::generateNumberForStore($data['store_id']);

            $invoice = Invoice::create(array_merge($data, [
                'invoice_number' => $invoiceNumber,
                'created_by' => $createdBy,
                'status' => $status,
            ]));

            foreach ($items as $item) {
                $lineTotal = $this->lineTotal($item);
                $invoice->items()->create(array_merge($item, ['total' => $lineTotal]));
            }

            $this->recalculateTotals($invoice);

            return $invoice->fresh('items');
        });
    }

    /**
     * @throws RuntimeException kalau invoice sudah paid/void (tidak
     *         boleh diubah lagi, sama filosofi dengan JournalEntry
     *         posted -- koreksi harus lewat void + invoice baru, bukan
     *         edit diam-diam).
     */
    public function update(Invoice $invoice, array $data, array $items): Invoice
    {
        if (! $invoice->isEditable()) {
            throw new RuntimeException('Invoice yang sudah Lunas/Void tidak bisa diedit lagi.');
        }

        if (empty($items)) {
            throw new RuntimeException('Invoice harus punya minimal 1 baris produk.');
        }

        return DB::transaction(function () use ($invoice, $data, $items) {
            $invoice->update($data);
            $invoice->items()->delete();

            foreach ($items as $item) {
                $lineTotal = $this->lineTotal($item);
                $invoice->items()->create(array_merge($item, ['total' => $lineTotal]));
            }

            $this->recalculateTotals($invoice);

            return $invoice->fresh('items');
        });
    }

    public function markPaid(Invoice $invoice): void
    {
        if (! in_array($invoice->status, ['unpaid', 'draft'], true)) {
            throw new RuntimeException('Invoice ini tidak dalam status yang bisa ditandai Lunas.');
        }

        $invoice->update(['status' => 'paid', 'amount_paid' => $invoice->total]);
    }

    public function void(Invoice $invoice): void
    {
        if ($invoice->status === 'void') {
            throw new RuntimeException('Invoice ini sudah Void.');
        }

        $invoice->update(['status' => 'void']);
    }

    private function lineTotal(array $item): float
    {
        $gross = (float) $item['quantity'] * (float) $item['price'];
        $discountPercent = (float) ($item['discount_percent'] ?? 0);

        return round($gross - ($gross * $discountPercent / 100), 2);
    }

    private function recalculateTotals(Invoice $invoice): void
    {
        $invoice->loadMissing('items');

        $subtotal = (float) $invoice->items->sum('total');

        $transactionDiscount = match ($invoice->transaction_discount_type) {
            'percent' => round($subtotal * (float) $invoice->transaction_discount_value / 100, 2),
            'rp' => (float) $invoice->transaction_discount_value,
            default => 0.0,
        };

        $total = max(0, round(
            $subtotal - $transactionDiscount + (float) $invoice->shipping_cost + (float) $invoice->other_cost,
            2
        ));

        $invoice->update([
            'subtotal' => $subtotal,
            'total' => $total,
        ]);
    }
}
