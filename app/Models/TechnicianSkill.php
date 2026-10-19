<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Tingkat keterampilan satu teknisi pada satu jenis layanan (Karyawan Fase 4, 2026-10-31). Matriks internal Ginnva: tidak ada standar
 * independen tingkat keterampilan PPF, dan sertifikasi produsen khusus merek masing-masing. Dipakai sebagai peringatan penugasan dan
 * pembinaan, bukan untuk memotong gaji atau komisi.
 */
class TechnicianSkill extends Model
{
    use LogsActivity;

    public const BEGINNER = 1;
    public const INDEPENDENT = 2;
    public const EXPERT = 3;

    public const LEVELS = [
        self::BEGINNER => 'Pemula (didampingi)',
        self::INDEPENDENT => 'Mandiri',
        self::EXPERT => 'Mahir (bisa melatih)',
    ];

    protected $fillable = ['technician_id', 'service_type', 'level', 'assessed_on', 'assessed_by', 'notes'];

    protected $casts = ['level' => 'integer', 'assessed_on' => 'date'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['technician_id', 'service_type', 'level', 'assessed_on'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('technician_skill');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
