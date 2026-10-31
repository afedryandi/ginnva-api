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
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'document',
        'issued_by',
    ];

    protected $casts = [
        'issued_date'      => 'date',
        'valid_until'      => 'date',
        'acknowledged_at'  => 'datetime',
        'cancelled_at'     => 'datetime',
    ];

    /** Isi surat yang terkunci begitu karyawan membacanya atau surat dibatalkan; hanya Scan Surat yang masih boleh dilengkapi. */
    public const LOCKED_FIELDS = ['user_id', 'store_id', 'level', 'reason', 'issued_date', 'valid_until'];

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

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** Sudah dibaca karyawan atau dibatalkan: isinya tidak boleh diubah dan suratnya tidak boleh dihapus. */
    public function isLocked(): bool
    {
        return $this->acknowledged_at !== null || $this->cancelled_at !== null;
    }

    /** "Dibatalkan", "Kedaluwarsa", atau "Berlaku". */
    public function statusLabel(): string
    {
        return match (true) {
            $this->isCancelled() => 'Dibatalkan',
            $this->valid_until !== null && $this->valid_until->lt(today()) => 'Kedaluwarsa',
            default => 'Berlaku',
        };
    }

    /**
     * Batalkan surat yang keliru atau tidak sah. Surat tetap tersimpan sebagai bukti; tidak dihitung sebagai SP berlaku maupun dasar saran
     * tingkat berikutnya. Alasan wajib dan pelaku tercatat.
     *
     * @throws \InvalidArgumentException bila alasan kosong atau surat sudah dibatalkan.
     */
    public function cancel(?User $by, string $reason): self
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Alasan pembatalan wajib diisi.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($by, $reason) {
            $fresh = static::withoutGlobalScopes()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->isCancelled()) {
                throw new \InvalidArgumentException("Surat {$fresh->warning_number} sudah dibatalkan.");
            }

            $fresh->update(['cancelled_at' => now(), 'cancelled_by' => $by?->id, 'cancel_reason' => trim($reason)]);

            return $fresh;
        });
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
        return static::withoutGlobalScopes()->where('user_id', $userId)->active($on)->orderByDesc('issued_date')->get();
    }

    /**
     * Satu definisi "SP berlaku" untuk saran tingkat, hitungan Dasbor HR, dan daftar: tidak dibatalkan, sudah terbit pada tanggal itu, dan
     * belum lewat masa berlakunya (SP lama tanpa tanggal berlaku dianggap berlaku terus).
     */
    public function scopeActive($query, ?\Illuminate\Support\Carbon $on = null)
    {
        $day = ($on ?? today())->toDateString();

        return $query->whereNull('cancelled_at')
            ->whereDate('issued_date', '<=', $day)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day));
    }

    /** Jumlah SP milik karyawan ini yang belum dibaca dan belum dibatalkan (penanda di aplikasi). */
    public static function unreadCountFor(int $userId): int
    {
        return static::withoutGlobalScopes()->where('user_id', $userId)->whereNull('acknowledged_at')->whereNull('cancelled_at')->count();
    }

    /** Belum dibaca karyawan sesudah sekian hari sejak dicatat (config hr.warning_unread_days), dan belum dibatalkan. */
    public function scopeUnreadForTooLong($query)
    {
        return $query->whereNull('acknowledged_at')->whereNull('cancelled_at')
            ->where('created_at', '<=', now()->subDays((int) config('hr.warning_unread_days', 3)));
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
        // Penjaga di model (bukan hanya di form): SP yang sudah dibaca karyawan atau dibatalkan tidak bisa diubah isinya maupun dihapus,
        // dari jalur mana pun. Kekeliruan diselesaikan dengan membatalkan lalu menerbitkan surat baru.
        static::updating(function (WarningLetter $warning) {
            $wasLocked = $warning->getOriginal('acknowledged_at') !== null || $warning->getOriginal('cancelled_at') !== null;

            if ($wasLocked && $warning->isDirty(self::LOCKED_FIELDS)) {
                throw new \RuntimeException("Surat {$warning->warning_number} sudah dibaca karyawan atau dibatalkan, isinya tidak bisa diubah. Batalkan dan terbitkan surat baru.");
            }
        });

        static::deleting(function (WarningLetter $warning) {
            if ($warning->isLocked()) {
                throw new \RuntimeException("Surat {$warning->warning_number} sudah dibaca karyawan atau dibatalkan, tidak bisa dihapus. Gunakan Batalkan SP.");
            }
        });

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
            ->logOnly(['level', 'reason', 'issued_date', 'valid_until', 'acknowledged_at', 'cancelled_at', 'cancel_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('warning_letter')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Surat Peringatan #{$this->warning_number} untuk {$this->user?->name} diterbitkan",
                'updated' => $this->wasChanged('cancelled_at')
                    ? "Surat Peringatan #{$this->warning_number} dibatalkan: {$this->cancel_reason}"
                    : "Surat Peringatan #{$this->warning_number} diubah",
                'deleted' => "Surat Peringatan #{$this->warning_number} dihapus",
                default   => "Surat Peringatan #{$this->warning_number} — {$eventName}",
            });
    }
}
