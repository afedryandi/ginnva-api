<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Rekening kas atau bank perusahaan (Keuangan Fase 1, 2026-11-05). Tiap rekening terhubung ke satu akun kas di Bagan Akun, sehingga saldo
 * tiap kas toko dan tiap rekening bank terpisah. Metode bayar yang diterimanya menentukan ke mana uang pembayaran booking masuk
 * (lihat App\Services\PaymentAccountResolver). Nomor rekening dienkripsi di database.
 */
class PaymentAccount extends Model
{
    use LogsActivity;

    public const KINDS = ['kas' => 'Kas (tunai di toko)', 'kas_kecil' => 'Kas Kecil (dana tetap)', 'bank' => 'Rekening Bank'];

    protected $fillable = [
        'name', 'kind', 'chart_of_account_id', 'store_id', 'bank_name', 'account_number', 'account_holder', 'methods', 'is_default', 'is_active', 'notes',
        'float_amount', 'voucher_limit', 'custodian_id',
    ];

    protected $casts = [
        'methods' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'float_amount' => 'decimal:2',
        'voucher_limit' => 'decimal:2',
        'account_number' => 'encrypted',
    ];

    protected static function booted(): void
    {
        // Satu rekening bawaan per lingkup (per toko, atau satu untuk pusat): menetapkan yang baru melepas yang lama.
        static::saved(function (PaymentAccount $account) {
            if ($account->is_default) {
                static::where('id', '!=', $account->id)
                    ->where('store_id', $account->store_id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'kind', 'chart_of_account_id', 'store_id', 'bank_name', 'methods', 'is_default', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('payment_account');
    }

    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** Pemegang kas untuk rekening kas kecil. */
    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function acceptsMethod(?string $method): bool
    {
        return $method !== null && in_array($method, $this->methods ?? [], true);
    }

    /** Akun kas di Bagan Akun masih bisa dipakai jurnal. */
    public function isUsable(): bool
    {
        $account = $this->chartAccount;

        return $this->is_active && $account && $account->is_active && $account->is_postable && $account->is_cash;
    }
}
