<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RawMaterial extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name',
        'code',
        'category',
        'unit',
        'current_stock',
        'reorder_point',
        'unit_cost',
        'expiry_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'current_stock' => 'decimal:2',
        'reorder_point' => 'decimal:2',
        'unit_cost'     => 'decimal:2',
        'expiry_date'   => 'date',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(RawMaterialMovement::class)->latest();
    }

    public function isLowStock(): bool
    {
        return $this->reorder_point !== null && $this->current_stock <= $this->reorder_point;
    }

    /**
     * "Mendekati" = kedaluwarsa dalam 30 hari ke depan atau sudah lewat —
     * dipakai buat badge peringatan, bukan pemblokiran (staff tetap bisa
     * pakai/keluarkan barangnya, sistem cuma mengingatkan).
     */
    public function isNearExpiry(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->lte(now()->addDays(30));
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    /**
     * Catat 1 kejadian masuk/keluar SEKALIGUS update current_stock —
     * berbeda dari InventoryItem::recordMovement() yang cuma toggle
     * status, di sini quantity-nya harus dijumlah/dikurangkan. Dibungkus
     * transaction + row lock supaya 2 staff yang input barengan tidak
     * saling menimpa saldo.
     *
     * @throws \InvalidArgumentException kalau stok keluar melebihi stok yang tersedia.
     */
    public function recordMovement(string $type, float $quantity, ?int $userId, ?string $note = null): RawMaterialMovement
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Jumlah harus lebih besar dari 0.');
        }

        return DB::transaction(function () use ($type, $quantity, $userId, $note) {
            $material = self::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if ($type === 'out' && $material->current_stock < $quantity) {
                throw new \InvalidArgumentException("Stok tidak cukup — sisa stok saat ini {$material->current_stock} {$material->unit}.");
            }

            $material->update([
                'current_stock' => $type === 'in'
                    ? $material->current_stock + $quantity
                    : $material->current_stock - $quantity,
            ]);

            $movement = $material->movements()->create([
                'type' => $type,
                'quantity' => $quantity,
                'note' => $note,
                'user_id' => $userId,
            ]);

            $this->setRawAttributes($material->getAttributes());

            return $movement;
        });
    }

    /**
     * Stock opname — staff input hasil hitung fisik SEBENARNYA, sistem
     * yang menghitung selisihnya sendiri dan mencatatnya sebagai 1
     * movement bertipe 'adjustment' (quantity BOLEH negatif, beda dari
     * 'in'/'out' yang selalu positif) — supaya current_stock tidak
     * pernah diam-diam ditimpa tanpa jejak, sekecil apa pun selisihnya.
     *
     * Return null (tidak ada movement dibuat) kalau hasil hitung sama
     * persis dengan sistem — tidak perlu bikin baris riwayat kosong.
     */
    public function adjustStock(float $actualQuantity, ?int $userId, ?string $note = null): ?RawMaterialMovement
    {
        return DB::transaction(function () use ($actualQuantity, $userId, $note) {
            $material = self::where('id', $this->id)->lockForUpdate()->firstOrFail();
            $delta = round($actualQuantity - (float) $material->current_stock, 2);

            if (abs($delta) < 0.01) {
                return null;
            }

            $material->update(['current_stock' => $actualQuantity]);

            $movement = $material->movements()->create([
                'type' => 'adjustment',
                'quantity' => $delta,
                'note' => $note,
                'user_id' => $userId,
            ]);

            $this->setRawAttributes($material->getAttributes());

            return $movement;
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'category', 'unit', 'current_stock', 'reorder_point', 'unit_cost', 'expiry_date', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('raw_material')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Bahan baku \"{$this->name}\" didaftarkan",
                'updated' => "Bahan baku \"{$this->name}\" diubah",
                'deleted' => "Bahan baku \"{$this->name}\" dihapus",
                default => "Bahan baku \"{$this->name}\" — {$eventName}",
            });
    }
}
