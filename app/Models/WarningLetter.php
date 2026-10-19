<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class WarningLetter extends Model
{
    use LogsActivity;

    // Global Scope store-id (audit framework 2026-09-14, "Isolasi data
    // multi-tenant") — lihat App\Models\Scopes\StoreScope. Pola manual
    // yang sudah ada di WarningLetterResource::getEloquentQuery() SENGAJA
    // DIBIARKAN (bukan dihapus) sebagai defense-in-depth; filter dari
    // scope ini no-op/redundan di sana, tapi jadi satu-satunya proteksi
    // untuk query LANGSUNG ke WarningLetter:: di tempat lain (widget/
    // report/service) yang sebelumnya rawan lupa di-scope.
    use HasStoreScope;

    protected $fillable = [
        'warning_number',
        'user_id',
        'store_id',
        'level',
        'reason',
        'issued_date',
        'valid_until',
        'acknowledged_at',
        'document',
        'issued_by',
    ];

    protected $casts = [
        'issued_date'      => 'date',
        'valid_until'      => 'date',
        'acknowledged_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** Masa berlaku SP menurut PP 35/2021 Pasal 52 (6 bulan kecuali diatur lain); bisa diubah di config/hr.php. */
    public const DEFAULT_VALIDITY_MONTHS = 6;

    protected static function booted(): void
    {
        static::creating(function (WarningLetter $warning) {
            // Masa berlaku otomatis bila tidak diisi, supaya SP lama tidak dihitung selamanya (Karyawan Fase 3).
            if (empty($warning->valid_until) && ! empty($warning->issued_date)) {
                $warning->valid_until = \Illuminate\Support\Carbon::parse($warning->issued_date)->addMonths((int) config('hr.warning_validity_months', self::DEFAULT_VALIDITY_MONTHS))->toDateString();
            }

            if (empty($warning->warning_number)) {
                $warning->warning_number = static::generateWarningNumber();
            }
        });
    }

    protected static function generateWarningNumber(): string
    {
        do {
            $candidate = 'SP-' . now()->format('Ym') . '-' . Str::upper(Str::random(4));
        } while (static::where('warning_number', $candidate)->exists());

        return $candidate;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['level', 'reason', 'issued_date', 'valid_until', 'acknowledged_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('warning_letter')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Surat Peringatan #{$this->warning_number} untuk {$this->user?->name} diterbitkan",
                'updated' => "Surat Peringatan #{$this->warning_number} diubah",
                'deleted' => "Surat Peringatan #{$this->warning_number} dihapus",
                default   => "Surat Peringatan #{$this->warning_number} — {$eventName}",
            });
    }
}
