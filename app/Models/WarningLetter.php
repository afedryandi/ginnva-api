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

    /** Nomor tingkat (1-3) dari kode 'sp1'..'sp3'. */
    public static function levelNumber(?string $level): int
    {
        return (int) preg_replace('/\D/', '', (string) $level);
    }

    /**
     * SP yang masih berlaku pada tanggal tertentu (diterbitkan sebelum/pada tanggal itu dan belum lewat masa berlakunya), terbaru dulu.
     *
     * @return \Illuminate\Support\Collection<int, WarningLetter>
     */
    public static function activeFor(int $userId, ?\Illuminate\Support\Carbon $on = null)
    {
        $day = ($on ?? today())->toDateString();

        return static::withoutGlobalScopes()->where('user_id', $userId)
            ->whereDate('issued_date', '<=', $day)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day))
            ->orderByDesc('issued_date')->get();
    }

    /**
     * Tingkat yang disarankan untuk pelanggaran berikutnya: satu tingkat di atas SP tertinggi yang masih berlaku, maksimal SP3; SP1 bila
     * tidak ada yang berlaku (PP 35/2021 Pasal 52: setelah masa berlaku habis, pelanggaran baru kembali ke SP1). Hanya saran, keputusan
     * tetap di HRD karena pelanggaran berat boleh langsung SP2/SP3.
     */
    public static function suggestedLevelFor(int $userId, ?\Illuminate\Support\Carbon $on = null): string
    {
        $highest = static::activeFor($userId, $on)->map(fn (self $w) => static::levelNumber($w->level))->max() ?? 0;

        return 'sp' . min(3, $highest + 1);
    }

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
