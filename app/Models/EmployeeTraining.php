<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Pelatihan seorang karyawan (Karyawan Fase 5, 2026-11-02): K3, pengetahuan produk, dll., dengan masa berlaku bila ada. Untuk installer,
 * pelatihan per layanan tetap dicatat di Teknisi > Pelatihan & Sertifikat (technician_trainings).
 */
class EmployeeTraining extends Model
{
    use HasStoreScope;
    use LogsActivity;

    public const CATEGORIES = ['k3' => 'K3 (keselamatan kerja)', 'produk' => 'Pengetahuan produk', 'lainnya' => 'Lainnya'];

    protected $fillable = ['user_id', 'store_id', 'category', 'title', 'provider', 'held_on', 'valid_until', 'certificate_path', 'notes'];

    protected $casts = ['held_on' => 'date', 'valid_until' => 'date'];

    protected static function booted(): void
    {
        static::creating(function (EmployeeTraining $training) {
            if (empty($training->store_id) && $training->user_id) {
                $training->store_id = User::whereKey($training->user_id)->value('store_id');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'category', 'title', 'held_on', 'valid_until'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('employee_training');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
