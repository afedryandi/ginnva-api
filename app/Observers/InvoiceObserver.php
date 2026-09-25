<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\PushNotificationService;

/**
 * GAP DIPERBAIKI 2026-09-25 (audit Invoice) -- SEBELUMNYA tidak ada
 * notifikasi apa pun untuk siklus hidup Invoice (dibuat, ditandai
 * lunas/dibayar sebagian, di-void), padahal modul sebanding (Booking,
 * Quotation, SPK) semua sudah punya observer masing-masing. Pola SAMA
 * PERSIS dengan SpkObserver (diperbaiki lebih dulu sesi ini).
 */
class InvoiceObserver
{
    public function __construct(private PushNotificationService $push)
    {
    }

    /**
     * SENGAJA TANPA 'route' -- beda dari BookingObserver/SpkObserver dkk.
     * Tidak ada satu pun layar SPK/Booking-style di mobile app untuk
     * Invoice (staff mengelola invoice murni dari Filament, lihat catatan
     * class ini) — mengirim route ke layar yang tidak ada akan 404 di
     * Expo Router begitu staff tap notifikasinya (persis kelas bug yang
     * sudah ditemukan & diperbaiki di modul Quotation sesi ini: route
     * dikirim ke tujuan yang ternyata tidak bisa menanganinya). Push ini
     * murni informasi.
     */
    public function created(Invoice $invoice): void
    {
        if (! $invoice->store_id) {
            return;
        }

        $this->push->sendToStoreStaff(
            $invoice->store_id,
            'Invoice Baru Dibuat',
            "Invoice #{$invoice->invoice_number} untuk {$invoice->customer_name} sudah dibuat.",
            ['type' => 'invoice_new', 'invoice_id' => $invoice->id]
        );

        // Customer BOLEH dapat route -- portal invoice-nya baru dibangun
        // bareng observer ini (lihat Api\Customer\InvoiceController &
        // app/account/invoices/ di mobile), jadi rute ini genuinely ada
        // & bisa dibuka. 'draft' tidak pernah memicu notif ke customer
        // (invoice belum final, lihat catatan controller-nya).
        if ($invoice->customer_id && $invoice->status !== 'draft') {
            $this->push->sendToCustomer(
                $invoice->customer_id,
                'Invoice Baru',
                "Invoice #{$invoice->invoice_number} sudah diterbitkan untuk Anda.",
                ['type' => 'invoice_new', 'invoice_id' => $invoice->id, 'route' => "/account/invoices/{$invoice->id}"]
            );
        }
    }

    /**
     * Cuma peduli transisi STATUS (draft/unpaid -> paid, atau -> void) --
     * bukan tiap kali amount_paid berubah dikit (mis. pembayaran
     * bertahap yang belum melunasi total), supaya tidak spam notif untuk
     * tiap cicilan kecil.
     */
    public function updated(Invoice $invoice): void
    {
        if (! $invoice->wasChanged('status') || ! $invoice->store_id) {
            return;
        }

        $title = match ($invoice->status) {
            'paid'  => 'Invoice Lunas',
            'void'  => 'Invoice Di-void',
            default => null,
        };

        if (! $title) {
            return;
        }

        $body = $invoice->status === 'paid'
            ? "Invoice #{$invoice->invoice_number} untuk {$invoice->customer_name} sudah lunas."
            : "Invoice #{$invoice->invoice_number} untuk {$invoice->customer_name} telah di-void.";

        $this->push->sendToStoreStaff(
            $invoice->store_id,
            $title,
            $body,
            ['type' => 'invoice_' . $invoice->status, 'invoice_id' => $invoice->id]
        );
    }
}
