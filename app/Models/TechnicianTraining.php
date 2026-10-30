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

    /**
     * Catatan yang sudah DIGANTIKAN: installer yang sama punya catatan lain dengan judul dan layanan yang sama yang berlaku lebih lama
     * (atau tanpa batas). Memperbarui sertifikat berarti menambah catatan baru, jadi yang lama tidak boleh terus dihitung kedaluwarsa.
     * Dipakai di query (laporan, Dasbor HR) sebagai ->current().
     */
    public function scopeCurrent(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNotExists(function ($sub) {
            $sub->selectRaw('1')
                ->from('technician_trainings as newer')
                ->whereColumn('newer.technician_id', 'technician_trainings.technician_id')
                ->whereColumn('newer.title', 'technician_trainings.title')
                ->whereRaw('newer.service_type <=> technician_trainings.service_type')
                ->whereColumn('newer.id', '!=', 'technician_trainings.id')
                ->where(fn ($w) => $w->whereNull('newer.valid_until')->orWhereColumn('newer.valid_until', '>', 'technician_trainings.valid_until'));
        });
    }

    /**
     * Sama dengan scopeCurrent() untuk koleksi catatan satu installer yang sudah dimuat: apakah catatan ini digantikan oleh yang lain.
     *
     * @param  \Illuminate\Support\Collection<int, TechnicianTraining>  $siblings
     */
    public function isSupersededBy(\Illuminate\Support\Collection $siblings): bool
    {
        $title = mb_strtolower(trim($this->title));

        return $siblings->contains(fn (TechnicianTraining $other) => $other->id !== $this->id
            && mb_strtolower(trim($other->title)) === $title
            && $other->service_type === $this->service_type
            && ($other->valid_until === null || ($this->valid_until !== null && $other->valid_until->gt($this->valid_until))));
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
