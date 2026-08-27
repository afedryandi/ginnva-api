<?php

namespace App\Models;

use App\Models\Concerns\Acknowledgeable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Barang Habis Pakai — lihat catatan lengkap di migrasi
 * create_consumable_items_table. Method-method di sini SENGAJA identik
 * dengan RawMaterial (recordMovement/adjustStock/isLowStock) karena
 * kelakuannya memang sama — cuma domain datanya beda.
 */
class ConsumableItem extends Model
{
    use LogsActivity;
    use Acknowledgeable;

    protected $fillable = [
        'name',
        'code',
        'category',
        'received_date',
        'unit',
        'current_stock',
        'reorder_point',
        'unit_cost',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'current_stock' => 'decimal:2',
        'reorder_point' => 'decimal:2',
        'unit_cost'     => 'decimal:2',
        'received_date' => 'date',
        'reviewed_at'   => 'datetime',
    ];

    public const DEAD_STOCK_DAYS = 60;

    public function isDeadStock(): bool
    {
        return (float) $this->current_stock > 0
            && $this->updated_at !== null
            && $this->updated_at->lt(now()->subDays(self::DEAD_STOCK_DAYS));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(ConsumableItemMovement::class)->latest();
    }

    public function isLowStock(): bool
    {
        return $this->reorder_point !== null && $this->current_stock <= $this->reorder_point;
    }

    /**
     * Sama persis pola RawMaterial::recordMovement() — TANPA sistem
     * batch (disepakati tidak diperlukan, barang habis pakai tidak
     * kedaluwarsa), tapi $unitCost tetap disalin ke movement + dipakai
     * memperbarui "harga terakhir" di item, supaya riwayat harga
     * historis tidak hilang kalau harga beli berubah antar pembelian.
     *
     * @throws \InvalidArgumentException kalau stok keluar melebihi stok yang tersedia.
     */
    public function recordMovement(string $type, float $quantity, ?int $userId, ?string $note = null, ?float $unitCost = null): ConsumableItemMovement
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Jumlah harus lebih besar dari 0.');
        }

        return DB::transaction(function () use ($type, $quantity, $userId, $note, $unitCost) {
            $item = self::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if ($type === 'out' && $item->current_stock < $quantity) {
                throw new \InvalidArgumentException("Stok tidak cukup — sisa stok saat ini {$item->current_stock} {$item->unit}.");
            }

            $item->update([
                'current_stock' => $type === 'in'
                    ? $item->current_stock + $quantity
                    : $item->current_stock - $quantity,
                'unit_cost' => ($type === 'in' && $unitCost !== null) ? $unitCost : $item->unit_cost,
            ]);

            $movement = $item->movements()->create([
                'type' => $type,
                'quantity' => $quantity,
                'unit_cost' => $type === 'in' ? ($unitCost ?? $item->unit_cost) : null,
                'note' => $note,
                'user_id' => $userId,
            ]);

            $this->setRawAttributes($item->getAttributes());

            return $movement;
        });
    }

    /**
     * Sama persis pola RawMaterial::adjustStock() — stock opname.
     */
    public function adjustStock(float $actualQuantity, ?int $userId, ?string $note = null): ?ConsumableItemMovement
    {
        return DB::transaction(function () use ($actualQuantity, $userId, $note) {
            $item = self::where('id', $this->id)->lockForUpdate()->firstOrFail();
            $delta = round($actualQuantity - (float) $item->current_stock, 2);

            if (abs($delta) < 0.01) {
                return null;
            }

            $item->update(['current_stock' => $actualQuantity]);

            $movement = $item->movements()->create([
                'type' => 'adjustment',
                'quantity' => $delta,
                'note' => $note,
                'user_id' => $userId,
            ]);

            $this->setRawAttributes($item->getAttributes());

            return $movement;
        });
    }

    /**
     * Koreksi resmi kalau staff salah catat kuantitas/tipe — SEBELUMNYA
     * satu-satunya jalan koreksi adalah "Sesuaikan Stok" (opname), yang
     * kurang presisi (staff harus tahu angka hasil hitung fisik yang
     * BENAR, bukan sekadar "batalkan yang barusan salah"). Sama pola
     * dengan InventoryItem::reverseLastMovement() — cuma bisa untuk
     * movement TERAKHIR, meninggalkan 1 baris "Koreksi" sebagai jejak,
     * bukan menghapus tanpa bekas.
     *
     * @throws \InvalidArgumentException kalau movement ini bukan yang terbaru.
     */
    public function reverseLastMovement(ConsumableItemMovement $movement, ?int $userId): void
    {
        if ($movement->consumable_item_id !== $this->id) {
            throw new \InvalidArgumentException('Baris riwayat ini bukan milik barang ini.');
        }

        DB::transaction(function () use ($movement, $userId) {
            $item = self::where('id', $this->id)->lockForUpdate()->firstOrFail();

            $isLatest = ! $item->movements()->where('id', '>', $movement->id)->exists();
            if (! $isLatest) {
                throw new \InvalidArgumentException('Cuma bisa membatalkan riwayat paling terakhir — sudah ada kejadian lain setelah ini.');
            }

            // Kebalikan dari efek movement yang dibatalkan: 'in' berarti
            // stok dikurangi lagi, 'out' berarti dikembalikan, 'adjustment'
            // berarti delta-nya (boleh negatif) dibalik.
            $reversedStock = match ($movement->type) {
                'in' => (float) $item->current_stock - (float) $movement->quantity,
                'out' => (float) $item->current_stock + (float) $movement->quantity,
                'adjustment' => (float) $item->current_stock - (float) $movement->quantity,
                default => (float) $item->current_stock,
            };

            $item->update(['current_stock' => max(0, $reversedStock)]);

            $movement->delete();

            $item->movements()->create([
                'type' => 'correction',
                'quantity' => 0,
                'note' => 'Koreksi: membatalkan pencatatan "' . match ($movement->type) {
                    'in' => 'Masuk',
                    'out' => 'Keluar',
                    'adjustment' => 'Penyesuaian (Opname)',
                    default => $movement->type,
                } . '" yang salah.',
                'user_id' => $userId,
            ]);

            $this->setRawAttributes($item->getAttributes());
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'category', 'received_date', 'unit', 'current_stock', 'reorder_point', 'unit_cost', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('consumable_item')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Barang habis pakai \"{$this->name}\" didaftarkan",
                'updated' => "Barang habis pakai \"{$this->name}\" diubah",
                'deleted' => "Barang habis pakai \"{$this->name}\" dihapus",
                default => "Barang habis pakai \"{$this->name}\" — {$eventName}",
            });
    }
}
