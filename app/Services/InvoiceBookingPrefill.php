<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingSaleLine;
use App\Models\BookingServiceType;

/**
 * Isian awal form Invoice yang ditarik dari sebuah Booking: pelanggan, referensi booking, dan baris produk. Semuanya hanya isian awal;
 * staf tetap bebas mengubah apa pun sebelum menyimpan (harga di booking tidak otomatis menjadi tagihan, dan kolom harga yang belum
 * diketahui sengaja dikosongkan supaya diisi manual).
 *
 * Baris produk, urutan prioritas:
 *  1. Rincian Harga booking (Catat Penjualan) kalau ada: satu baris per rincian, lengkap dengan harga dan potongannya.
 *  2. Kalau belum ada: satu baris per jenis layanan booking. PPF dengan model/cakupan/panelnya, Window Film per bagian kaca,
 *     Detailing dan Premium Wash. Harga dikosongkan (diisi manual). Detailing dan Premium Wash dari paket PPF Full Body
 *     berharga Rp 0 dengan keterangan "Termasuk paket PPF Full Body".
 */
class InvoiceBookingPrefill
{
    /** @return array<string, mixed> */
    public static function dataFor(Booking $booking): array
    {
        $booking->loadMissing(['customer', 'store', 'filmProduct', 'filmProducts.filmProduct', 'saleLines.filmProduct']);

        return [
            'store_id' => $booking->store_id,
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'customer_name' => $booking->customer_name ?: $booking->customer?->name,
            'customer_phone' => $booking->phone_number ?: $booking->customer?->phone_number,
            'customer_email' => $booking->customer?->email,
            'issue_date' => now()->toDateString(),
            'notes' => 'Referensi booking ' . $booking->booking_number
                . ($booking->vehicle_plate ? ' — ' . trim(($booking->vehicle_brand ? $booking->vehicle_brand . ' ' : '') . ($booking->vehicle_model ?? '') . ' ' . $booking->vehicle_plate) : ''),
            'items' => self::items($booking),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function items(Booking $booking): array
    {
        $fromSale = self::itemsFromSaleLines($booking);

        return $fromSale !== [] ? $fromSale : self::itemsFromServices($booking);
    }

    /** @return list<array<string, mixed>> */
    private static function itemsFromSaleLines(Booking $booking): array
    {
        $items = [];

        foreach ($booking->saleLines as $line) {
            $gross = (float) $line->quantity * (float) $line->unit_price;
            $label = BookingSaleLine::SERVICE_TYPES[$line->service_type] ?? $line->service_type;
            $name = trim($label . ($line->filmProduct ? ' — ' . $line->filmProduct->name : '') . ($line->position ? ' (' . $line->position . ')' : ''));

            $items[] = [
                'film_product_id' => $line->film_product_id,
                'name' => $name,
                'quantity' => (float) $line->quantity,
                'unit' => 'Unit',
                'price' => (float) $line->unit_price,
                'discount_percent' => $gross > 0 ? round((float) $line->discount_amount / $gross * 100, 2) : 0,
                'note' => $line->description,
            ];
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private static function itemsFromServices(Booking $booking): array
    {
        $items = [];
        $bonus = $booking->includesPpfBonus();

        foreach (BookingServiceType::FLAGS as $key => $column) {
            if (! $booking->{$column}) {
                continue;
            }

            $label = BookingServiceType::labelFor($key);

            if ($key === 'ppf') {
                $items = array_merge($items, self::ppfItems($booking, $label));
            } elseif ($key === 'kaca_film') {
                $items = array_merge($items, self::windowFilmItems($booking, $label));
            } elseif ($bonus) {
                // Detailing dan Premium Wash paket Full Body: tercatat di invoice tapi tidak ditagih terpisah.
                $items[] = self::line($label, null, 0.0, 'Termasuk paket PPF Full Body');
            } else {
                $items[] = self::line($label, null, null, null);
            }
        }

        if ($items === []) {
            $items[] = self::line($booking->service_type ?: 'Jasa', $booking->film_product_id, null, null);
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private static function ppfItems(Booking $booking, string $label): array
    {
        $area = match ($booking->ppf_area) {
            Booking::PPF_AREA_EXTERIOR => 'Eksterior',
            Booking::PPF_AREA_INTERIOR => 'Interior',
            default => null,
        };
        $scope = match ($booking->ppf_scope) {
            Booking::PPF_SCOPE_FULL_BODY => 'Full Body',
            Booking::PPF_SCOPE_PARTIAL => 'Part',
            default => null,
        };
        $title = trim($label . ($area ? ' ' . $area : '') . ($scope ? ' — ' . $scope : ''));

        $parts = $booking->filmProducts
            ->filter(fn ($row) => in_array($row->position, [...Booking::PPF_PARTS, Booking::PPF_ALL], true))
            ->values();

        // Beberapa varian (panel satu per satu, atau "Seluruh Bagian PPF" dengan lebih dari satu varian): satu baris per varian.
        if ($parts->isNotEmpty()) {
            return $parts->map(fn ($row) => self::line(
                $title . ' — ' . $row->position,
                $row->film_product_id,
                null,
                $row->filmProduct?->name,
            ))->all();
        }

        return [self::line($title, $booking->film_product_id, null, $booking->filmProduct?->name)];
    }

    /** @return list<array<string, mixed>> */
    private static function windowFilmItems(Booking $booking, string $label): array
    {
        $areas = $booking->filmProducts
            ->filter(fn ($row) => in_array($row->position, [...Booking::WF_AREAS, Booking::WF_ALL], true))
            ->values();

        if ($areas->isNotEmpty()) {
            return $areas->map(fn ($row) => self::line(
                $label . ' — ' . $row->position,
                $row->film_product_id,
                null,
                $row->filmProduct?->name,
            ))->all();
        }

        return [self::line($label, $booking->film_product_id, null, $booking->filmProduct?->name)];
    }

    /** @return array<string, mixed> */
    private static function line(string $name, ?int $filmProductId, ?float $price, ?string $note): array
    {
        return [
            'film_product_id' => $filmProductId,
            'name' => $name,
            'quantity' => 1,
            'unit' => 'Unit',
            'price' => $price,
            'discount_percent' => 0,
            'note' => $note,
        ];
    }
}
