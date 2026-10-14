<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Katalog layanan booking (Fase 2 pengerjaan ulang booking, 2026-10-16). Empat kunci tetap; tiap kunci terhubung ke satu
 * kolom penanda produk di tabel bookings (lihat FLAGS). Nama, deskripsi, kategori kapasitas, lama pengerjaan, urutan, dan
 * aktif bisa diubah dari Filament (menu Booking → Layanan Booking).
 *
 * Kategori kapasitas: "berat" (mis. PPF, kaca film) memakai kapasitas harian toko; "ringan" (mis. detailing, cuci premium)
 * memakai slot jam. Booking yang berisi layanan berat DAN ringan dihitung sebagai berat.
 */
class BookingServiceType extends Model
{
    use LogsActivity;

    public const CATEGORY_HEAVY = 'berat';
    public const CATEGORY_LIGHT = 'ringan';

    /** Kunci layanan → kolom penanda produk di tabel bookings. */
    public const FLAGS = [
        'kaca_film' => 'product_kaca_film',
        'ppf' => 'product_ppf',
        'detailing' => 'product_detailing',
        'premium_wash' => 'product_premium_wash',
    ];

    /** Cadangan bila tabel kosong (belum dimigrasi / baris terhapus langsung dari database). */
    private const DEFAULTS = [
        'kaca_film' => ['label' => 'Kaca Film (Window Film)', 'category' => self::CATEGORY_HEAVY],
        'ppf' => ['label' => 'Pelindung Cat (PPF)', 'category' => self::CATEGORY_HEAVY],
        'detailing' => ['label' => 'Detailing', 'category' => self::CATEGORY_LIGHT],
        'premium_wash' => ['label' => 'Premium Wash', 'category' => self::CATEGORY_LIGHT],
    ];

    private const CACHE_KEY = 'booking_service_types.v1';

    protected $fillable = ['key', 'label', 'description', 'capacity_category', 'estimated_duration_days', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'estimated_duration_days' => 'integer',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        $flush = fn () => Cache::forget(self::CACHE_KEY);

        static::saved($flush);
        static::deleted($flush);
    }

    /**
     * Semua layanan berurutan (dari cache 5 menit; dibersihkan otomatis saat ada perubahan).
     *
     * @return Collection<int, static>
     */
    public static function catalog(): Collection
    {
        return Cache::remember(self::CACHE_KEY, 300, fn () => static::query()->orderBy('sort_order')->orderBy('id')->get());
    }

    /** @return Collection<int, static> */
    public static function activeCatalog(): Collection
    {
        return static::catalog()->where('is_active', true)->values();
    }

    public static function labelFor(string $key): string
    {
        return static::catalog()->firstWhere('key', $key)?->label ?? (self::DEFAULTS[$key]['label'] ?? $key);
    }

    public static function categoryFor(string $key): string
    {
        return static::catalog()->firstWhere('key', $key)?->capacity_category ?? (self::DEFAULTS[$key]['category'] ?? self::CATEGORY_HEAVY);
    }

    /**
     * Kolom penanda produk (di tabel bookings) milik layanan dengan kategori ini.
     *
     * @return array<int, string>
     */
    public static function flagColumnsFor(string $category): array
    {
        $columns = [];

        foreach (self::FLAGS as $key => $column) {
            if (static::categoryFor($key) === $category) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['label', 'description', 'capacity_category', 'estimated_duration_days', 'sort_order', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('booking_service_type')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'updated' => "Layanan booking \"{$this->label}\" diubah",
                default => "Layanan booking \"{$this->label}\" — {$eventName}",
            });
    }
}
