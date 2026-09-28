<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat tutup / buka kembali periode akuntansi (append-only). Baris accounting_periods dihapus
 * saat periode dibuka kembali, jadi jejak siapa/kapan/mengapa disimpan di sini.
 */
class AccountingPeriodEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['period_month', 'action', 'user_id', 'note', 'snapshot', 'created_at'];

    protected $casts = [
        'period_month' => 'date',
        'snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
