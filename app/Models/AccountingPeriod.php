<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AccountingPeriod extends Model
{
    use LogsActivity;

    protected $fillable = [
        'period_month',
        'closed_by',
        'closed_at',
        'notes',
    ];

    protected $casts = [
        'period_month' => 'date',
        'closed_at' => 'datetime',
    ];

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Bulan yang TIDAK punya baris di sini otomatis dianggap terbuka —
     * dicek lewat keberadaan baris, bukan kolom status, konsisten
     * dengan komentar migration.
     */
    public static function isClosedFor(Carbon $date): bool
    {
        return self::where('period_month', $date->copy()->startOfMonth()->toDateString())->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['period_month', 'closed_by', 'notes'])
            ->dontSubmitEmptyLogs()
            ->useLogName('accounting_period')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => 'Periode ' . $this->period_month?->format('F Y') . ' ditutup',
                'deleted' => 'Periode ' . $this->period_month?->format('F Y') . ' dibuka kembali',
                default => 'Periode ' . $this->period_month?->format('F Y') . " — {$eventName}",
            });
    }
}
