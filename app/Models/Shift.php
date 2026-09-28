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

    protected static function booted(): void
    {
        static::deleting(function (Shift $shift) {
            if ($shift->isInUse()) {
                throw new \RuntimeException("Shift \"{$shift->name}\" masih dipakai Jadwal Kerja/override harian, tidak bisa dihapus. Ganti dulu shift di jadwal tersebut, atau nonaktifkan saja.");
            }
        });
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

    /**
     * Berapa Jadwal Kerja (template) & override harian yang memakai
     * shift ini. Ditambahkan 2026-09-28 (audit Daftar Shift):
     * work_schedules.days adalah JSON tanpa FK dan
     * schedule_day_overrides.shift_id nullOnDelete (NULL = LIBUR), jadi
     * menghapus shift yang masih dipakai diam-diam memutus jadwal
     * (staf dianggap tanpa jadwal -> telat dihitung dari jam buka toko)
     * atau mengubah override jadi libur. withoutGlobalScopes() supaya
     * pengecekan tidak ikut terbatas store_id user yang sedang login.
     *
     * @return array{schedules:int, overrides:int}
     */
    public function usageSummary(): array
    {
        $schedules = $this->schedulesUsing()->count();

        $overrides = ScheduleDayOverride::withoutGlobalScopes()->where('shift_id', $this->id)->count();

        return ['schedules' => $schedules, 'overrides' => $overrides];
    }

    /** @return \Illuminate\Support\Collection<int, WorkSchedule> */
    public function schedulesUsing(): \Illuminate\Support\Collection
    {
        return WorkSchedule::withoutGlobalScopes()
            ->get()
            ->filter(fn (WorkSchedule $s) => collect($s->days ?? [])->contains(fn ($row) => (int) ($row['shift_id'] ?? 0) === $this->id))
            ->values();
    }

    /**
     * Karyawan yang jadwalnya benar-benar terdampak kalau jam shift ini
     * berubah: penugasan Jadwal Kerja yang masih berlaku (effective_to
     * kosong / belum lewat) pada template yang memakai shift ini, plus
     * override harian hari ini dan ke depan. Gap audit Daftar Shift
     * 2026-09-28: karyawan tidak diberi tahu jam shift berubah.
     *
     * @return array<int>
     */
    public function affectedUserIds(): array
    {
        $scheduleIds = $this->schedulesUsing()->pluck('id');

        $fromAssignments = EmployeeScheduleAssignment::whereIn('work_schedule_id', $scheduleIds)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
            ->pluck('user_id');

        $fromOverrides = ScheduleDayOverride::withoutGlobalScopes()
            ->where('shift_id', $this->id)
            ->whereDate('date', '>=', today())
            ->pluck('user_id');

        return $fromAssignments->merge($fromOverrides)->unique()->values()->all();
    }

    /**
     * Pindahkan SEMUA pemakaian shift ini (template Jadwal Kerja &
     * override harian) ke $target, dalam 1 transaction dengan lock
     * pada baris template. Dipakai sebelum menghapus shift.
     */
    public function replaceUsageWith(Shift $target): void
    {
        if ($target->id === $this->id || $target->store_id !== $this->store_id) {
            throw new \InvalidArgumentException('Shift pengganti harus shift lain di toko yang sama.');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($target) {
            $ids = $this->schedulesUsing()->pluck('id');

            WorkSchedule::withoutGlobalScopes()->whereIn('id', $ids)->lockForUpdate()->get()->each(function (WorkSchedule $s) use ($target) {
                $s->days = collect($s->days ?? [])
                    ->map(function ($row) use ($target) {
                        if ((int) ($row['shift_id'] ?? 0) === $this->id) {
                            $row['shift_id'] = $target->id;
                        }

                        return $row;
                    })
                    ->all();
                $s->save();
            });

            ScheduleDayOverride::withoutGlobalScopes()->where('shift_id', $this->id)->update(['shift_id' => $target->id]);
        });
    }

    public function isInUse(): bool
    {
        $usage = $this->usageSummary();

        return $usage['schedules'] > 0 || $usage['overrides'] > 0;
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
