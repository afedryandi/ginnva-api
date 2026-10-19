<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bukti satu karyawan menerima satu dokumen kebijakan (Karyawan Fase 3). Tidak diubah setelah dibuat.
 */
class PolicyAcknowledgment extends Model
{
    protected $fillable = ['company_policy_id', 'user_id', 'acknowledged_at', 'ip_address'];

    protected $casts = ['acknowledged_at' => 'datetime'];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(CompanyPolicy::class, 'company_policy_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
