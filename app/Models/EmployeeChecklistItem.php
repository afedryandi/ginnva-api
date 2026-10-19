<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Satu butir checklist onboarding/offboarding milik seorang karyawan (Karyawan Fase 3, 2026-10-31).
 */
class EmployeeChecklistItem extends Model
{
    use LogsActivity;

    protected $fillable = ['user_id', 'kind', 'title', 'sort_order', 'is_done', 'done_at', 'done_by', 'notes'];

    protected $casts = ['is_done' => 'boolean', 'done_at' => 'datetime', 'sort_order' => 'integer'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['kind', 'title', 'is_done', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('employee_checklist_item');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    /**
     * Salin butir template aktif untuk karyawan (lewati yang sudah ada). Mengembalikan jumlah butir baru.
     */
    public static function startFor(User $user, string $kind): int
    {
        ChecklistTemplate::ensureDefaults();

        $created = 0;

        foreach (ChecklistTemplate::where('kind', $kind)->where('is_active', true)->orderBy('sort_order')->get() as $template) {
            $item = static::firstOrCreate(
                ['user_id' => $user->id, 'kind' => $kind, 'title' => $template->title],
                ['sort_order' => $template->sort_order],
            );

            $created += $item->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    public function markDone(bool $done, ?int $byUserId, ?string $notes = null): void
    {
        $this->update([
            'is_done' => $done,
            'done_at' => $done ? now() : null,
            'done_by' => $done ? $byUserId : null,
            'notes' => $notes ?? $this->notes,
        ]);
    }
}
