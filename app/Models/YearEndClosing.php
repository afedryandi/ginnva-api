<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Catatan penutupan tahun buku (Keuangan Fase 6, 2026-11-09). Jurnal penutupnya mengacu ke tahun ini (reference_type year_end_closing).
 */
class YearEndClosing extends Model
{
    use LogsActivity;

    protected $fillable = ['year', 'net_income', 'entries_count', 'notes', 'closed_by', 'closed_at', 'voided_at', 'voided_by', 'void_reason'];

    protected $casts = ['net_income' => 'decimal:2', 'closed_at' => 'datetime', 'voided_at' => 'datetime'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['year', 'net_income', 'closed_at', 'voided_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('year_end_closing');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isActive(): bool
    {
        return $this->voided_at === null;
    }

    /** Tahun yang sedang berstatus ditutup. */
    public static function closedYears(): array
    {
        return static::whereNull('voided_at')->pluck('year')->map(fn ($y) => (int) $y)->all();
    }
}
