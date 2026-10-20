<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Catatan pekerjaan ulang sebuah booking (Karyawan Fase 4 lanjutan, 2026-11-03). Toko diambil dari booking. Installer yang bertugas pada
 * booking itu dihitung lewat relasi booking (installers), jadi catatan tidak menyimpan siapa yang salah: pembinaan dibahas manusia, bukan
 * ditentukan sistem.
 */
class BookingRework extends Model
{
    use HasStoreScope;
    use LogsActivity;

    public const SOURCES = [
        'qc_gagal' => 'QC gagal',
        'keluhan_pelanggan' => 'Keluhan pelanggan',
        'temuan_internal' => 'Temuan internal',
    ];

    protected $fillable = ['booking_id', 'store_id', 'source', 'reason', 'reported_on', 'resolved_on', 'notes', 'reported_by'];

    protected $casts = ['reported_on' => 'date', 'resolved_on' => 'date'];

    protected static function booted(): void
    {
        static::creating(function (BookingRework $rework) {
            if (empty($rework->store_id) && $rework->booking_id) {
                $rework->store_id = Booking::withoutGlobalScopes()->whereKey($rework->booking_id)->value('store_id');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['booking_id', 'source', 'reason', 'reported_on', 'resolved_on'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('booking_rework');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isOpen(): bool
    {
        return $this->resolved_on === null;
    }
}
