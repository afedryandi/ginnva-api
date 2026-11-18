<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingMessage;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Cetak PDF khusus satu Booking (2026-12-16), bergaya sama dengan cetakan SPK. Berisi data pelanggan dan kendaraan, jadwal dan layanan,
 * produk yang dipasang, tim installer, dan catatan. Sengaja TANPA angka harga, DP, dan pembayaran: itu ada di invoice booking.
 */
class BookingPdf
{
    private const STATUS_LABELS = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    public static function render(Booking $booking)
    {
        $booking->loadMissing(['store', 'customer', 'installers', 'filmProduct', 'filmProducts.filmProduct']);

        // Logo Ginnva (lambang + kata, 600 px). Jangan pakai ginnva-logo.png (4445 px): dompdf menguraikannya jadi puluhan MB.
        $logoPath = public_path('images/ginnva-wordmark-merah.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath)) : null;

        $stages = BookingMessage::allStages();
        $stageLine = collect([$booking->current_stage, $booking->secondary_stage])
            ->filter()
            ->map(fn (string $key) => $stages[$key] ?? $key)
            ->implode(' · ');

        $products = collect();

        // Produk utama (film_product_id) hanyalah salinan baris pertama "Produk per Bagian", jadi dipakai HANYA untuk booking lama yang belum
        // punya baris per bagian; kalau tidak, produk yang sama tampil dua kali.
        if ($booking->filmProduct && $booking->filmProducts->isEmpty()) {
            $products->push(['name' => trim(($booking->filmProduct->name ?? '') . ($booking->filmProduct->sku ? ' (' . $booking->filmProduct->sku . ')' : '')), 'position' => '-']);
        }

        foreach ($booking->filmProducts as $extra) {
            $product = $extra->filmProduct;
            $products->push([
                'name' => $product ? trim(($product->name ?? '') . ($product->sku ? ' (' . $product->sku . ')' : '')) : '-',
                'position' => $extra->position ?: '-',
            ]);
        }

        $services = collect([
            $booking->product_kaca_film ? 'Kaca Film' : null,
            $booking->product_ppf ? 'PPF' : null,
            $booking->product_detailing ? 'Full Detailing' : null,
            $booking->product_premium_wash ? 'Premium Wash' : null,
        ])->filter()->implode(', ');

        $vehicle = trim(implode(' ', array_filter([$booking->vehicle_brand, $booking->vehicle_model, $booking->vehicle_variant])));
        $startDate = $booking->preferred_date;
        $endDate = $booking->end_date ? \Illuminate\Support\Carbon::parse($booking->end_date) : null;

        $dateLine = $startDate ? $startDate->translatedFormat('l, d M Y') : '-';

        if ($startDate && $endDate && ! $endDate->isSameDay($startDate)) {
            $dateLine .= ' s.d. ' . $endDate->translatedFormat('d M Y');
        }

        return Pdf::loadView('pdf.booking_detail', [
            'booking' => $booking,
            'logo' => $logo,
            'statusLabel' => self::STATUS_LABELS[$booking->status] ?? ucfirst((string) $booking->status),
            'customerName' => $booking->customer_name ?: ($booking->customer?->name ?? '-'),
            'customerPhone' => $booking->phone_number ?: ($booking->customer?->phone_number ?? '-'),
            'vehicle' => $vehicle !== '' ? $vehicle : '-',
            'services' => $services !== '' ? $services : ($booking->service_type ?: '-'),
            'dateLine' => $dateLine,
            'durationLine' => $booking->duration_days ? $booking->duration_days . ' hari kerja' : '-',
            'installerNames' => $booking->installers->pluck('name')->implode(', '),
            'stageLine' => $stageLine,
            'products' => $products->all(),
            'printedBy' => auth()->user()?->name,
            'printedAt' => now()->locale('id')->translatedFormat('d F Y H:i'),
        ])->setPaper('a4', 'portrait');
    }
}
