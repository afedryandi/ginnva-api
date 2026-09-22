<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Riwayat Karir" — 1 baris = 1 kali perpindahan toko/outlet seorang
 * karyawan. Lihat migrasi create_employee_career_histories_table &
 * User::booted() untuk bagaimana baris ini otomatis tercatat.
 */
class EmployeeCareerHistory extends Model
{
    protected $fillable = [
        'user_id',
        'previous_store_id',
        'new_store_id',
        'reason',
        'changed_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function previousStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'previous_store_id');
    }

    public function newStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'new_store_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
