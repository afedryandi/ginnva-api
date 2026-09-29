<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PartnerPointTransaction extends Model
{
    // Audit trail (audit Kemitraan & Referral 2026-09-29) -- konsistensi dengan Partner/
    // RewardRedemption yang sudah pakai LogsActivity. Resource-nya sendiri sudah immutable
    // (canEdit/canDelete selalu false), tapi ini menutup celah kalau ada perubahan lewat query
    // langsung/console command di luar 1 jalur form manual yang sudah tercatat via created_by.
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['partner_id', 'type', 'points', 'description', 'reference_type', 'reference_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('partner_point_transaction');
    }

    protected $fillable = [
        'partner_id',
        'type',
        'points',
        'description',
        'reference_type',
        'reference_id',
        // Gap ditutup 2026-09-26 (audit Riwayat Poin Partner) -- lihat
        // migrasi add_created_by_to_partner_point_transactions_table.
        'created_by',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
