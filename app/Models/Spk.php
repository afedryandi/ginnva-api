<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Digitalisasi form kertas "Surat Perintah Kerja" (SPK) -- 2026-09-16,
 * V1 murni dokumen isi + cetak PDF (analog pola Invoice), lihat
 * migrasi create_spks_table untuk alasan lengkap & keterbatasan.
 */
class Spk extends Model
{
    use LogsActivity;
    use HasStoreScope;

    public const VEHICLE_TYPE_LABELS = [
        'sedan' => 'Sedan',
        'suv' => 'SUV',
        'mpv' => 'MPV',
        'jeep' => 'Jeep',
    ];

    public const FUEL_LEVEL_LABELS = [
        'e' => 'E',
        'quarter' => '1/4',
        'half' => '1/2',
        'three_quarter' => '3/4',
        'f' => 'F',
    ];

    public const DAMAGE_CODE_LABELS = [
        'C' => 'Cat Luka/Belang (Stone Chip, Repaint)',
        'B' => 'Baret Dalam',
        'P' => 'Penyok',
        'G' => 'Kaca Baret/Retak',
        'M' => 'Komponen Hilang',
        'OS' => 'Over Spray',
    ];

    /**
     * Daftar checklist bawaan form kertas asli -- dipakai
     * SpkService::create() buat mengisi awal tiap kategori supaya
     * staff tinggal centang, bukan ngetik ulang tiap kali. Tetap bisa
     * ditambah/dihapus manual lewat Repeater (lihat SpkResource).
     */
    public const DEFAULT_CHECKLIST = [
        'pekerjaan' => ['Full Detailing Car Care', 'PPF', 'Kaca Film'],
        'extra_service' => ['Watermark Remover', 'Exterior Detailing', 'Interior Detailing', 'Engine Detailing', 'Glass Cleaning', 'Wheel Cleaning'],
        'perlengkapan' => ['STNK', 'Ban Serep', 'Dongkrak + Tuas', 'Perkakas (Kunci Pas)', 'Karpet dalam', 'Headunit/Sound System', 'Karpet Bagasi', 'Tutup Velg'],
    ];

    protected $fillable = [
        'spk_number',
        'store_id',
        'booking_id',
        'customer_name',
        'phone_number',
        'address',
        'vehicle_plate',
        'vehicle_vin',
        'vehicle_brand',
        'vehicle_year',
        'vehicle_type',
        'vehicle_km',
        'fuel_level',
        'battery_note',
        'checked_in_at',
        'checked_out_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'vehicle_km' => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(SpkChecklistItem::class)->orderBy('sort_order');
    }

    public function damageMarks(): HasMany
    {
        return $this->hasMany(SpkDamageMark::class);
    }

    /**
     * Dipanggil di dalam DB::transaction() oleh SpkService supaya tidak
     * race-condition dobel nomor -- sama pola dengan
     * Invoice::generateNumberForStore().
     */
    public static function generateNumberForStore(int $storeId): string
    {
        $prefix = 'SPK/' . $storeId . '/' . now()->format('ymd') . '/';
        $todayCount = self::where('spk_number', 'like', $prefix . '%')->count();

        return $prefix . str_pad((string) ($todayCount + 1), 4, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['checked_in_at', 'checked_out_at', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('spk');
    }
}
