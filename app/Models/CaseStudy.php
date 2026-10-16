<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CaseStudy extends Model
{
    // Audit trail (audit Galeri Pemasangan 2026-09-29) -- SEBELUMNYA perubahan galeri (foto,
    // judul, kendaraan/produk terkait, aktif/nonaktif) tidak tercatat sama sekali, beda dari
    // CustomerGalleryPhoto (fitur serupa) yang sudah punya ini.
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['vehicle_id', 'film_product_id', 'booking_id', 'marketing_consent_id', 'service_type', 'title', 'short_title', 'alt_text', 'plate_blurred', 'sort_order', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('case_study');
    }

    protected $fillable = [
        'vehicle_id',
        'film_product_id',
        'booking_id',
        'marketing_consent_id',
        'service_type',
        'title',
        'short_title',
        'image',
        'before_image',
        'alt_text',
        'plate_blurred',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'plate_blurred' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        // Pengaman terakhir: materi dari booking tidak boleh tayang tanpa izin aktif, plat tertutup stiker di foto, dan teks alternatif.
        static::saving(function (CaseStudy $case) {
            if ($case->is_active && ($blocker = $case->activationBlocker())) {
                throw \Illuminate\Validation\ValidationException::withMessages(['is_active' => $blocker]);
            }
        });
    }

    /**
     * Syarat sebelum materi dari booking boleh tayang (null = boleh). Materi lama tanpa booking tidak dibatasi.
     */
    public function activationBlocker(): ?string
    {
        if (! $this->booking_id) {
            return null;
        }

        if (! $this->marketing_consent_id || ! $this->consent?->isActive()) {
            return 'Pelanggan belum memberi izin, atau izinnya sudah ditarik. Foto ini tidak boleh tayang.';
        }

        if (! $this->plate_blurred) {
            return 'Pastikan plat nomor di foto tertutup stiker (bukan terlihat), lalu centang "Plat nomor tertutup stiker di foto".';
        }

        if (blank($this->alt_text)) {
            return 'Isi teks alternatif foto (deskripsi singkat isi gambar).';
        }

        return null;
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function consent()
    {
        return $this->belongsTo(MarketingConsent::class, 'marketing_consent_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function filmProduct()
    {
        // withTrashed(): studi kasus tetap menampilkan nama produknya walau produk itu sudah dihapus dari katalog aktif
        // (sama dengan relasi produk di Booking / roll / quotation).
        return $this->belongsTo(FilmProduct::class)->withTrashed();
    }
}