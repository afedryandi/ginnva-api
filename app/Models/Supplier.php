<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Master supplier untuk Hutang Usaha (audit 2026-09-29): 1 supplier dipakai
 * berulang supaya histori tidak terbelah oleh typo nama, plus data NPWP/rekening
 * untuk pembayaran. Tidak per-toko (supplier bersifat company-wide).
 */
class Supplier extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name', 'npwp', 'bank_name', 'bank_account_number', 'bank_account_name',
        'phone', 'email', 'address', 'notes', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function payables(): HasMany
    {
        return $this->hasMany(Payable::class);
    }

    public function recurringBillTemplates(): HasMany
    {
        return $this->hasMany(RecurringBillTemplate::class);
    }

    /** Dipakai di manapun (tagihan atau template rutin) -- guard hapus (audit Supplier 2026-09-29). */
    public function isInUse(): bool
    {
        return $this->payables()->exists() || $this->recurringBillTemplates()->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'npwp', 'bank_name', 'bank_account_number', 'bank_account_name', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('supplier')
            ->setDescriptionForEvent(fn (string $eventName) => "Supplier {$this->name} — {$eventName}");
    }
}
