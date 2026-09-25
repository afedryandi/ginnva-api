<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Riwayat transfer kepemilikan garansi (audit Garansi 2026-09-25, gap
 * "transfer ke pemilik baru tidak dipertimbangkan sama sekali") --
 * dibuat lewat WarrantyResource::performOwnershipTransfer(). Baris ini
 * SENDIRI adalah catatan riwayat -- tidak pernah diedit/dihapus lewat UI
 * apa pun setelah dibuat.
 */
class WarrantyOwnershipTransfer extends Model
{
    protected $fillable = [
        'warranty_id',
        'previous_customer_id',
        'previous_customer_name',
        'previous_phone_number',
        'new_customer_id',
        'new_customer_name',
        'new_phone_number',
        'note',
        'transferred_by',
        'transferred_at',
    ];

    protected $casts = [
        'transferred_at' => 'datetime',
    ];

    public function warranty()
    {
        return $this->belongsTo(Warranty::class);
    }

    public function previousCustomer()
    {
        return $this->belongsTo(Customer::class, 'previous_customer_id');
    }

    public function newCustomer()
    {
        return $this->belongsTo(Customer::class, 'new_customer_id');
    }

    public function transferredBy()
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }
}
