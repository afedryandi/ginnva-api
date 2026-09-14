<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BankStatementLine extends Model
{
    // Audit framework 2026-09-14, "Jejak audit (audit trail) perubahan
    // data" -- status (matched/unmatched/diabaikan) adalah bagian dari
    // kontrol rekonsiliasi bank, sebelumnya perubahannya (siapa/kapan)
    // tidak tercatat.
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'matched_journal_entry_line_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('bank_statement_line');
    }

    protected $fillable = [
        'chart_of_account_id',
        'statement_date',
        'description',
        'amount',
        'external_reference',
        'matched_journal_entry_line_id',
        'status',
        'import_batch',
        'created_by',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }

    public function matchedLine(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class, 'matched_journal_entry_line_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
