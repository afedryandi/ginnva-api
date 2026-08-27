<?php

namespace App\Support;

/**
 * Helper format nomor telepon — dipakai di lebih dari satu Filament
 * Resource (Quotation, Booking) untuk tombol "Hubungi via WhatsApp".
 * Diekstrak dari QuotationResource::toWhatsAppNumber() supaya tidak
 * duplikat logic saat BookingResource butuh hal yang sama. Logikanya
 * SAMA PERSIS dengan lib/phone.ts di mobile app — format wa.me (62xxx
 * tanpa +/0 depan). Lihat audit UI/UX Filament Booking 2026-08-27.
 */
class PhoneFormatter
{
    public static function toWhatsAppNumber(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            return '62' . substr($digits, 1);
        }

        return $digits;
    }
}
