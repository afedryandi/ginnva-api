<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;

/**
 * Nomor induk sebuah Permohonan Pembelian yang memuat satu atau lebih baris barang (PP-yyyymm-NNNN). Setiap baris adalah PurchaseRequest
 * dengan nomor PR sendiri; keputusan dan penerimaan tetap per baris.
 */
class PurchaseRequestGroup extends Model
{
    use HasStoreScope;

    protected $fillable = ['group_number', 'store_id', 'requested_by', 'reason'];

    /** Buat induk baru dengan nomor urut per bulan; bentrok (dua pengajuan bersamaan) diulang dengan nomor berikutnya. */
    public static function open(array $attributes): self
    {
        $prefix = 'PP-' . now()->format('Ym') . '-';
        $sequence = static::withoutGlobalScopes()->where('group_number', 'like', $prefix . '%')->count() + 1;

        for ($attempt = 0; $attempt < 10; $attempt++, $sequence++) {
            try {
                return static::create($attributes + ['group_number' => $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT)]);
            } catch (QueryException $e) {
                if (! str_contains(strtolower($e->getMessage()), 'duplicate') && ! str_contains($e->getMessage(), 'group_number')) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Gagal membuat nomor permohonan. Coba lagi.');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class, 'purchase_request_group_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
