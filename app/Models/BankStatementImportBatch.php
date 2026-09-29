<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Arsip 1 kali impor mutasi bank (audit Rekonsiliasi Bank 2026-09-29): file aslinya disimpan
 * (bukan dihapus) supaya bisa ditelusuri "file apa yang diimpor tanggal X" saat audit.
 */
class BankStatementImportBatch extends Model
{
    protected $fillable = [
        'batch', 'chart_of_account_id', 'original_filename', 'archived_path',
        'imported_count', 'duplicate_count', 'invalid_count', 'created_by',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'import_batch', 'batch');
    }
}
