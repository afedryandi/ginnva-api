<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class JournalEntryLine extends Model
{
    use LogsActivity;

    // Hanya perubahan & penghapusan yang dicatat (nilai LAMA baris draft yang
    // diedit tidak hilang tanpa jejak); pembuatan sudah tercermin di log
    // jurnalnya -- audit Jurnal Umum 2026-09-29.
    protected static $recordEvents = ['updated', 'deleted'];

    protected $fillable = [
        'journal_entry_id',
        'chart_of_account_id',
        'debit',
        'credit',
        'description',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    /**
     * Baris jurnal POSTED tidak boleh diubah/dihapus (guard di level model).
     * Penghapusan via cascade database (hapus jurnal draft) tidak melewati
     * event ini -- itu aman karena jurnal draft belum dipakai laporan.
     */
    protected static function booted(): void
    {
        $guard = function (JournalEntryLine $line) {
            if (JournalEntry::withoutGlobalScopes()->where('id', $line->journal_entry_id)->where('status', 'posted')->exists()) {
                throw new \RuntimeException('Baris jurnal yang sudah diposting tidak boleh diubah atau dihapus — gunakan jurnal pembalik.');
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['journal_entry_id', 'chart_of_account_id', 'debit', 'credit', 'description'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('journal_entry_line')
            ->setDescriptionForEvent(fn (string $eventName) => "Baris jurnal #{$this->journal_entry_id} {$eventName}");
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }
}
