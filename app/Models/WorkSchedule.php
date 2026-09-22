<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Template Jadwal Kerja Mingguan (Pola Standar 7-hari) — audit Majoo vs
 * Ginnva, dibangun 2026-09-22. Lihat migrasi create_work_schedules_table
 * untuk struktur kolom `days`.
 */
class WorkSchedule extends Model
{
    use HasStoreScope;
    use LogsActivity;

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const DAY_LABELS = [
        'mon' => 'Senin',
        'tue' => 'Selasa',
        'wed' => 'Rabu',
        'thu' => 'Kamis',
        'fri' => 'Jumat',
        'sat' => 'Sabtu',
        'sun' => 'Minggu',
    ];

    protected $fillable = [
        'store_id',
        'name',
        'days',
        'is_active',
    ];

    protected $casts = [
        'days' => 'array',
        'is_active' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeScheduleAssignment::class);
    }

    /**
     * shift_id yang berlaku untuk kode hari tertentu ('mon'..'sun') di
     * template ini, atau null kalau hari itu libur / tidak diisi.
     */
    public function shiftIdFor(string $dayCode): ?int
    {
        $row = collect($this->days ?? [])->firstWhere('day', $dayCode);

        return $row['shift_id'] ?? null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'days', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('work_schedule')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Jadwal Kerja \"{$this->name}\" dibuat",
                'updated' => "Jadwal Kerja \"{$this->name}\" diubah",
                'deleted' => "Jadwal Kerja \"{$this->name}\" dihapus",
                default => "Jadwal Kerja \"{$this->name}\" — {$eventName}",
            });
    }
}
