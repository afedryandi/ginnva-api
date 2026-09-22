<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Riwayat Impor" — lihat migrasi create_product_import_logs_table.
 * Append-only, sengaja TIDAK pakai LogsActivity (baris ini SUDAH
 * menjadi jejak/log itu sendiri, bukan data yang perlu diaudit lagi).
 */
class ProductImportLog extends Model
{
    protected $fillable = [
        'user_id',
        'filename',
        'total_rows',
        'updated_count',
        'skipped_count',
        'errors',
    ];

    protected $casts = [
        'errors' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
