<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class JournalEntry extends Model
{
    use LogsActivity;

    protected $fillable = [
        'entry_number',
        'entry_date',
        'store_id',
        'description',
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
        return (float) $this->lines()->sum('debit');
    }

    public function totalCredit(): float
    {
        return (float) $this->lines()->sum('credit');
    }

    public function isBalanced(): bool
    {
        return round($this->totalDebit(), 2) === round($this->totalCredit(), 2);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'entry_date', 'description', 'store_id'])
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
