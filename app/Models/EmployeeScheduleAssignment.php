<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Penugasan WorkSchedule ke 1 karyawan, dengan rentang tanggal berlaku
 * — lihat migrasi create_employee_schedule_assignments_table untuk
 * penjelasan lengkap "Tanggal Efektif" & "Riwayat Jadwal Kerja".
 */
class EmployeeScheduleAssignment extends Model
{
    use LogsActivity;

    protected $fillable = [
        'user_id',
        'work_schedule_id',
        'store_id',
        'effective_from',
        'effective_to',
        'assigned_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Terapkan 1 WorkSchedule ke BANYAK karyawan sekaligus (audit Majoo,
     * "Template jadwal kerja mingguan direuse ke banyak karyawan") mulai
     * $effectiveFrom. Untuk tiap karyawan: penugasan LAMA yang masih
     * "terbuka" (effective_to null) otomatis DITUTUP (effective_to =
     * $effectiveFrom - 1 hari) SEBELUM baris baru dibuat -- supaya tidak
     * pernah ada 2 penugasan aktif tumpang-tindih utk 1 karyawan yang
     * sama, dan histori lama tetap utuh (bukan ditimpa/dihapus).
     *
     * @param  int[]  $userIds
     */
    public static function assignBulk(WorkSchedule $workSchedule, array $userIds, Carbon $effectiveFrom, ?int $assignedBy): int
    {
        $count = 0;

        DB::transaction(function () use ($workSchedule, $userIds, $effectiveFrom, $assignedBy, &$count) {
            foreach (array_unique($userIds) as $userId) {
                $open = static::where('user_id', $userId)
                    ->whereNull('effective_to')
                    ->lockForUpdate()
                    ->first();

                if ($open) {
                    // Kalau kebetulan penugasan lama BELUM MULAI (effective_from
                    // di masa depan >= tanggal efektif baru), timpa saja
                    // (bukan ditutup dgn tanggal mundur yang tidak masuk akal).
                    if ($open->effective_from->gte($effectiveFrom)) {
                        $open->delete();
                    } else {
                        $open->update(['effective_to' => $effectiveFrom->copy()->subDay()]);
                    }
                }

                static::create([
                    'user_id' => $userId,
                    'work_schedule_id' => $workSchedule->id,
                    'store_id' => $workSchedule->store_id,
                    'effective_from' => $effectiveFrom,
                    'effective_to' => null,
                    'assigned_by' => $assignedBy,
                ]);

                $count++;
            }
        });

        return $count;
    }

    /**
     * Penugasan yang AKTIF pada tanggal tertentu (default hari ini) utk
     * 1 karyawan, atau null kalau tidak ada jadwal ter-assign.
     */
    public static function activeFor(int $userId, ?Carbon $onDate = null): ?self
    {
        $onDate ??= Carbon::today();

        return static::where('user_id', $userId)
            ->whereDate('effective_from', '<=', $onDate)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $onDate))
            ->with('workSchedule')
            ->orderByDesc('effective_from')
            ->first();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'work_schedule_id', 'effective_from', 'effective_to'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('employee_schedule_assignment')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Penugasan jadwal kerja dibuat untuk {$this->user?->name}",
                'updated' => "Penugasan jadwal kerja {$this->user?->name} diubah",
                'deleted' => "Penugasan jadwal kerja {$this->user?->name} dihapus",
                default => "Penugasan jadwal kerja {$this->user?->name} — {$eventName}",
            });
    }
}
