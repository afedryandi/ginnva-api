<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Batch Penerimaan: satu kali penerimaan barang (satu import Excel, satu penambahan produk, satu Catat Stok Masuk bahan baku).
 * Nomor otomatis BTH-yymmdd-NNN menurut tanggal terima. Pemasok dan nomor faktur opsional.
 */
class StockBatch extends Model
{
    public const TYPE_PPF_WF = 'ppf_wf';

    public const TYPE_RAW_MATERIAL = 'raw_material';

    public const TYPE_LABELS = [
        self::TYPE_PPF_WF => 'PPF/WF',
        self::TYPE_RAW_MATERIAL => 'Bahan Baku',
    ];

    protected $fillable = ['batch_number', 'type', 'received_date', 'supplier_id', 'invoice_number', 'notes', 'created_by'];

    protected $casts = ['received_date' => 'date'];

    /**
     * Buat batch baru dengan nomor otomatis. Nomor urut per tanggal terima; bentrok (dua penerimaan bersamaan) diulang dengan nomor
     * berikutnya, dijaga unique index.
     *
     * @param  array{received_date?: string|null, supplier_id?: int|null, invoice_number?: string|null, notes?: string|null, created_by?: int|null}  $attributes
     */
    public static function open(string $type, array $attributes = []): self
    {
        $date = Carbon::parse($attributes['received_date'] ?? now())->startOfDay();
        $prefix = 'BTH-' . $date->format('ymd') . '-';
        $sequence = self::where('batch_number', 'like', $prefix . '%')->count() + 1;

        for ($attempt = 0; $attempt < 10; $attempt++, $sequence++) {
            try {
                return self::create([
                    'batch_number' => $prefix . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                    'type' => $type,
                    'received_date' => $date->toDateString(),
                    'supplier_id' => $attributes['supplier_id'] ?? null,
                    'invoice_number' => filled($attributes['invoice_number'] ?? null) ? trim((string) $attributes['invoice_number']) : null,
                    'notes' => $attributes['notes'] ?? null,
                    'created_by' => $attributes['created_by'] ?? null,
                ]);
            } catch (QueryException $e) {
                if (! str_contains($e->getMessage(), 'batch_number') && ! str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Gagal membuat nomor batch penerimaan. Coba lagi.');
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scrollCodes(): HasMany
    {
        return $this->hasMany(ScrollCode::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function rawMaterialBatches(): HasMany
    {
        return $this->hasMany(RawMaterialBatch::class);
    }
}
