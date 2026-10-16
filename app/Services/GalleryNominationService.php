<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingIntakePhoto;
use App\Models\CaseStudy;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Menominasikan hasil pekerjaan sebuah booking ke Galeri Pemasangan (Marketing/Konten Fase 3). Syarat: pelanggan memberi izin foto
 * (dan belum menariknya), ada foto akhir serah terima, dan booking itu belum pernah dinominasikan. Materi dibuat TIDAK tayang: foto
 * disalin apa adanya, jadi staf wajib memeriksa bahwa plat nomor di foto tertutup stiker (cara kerja perusahaan; sistem tidak mengaburkan
 * plat), mengisi teks alternatif, dan mencentang "plat tertutup stiker" sebelum bisa ditayangkan (lihat CaseStudy::activationBlocker()).
 */
class GalleryNominationService
{
    private const SERVICE_LABELS = [
        'kaca_film' => 'Kaca Film',
        'ppf' => 'PPF',
        'detailing' => 'Detailing',
        'premium_wash' => 'Premium Wash',
    ];

    public function nominate(Booking $booking, ?BookingIntakePhoto $beforePhoto = null): CaseStudy
    {
        $consent = $booking->photoConsent;

        if (! $consent || ! $consent->isActive()) {
            throw new RuntimeException('Pelanggan belum memberi izin foto untuk pemasaran, atau izinnya sudah ditarik.');
        }

        $handover = $booking->handover;

        if (! $handover || blank($handover->final_photo_path)) {
            throw new RuntimeException('Booking ini belum punya foto akhir serah terima.');
        }

        if (CaseStudy::where('booking_id', $booking->id)->exists()) {
            throw new RuntimeException('Booking ini sudah pernah dinominasikan ke galeri.');
        }

        if ($beforePhoto && $beforePhoto->booking_id !== $booking->id) {
            throw new RuntimeException('Foto sebelum bukan milik booking ini.');
        }

        $image = $this->copy($handover->final_photo_path);
        $before = $beforePhoto ? $this->copy($beforePhoto->path) : null;

        if (! $image) {
            throw new RuntimeException('Berkas foto akhir tidak ditemukan di penyimpanan.');
        }

        $service = $booking->serviceKeys()[0] ?? null;
        $vehicleName = trim("{$booking->vehicle_brand} {$booking->vehicle_model}");
        $label = self::SERVICE_LABELS[$service] ?? 'Pemasangan';

        $vehicle = ($booking->vehicle_brand && $booking->vehicle_model)
            ? Vehicle::where('brand', $booking->vehicle_brand)->where('model', $booking->vehicle_model)->first()
            : null;

        return CaseStudy::create([
            'booking_id' => $booking->id,
            'marketing_consent_id' => $consent->id,
            'vehicle_id' => $vehicle?->id,
            'film_product_id' => $booking->film_product_id,
            'service_type' => $service,
            'title' => trim("{$vehicleName} · {$label}", ' ·'),
            'short_title' => $vehicleName !== '' ? $vehicleName : $label,
            'image' => $image,
            'before_image' => $before,
            'plate_blurred' => false,
            'is_active' => false,
        ]);
    }

    /** Menyalin berkas ke folder galeri agar menghapus/mengganti di booking tidak merusak galeri; null bila berkas tidak ada. */
    private function copy(string $path): ?string
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return null;
        }

        $target = 'case-studies/' . uniqid('nominasi-', true) . '.' . pathinfo($path, PATHINFO_EXTENSION);
        $disk->copy($path, $target);

        return $target;
    }
}
