<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat "Mutasi Roll Film antar cabang" -- keputusan atasan 2026-09-19
 * (Topik 4, Fase 1). Lihat ScrollCode::transferTo() & migrasi
 * create_scroll_code_transfers_table.
 */
class ScrollCodeTransfer extends Model
{
    protected $fillable = [
        'scroll_code_id',
        'from_store_id',
        'to_store_id',
        'reason',
        'performed_by',
    ];

    public function scrollCode(): BelongsTo
    {
        return $this->belongsTo(ScrollCode::class);
    }

    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    public function toStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
