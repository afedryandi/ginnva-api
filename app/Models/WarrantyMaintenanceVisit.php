<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Riwayat kunjungan maintenance (fitur "Kuota Maintenance", 2026-09-25) --
 * dibuat lewat WarrantyResource::performRecordMaintenanceVisit(). Baris ini
 * SENDIRI adalah catatan riwayat -- tidak pernah diedit/dihapus lewat UI
 * apa pun setelah dibuat (sama pola dengan WarrantyOwnershipTransfer).
 */
class WarrantyMaintenanceVisit extends Model
{
    protected $fillable = [
        'warranty_id',
        'visited_at',
        'note',
        'recorded_by',
    ];

    protected $casts = [
        'visited_at' => 'date',
    ];

    public function warranty()
    {
        return $this->belongsTo(Warranty::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
