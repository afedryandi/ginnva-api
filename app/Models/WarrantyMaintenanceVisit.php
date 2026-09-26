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
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $casts = [
        'visited_at'   => 'date',
        'cancelled_at' => 'datetime',
    ];

    public function warranty()
    {
        return $this->belongsTo(Warranty::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Gap "koreksi kunjungan salah catat" diperbaiki 2026-09-26 -- lihat
     * WarrantyResource::performCancelMaintenanceVisit().
     */
    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
