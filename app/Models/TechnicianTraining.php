<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Pelatihan atau sertifikasi seorang teknisi (Karyawan Fase 4, 2026-10-31), internal maupun dari produsen film. Berkas sertifikat
 * disimpan di disk privat.
 */
class TechnicianTraining extends Model
{
    use LogsActivity;

    protected $fillable = ['technician_id', 'title', 'provider', 'service_type', 'held_on', 'valid_until', 'certificate_path', 'notes'];

    protected $casts = ['held_on' => 'date', 'valid_until' => 'date'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['technician_id', 'title', 'provider', 'service_type', 'held_on', 'valid_until'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('technician_training');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    public function expiresSoon(int $days = 30): bool
    {
        return $this->valid_until !== null && ! $this->isExpired() && $this->valid_until->lte(today()->addDays($days));
    }
}
