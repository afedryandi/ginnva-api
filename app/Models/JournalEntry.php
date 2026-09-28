<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class JournalEntry extends Model
{
    use LogsActivity;

    // Global Scope store-id (audit framework 2026-09-14, "Isolasi data
    // multi-tenant") — JournalEntryResource::canViewAny() sudah
    // full-access-only, jadi scope ini no-op lewat Filament; jaring
    // pengaman untuk query LANGSUNG ke JournalEntry:: di tempat lain
    // (widget/report/service). Lihat App\Models\Scopes\StoreScope.
    use HasStoreScope;

    protected $fillable = [
        'entry_number',
        'entry_date',
        'store_id',
        'description',
        'attachment',
        'reference_type',
        'reference_id',
        'status',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'posted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * Jurnal pembalik-nya (kalau sudah pernah dibalik) — dicari lewat
     * reference_type='reversal' + reference_id, BUKAN kolom langsung di
     * baris ini, supaya 1 entri posted bisa saja punya riwayat pembalik
     * tanpa perlu migrasi ulang skema kalau nanti butuh field tambahan.
     */
    public function reversal(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(self::class, 'reference_id')
            ->where('reference_type', 'reversal');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function totalDebit(): float
    {
        // Pakai hasil withSum('lines', 'debit') kalau sudah di-load (tabel) --
        // menghindari 1 query sum() per baris (audit Jurnal Umum 2026-09-29).
        if (array_key_exists('lines_sum_debit', $this->attributes)) {
            return (float) $this->attributes['lines_sum_debit'];
        }

        return (float) $this->lines()->sum('debit');
    }

    public function totalCredit(): float
    {
        if (array_key_exists('lines_sum_credit', $this->attributes)) {
            return (float) $this->attributes['lines_sum_credit'];
        }

        return (float) $this->lines()->sum('credit');
    }

    public function isBalanced(): bool
    {
        // Dibandingkan dalam SEN (integer), bukan float.
        return (int) round($this->totalDebit() * 100) === (int) round($this->totalCredit() * 100);
    }

    /**
     * Guard di level MODEL (audit Jurnal Umum 2026-09-29): jurnal POSTED
     * tidak boleh diubah tanggal/toko/status/keterangan/nomornya atau
     * dihapus lewat jalur mana pun (tinker, job, kode baru) -- sebelumnya cuma
     * dijaga UI & service. reference_type/reference_id sengaja masih boleh
     * (PayableService/ReceivableService mengisi reference_id setelah dibuat);
     * transisi draft->posted tetap boleh (status lama = draft).
     */
    protected static function booted(): void
    {
        static::updating(function (JournalEntry $entry) {
            if ($entry->getOriginal('status') === 'posted' && $entry->isDirty(['entry_date', 'store_id', 'status', 'description', 'entry_number'])) {
                throw new \RuntimeException("Jurnal {$entry->entry_number} sudah diposting dan terkunci — gunakan jurnal pembalik untuk koreksi.");
            }
        });

        static::deleting(function (JournalEntry $entry) {
            if ($entry->status === 'posted') {
                throw new \RuntimeException("Jurnal {$entry->entry_number} sudah diposting dan tidak boleh dihapus — gunakan jurnal pembalik.");
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'entry_date', 'description', 'store_id', 'posted_by', 'posted_at', 'reference_type', 'reference_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('journal_entry')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Jurnal {$this->entry_number} dibuat",
                'updated' => "Jurnal {$this->entry_number} diubah",
                'deleted' => "Jurnal {$this->entry_number} dihapus",
                default => "Jurnal {$this->entry_number} — {$eventName}",
            });
    }
}
