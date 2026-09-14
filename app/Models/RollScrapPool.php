<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * "Sisa Roll" — pool sisa panjang + potongan lebar (masih bisa
 * dipakai) dari berbagai ScrollCode, dikumpulkan per (toko, produk
 * film) lewat aksi "Kumpulkan Sisa" di Produk PPF/WF. Lihat migrasi
 * create_roll_scrap_pools_table untuk latar belakang lengkap.
 */
class RollScrapPool extends Model
{
    // Global Scope store-id (audit framework 2026-09-14, "Isolasi data
    // multi-tenant") — lihat App\Models\Scopes\StoreScope. Pola manual
    // yang sudah ada di RollScrapPoolResource::getEloquentQuery() SENGAJA
    // DIBIARKAN sebagai defense-in-depth.
    use HasStoreScope;

    protected $fillable = [
        'store_id',
        'film_product_id',
        'remaining_length_meters',
    ];

    protected $casts = [
        'remaining_length_meters' => 'decimal:2',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function filmProduct(): BelongsTo
    {
        return $this->belongsTo(FilmProduct::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(RollScrapMovement::class)->latest();
    }

    /**
     * Kumpulkan sisa dari 1 ScrollCode ke pool (toko, produk film)nya —
     * dipanggil dari aksi "Kumpulkan Sisa" di InventoryItemResource.
     * Roll sumbernya ikut ditandai habis (remaining_length_meters=0,
     * status='used') karena sisa gunanya sudah dipindah ke pool ini —
     * mencegah roll yang sama dihitung dobel (sisanya di ScrollCode
     * DAN di pool sekaligus).
     */
    public static function collectFrom(ScrollCode $scrollCode, float $meters, ?int $userId, ?string $note = null): self
    {
        if ($meters <= 0) {
            throw new \InvalidArgumentException('Jumlah meter sisa harus lebih besar dari 0.');
        }

        if ($scrollCode->film_product_id === null) {
            throw new \InvalidArgumentException('Kode gulungan ini belum punya Produk Film — tidak bisa dikumpulkan ke pool sisa.');
        }

        return DB::transaction(function () use ($scrollCode, $meters, $userId, $note) {
            $locked = ScrollCode::where('id', $scrollCode->id)->lockForUpdate()->firstOrFail();

            $pool = self::firstOrCreate(
                ['store_id' => $locked->store_id, 'film_product_id' => $locked->film_product_id],
                ['remaining_length_meters' => 0]
            );

            $pool = self::where('id', $pool->id)->lockForUpdate()->firstOrFail();
            $pool->update(['remaining_length_meters' => round((float) $pool->remaining_length_meters + $meters, 2)]);

            $pool->movements()->create([
                'type' => 'in',
                'quantity' => $meters,
                'source_scroll_code_id' => $locked->id,
                'user_id' => $userId,
                'note' => $note,
            ]);

            $locked->update([
                'remaining_length_meters' => 0,
                'status' => 'used',
                'used_at' => $locked->used_at ?? now(),
            ]);

            return $pool;
        });
    }

    /**
     * Catat pemakaian sekian meter dari pool ini — dipakai buat
     * instalasi yang pakai sisaan roll, bukan roll baru. $bookingId
     * opsional (tertaut ke booking yang memakainya).
     *
     * @throws \InvalidArgumentException kalau meter yang diminta melebihi sisa pool.
     */
    public function consume(float $meters, ?int $userId, ?string $note = null, ?int $bookingId = null): void
    {
        if ($meters <= 0) {
            throw new \InvalidArgumentException('Jumlah meter harus lebih besar dari 0.');
        }

        DB::transaction(function () use ($meters, $userId, $note, $bookingId) {
            $pool = self::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if ($meters > (float) $pool->remaining_length_meters) {
                throw new \InvalidArgumentException("Meter tidak cukup — sisa pool cuma {$pool->remaining_length_meters} meter.");
            }

            $pool->update(['remaining_length_meters' => round((float) $pool->remaining_length_meters - $meters, 2)]);

            $pool->movements()->create([
                'type' => 'out',
                'quantity' => $meters,
                'booking_id' => $bookingId,
                'user_id' => $userId,
                'note' => $note,
            ]);

            $this->setRawAttributes($pool->getAttributes());
        });
    }

    /**
     * Pembatalan kejadian PALING TERAKHIR — sama pola dengan
     * RawMaterial/ConsumableItem/InventoryItem::reverseLastMovement().
     * Membatalkan movement 'in' TIDAK mengembalikan status ScrollCode
     * sumbernya ke non-'used' (sengaja disederhanakan — kasusnya jarang,
     * dan roll yang sisanya sudah "dikumpulkan" biasanya memang sudah
     * benar-benar habis dipakai instalasi utamanya).
     */
    public function reverseLastMovement(RollScrapMovement $movement, ?int $userId): void
    {
        if ($movement->roll_scrap_pool_id !== $this->id) {
            throw new \InvalidArgumentException('Baris riwayat ini bukan milik pool ini.');
        }

        DB::transaction(function () use ($movement, $userId) {
            $pool = self::where('id', $this->id)->lockForUpdate()->firstOrFail();

            $isLatest = ! $pool->movements()->where('id', '>', $movement->id)->exists();
            if (! $isLatest) {
                throw new \InvalidArgumentException('Cuma bisa membatalkan riwayat paling terakhir — sudah ada kejadian lain setelah ini.');
            }

            $reversed = match ($movement->type) {
                'in' => (float) $pool->remaining_length_meters - (float) $movement->quantity,
                'out' => (float) $pool->remaining_length_meters + (float) $movement->quantity,
                default => (float) $pool->remaining_length_meters,
            };

            $pool->update(['remaining_length_meters' => max(0, round($reversed, 2))]);

            $movement->delete();

            $pool->movements()->create([
                'type' => 'correction',
                'quantity' => 0,
                'note' => 'Koreksi: membatalkan pencatatan "' . match ($movement->type) {
                    'in' => 'Masuk',
                    'out' => 'Keluar',
                    default => $movement->type,
                } . '" yang salah.',
                'user_id' => $userId,
            ]);

            $this->setRawAttributes($pool->getAttributes());
        });
    }
}
