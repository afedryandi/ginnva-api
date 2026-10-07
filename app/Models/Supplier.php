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

    /** Spasi di tepi dibuang dan spasi berulang dirapatkan: "PT  Jaya " dan "PT Jaya" adalah supplier yang sama. */
    public static function normalizeName(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    protected static function booted(): void
    {
        static::saving(function (Supplier $supplier) {
            $supplier->name = static::normalizeName((string) $supplier->name);
        });
    }

    /**
     * Cari supplier dengan nama yang sama (tanpa peduli huruf besar-kecil & spasi berulang), atau buat baru.
     * Dipakai tombol "buat supplier baru" cepat di form Hutang Usaha, Permintaan Pembelian & Template Tagihan
     * Rutin, yang sebelumnya selalu membuat baris baru walau nama yang sama sudah ada.
     */
    public static function findOrCreateByName(string $name, array $attributes = []): self
    {
        $clean = static::normalizeName($name);

        return static::whereRaw('LOWER(name) = ?', [mb_strtolower($clean)])->first()
            ?? static::create(array_merge($attributes, ['name' => $clean]));
    }

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
