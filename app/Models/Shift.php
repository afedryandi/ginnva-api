<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Master Shift — jam kerja + istirahat, didefinisikan sekali, dipakai
 * berulang di berbagai hari/WorkSchedule. Lihat migrasi create_shifts_table.
 */
class Shift extends Model
{
    use HasStoreScope;
    use LogsActivity;

    protected $fillable = [
        'store_id',
        'name',
        'start_time',
        'end_time',
        'break_start_time',
        'break_end_time',
        'color',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Durasi kerja bersih dalam menit (sudah dikurangi istirahat, kalau
     * ada) — dipakai kalkulasi jam kerja terjadwal di kalender & laporan
     * ke depannya.
     */
    public function netMinutes(): int
    {
        $start = \Illuminate\Support\Carbon::parse($this->start_time);
        $end = \Illuminate\Support\Carbon::parse($this->end_time);
        $minutes = $start->diffInMinutes($end, false);

        if ($minutes < 0) {
            // Shift lintas tengah malam (mis. shift malam 22:00-06:00).
            $minutes += 24 * 60;
        }

        if ($this->break_start_time && $this->break_end_time) {
            $breakStart = \Illuminate\Support\Carbon::parse($this->break_start_time);
            $breakEnd = \Illuminate\Support\Carbon::parse($this->break_end_time);
            $breakMinutes = $breakStart->diffInMinutes($breakEnd, false);
            $minutes -= max(0, $breakMinutes);
        }

        return max(0, $minutes);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'start_time', 'end_time', 'break_start_time', 'break_end_time', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('shift')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Shift \"{$this->name}\" dibuat",
                'updated' => "Shift \"{$this->name}\" diubah",
                'deleted' => "Shift \"{$this->name}\" dihapus",
                default => "Shift \"{$this->name}\" — {$eventName}",
            });
    }
}
