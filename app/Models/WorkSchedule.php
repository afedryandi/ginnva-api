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

    /**
     * Validasi SERVER isi `days` (audit Daftar Jadwal Kerja 2026-09-28):
     * sebelumnya cuma dibatasi lewat UI Repeater, payload Livewire yang
     * dimanipulasi bisa menyimpan jumlah hari salah, hari ganda, atau
     * shift_id milik toko lain / id sembarang (kolom JSON tanpa FK).
     * Return pesan error, atau null kalau valid.
     */
    public static function validateDays(mixed $days, ?int $storeId): ?string
    {
        if (! is_array($days) || count($days) !== 7) {
            return 'Pola harus berisi tepat 7 hari.';
        }

        $codes = collect($days)->pluck('day')->all();
        if (collect($codes)->sort()->values()->all() !== collect(self::DAYS)->sort()->values()->all()) {
            return 'Tiap hari (Senin-Minggu) harus muncul tepat satu kali.';
        }

        $shiftIds = collect($days)->pluck('shift_id')->filter()->unique()->values();
        if ($shiftIds->isNotEmpty()) {
            $valid = Shift::withoutGlobalScopes()->where('store_id', $storeId)->whereIn('id', $shiftIds)->count();
            if ($valid !== $shiftIds->count()) {
                return 'Ada shift yang tidak ditemukan atau bukan milik toko ini.';
            }
        }

        return null;
    }

    /** Karyawan dengan penugasan yang masih berlaku (hari ini atau ke depan). */
    public function activeAssigneeIds(): array
    {
        return $this->assignments()
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }

    protected static function booted(): void
    {
        static::deleting(function (WorkSchedule $schedule) {
            if ($schedule->assignments()->exists()) {
                throw new \RuntimeException("Jadwal Kerja \"{$schedule->name}\" punya riwayat penugasan karyawan, tidak bisa dihapus (riwayat ikut hilang). Nonaktifkan saja.");
            }
        });
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
