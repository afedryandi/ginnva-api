<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Master supplier: 1 supplier dipakai berulang supaya histori tidak terbelah oleh typo nama, plus kontak dan data NPWP/rekening
 * untuk pembayaran. Dipakai sebagai Pemasok Utama bahan/barang, pemasok barang yang diterima (Permohonan Pembelian), dan bila
 * Keuangan menyala, Hutang Usaha. Tidak per-toko (supplier bersifat company-wide).
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

    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    /** Bahan Baku yang menjadikan supplier ini Pemasok Utama. */
    public function suppliedMaterials(): HasMany
    {
        return $this->hasMany(RawMaterial::class, 'preferred_supplier_id');
    }

    /** Barang Habis Pakai yang menjadikan supplier ini Pemasok Utama. */
    public function suppliedConsumables(): HasMany
    {
        return $this->hasMany(ConsumableItem::class, 'preferred_supplier_id');
    }

    /**
     * Alasan supplier tidak boleh dihapus (kosong = bebas): tagihan, template tagihan rutin, Pemasok Utama bahan/barang, atau
     * permohonan pembelian yang pernah diterima darinya. Menghapusnya akan memutus taut itu diam-diam (nullOnDelete).
     *
     * @return list<string>
     */
    public function inUseReasons(): array
    {
        $reasons = [];

        if ($this->payables()->exists()) {
            $reasons[] = 'tagihan';
        }

        if ($this->recurringBillTemplates()->exists()) {
            $reasons[] = 'template tagihan rutin';
        }

        $items = $this->suppliedMaterials()->count() + $this->suppliedConsumables()->count();
        if ($items > 0) {
            $reasons[] = "Pemasok Utama {$items} bahan/barang";
        }

        if (PurchaseRequest::withoutGlobalScopes()->where('supplier_id', $this->id)->exists()) {
            $reasons[] = 'pemasok permohonan pembelian';
        }

        return $reasons;
    }

    /** Dipakai di manapun -- guard hapus (audit Supplier 2026-09-29, diperluas 2026-11-02). */
    public function isInUse(): bool
    {
        return $this->inUseReasons() !== [];
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