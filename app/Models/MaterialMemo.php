<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MaterialMemo extends Model
{
    use LogsActivity;

    protected $fillable = [
        'memo_number',
        'store_id',
        // Booking terkait -- OPSIONAL (diminta 2026-09-09, boleh diisi
        // kapan saja, tidak wajib saat memo dibuat), UNIQUE per booking
        // (selalu 1 booking = 1 memo; kalau ada tambahan barang di
        // tengah pekerjaan, memo yang SAMA diedit -- bukan bikin memo
        // baru). Dipakai supaya Detail Penjualan/View Booking bisa
        // menampilkan inventori yang terpakai. Lihat migrasi
        // 2026_09_09_000001.
        'booking_id',
        'vehicle_info',
        'spk_number',
        'notes',
        'created_by',
    ];

    /**
     * Format: MEMO-YYYYMMDD-XXXX (urut per hari). Dipanggil di dalam
     * transaction oleh controller supaya tidak race-condition dobel nomor.
     */
    public static function generateMemoNumber(): string
    {
        $datePart = now()->format('Ymd');
        $todayCount = static::where('memo_number', 'like', "MEMO-{$datePart}-%")->count();

        return sprintf('MEMO-%s-%04d', $datePart, $todayCount + 1);
    }

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

    public function items(): HasMany
    {
        return $this->hasMany(MaterialMemoItem::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['memo_number', 'store_id', 'booking_id', 'vehicle_info', 'spk_number', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
