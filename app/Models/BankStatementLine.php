<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementLine extends Model
{
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
