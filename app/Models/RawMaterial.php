<?php

namespace App\Models;

use App\Models\Concerns\Acknowledgeable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RawMaterial extends Model
{
    use LogsActivity;
    use Acknowledgeable;

    protected $fillable = [
        'name',
        'code',
        'category',
        'received_date',
        'unit',
        // Konversi satuan beli → satuan dasar (audit Majoo f38) --
        // opsional, murni pembantu input di "Catat Stok". `unit` &
        // `current_stock` TETAP selalu dalam satuan dasar (ml/gram/dst),
        // tidak pernah dalam purchase_unit. Lihat migrasi
        // 2026_09_22_000014.
        'purchase_unit',
        'purchase_conversion_factor',
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
        'purchase_conversion_factor' => 'decimal:4',
        'received_date' => 'date',
        'expiry_date'   => 'date',
        'reviewed_at'   => 'datetime',
    ];

    /**
     * Konversi input "Catat Stok Masuk" dari satuan BELI ke satuan
     * DASAR (unit/current_stock) -- audit Majoo f38. Dipakai HANYA saat
     * staff pilih "Input dalam Satuan Beli" di form Catat Stok; movement
     * yang tersimpan SELALU dalam satuan dasar seperti biasa, tidak ada
     * kolom baru di raw_material_movements.
     *
     * @return array{quantity: float, unitCost: ?float} quantity dalam
     *         satuan dasar, unitCost per satuan dasar (null kalau
     *         $purchaseTotalCost tidak diisi).
     */
    public function convertPurchaseToBaseUnit(float $purchaseQuantity, ?float $purchaseTotalCost): array
    {
        $factor = (float) ($this->purchase_conversion_factor ?? 1);
        $quantity = $purchaseQuantity * $factor;

        return [
            'quantity' => $quantity,
            'unitCost' => ($purchaseTotalCost !== null && $quantity > 0) ? $purchaseTotalCost / $quantity : null,
        ];
    }

    /**
     * "Mati" = punya stok tapi TIDAK ADA pergerakan (masuk/keluar/opname)
     * dalam 60 hari terakhir — updated_at dipakai sebagai proksi waktu
     * pergerakan terakhir karena recordMovement()/adjustStock() SELALU
     * ikut update() baris material ini (current_stock berubah). Ambang 60
     * hari dipilih sebagai default wajar untuk bahan baku consumable —
     * sesuaikan lewat konstanta ini kalau kebutuhan bisnisnya beda.
     */
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
        return $this->hasMany(RawMaterialMovement::class)->latest();
    }

    /**
     * Diurut received_date lalu id — urutan ini JUGA yang dipakai
     * consumeBatchesFifo() untuk menentukan batch mana dihabiskan duluan.
     */
    public function batches(): HasMany
    {
        return $this->hasMany(RawMaterialBatch::class)->orderBy('received_date')->orderBy('id');
    }

    public function isLowStock(): bool
    {
        return $this->reorder_point !== null && $this->current_stock <= $this->reorder_point;
    }

    /**
     * Kolom expiry_date di TABEL INI cuma snapshot sekali waktu daftar
     * bahan (dipakai sebagai default batch PERTAMA di CreateRawMaterial,
     * lihat catatan di sana) — begitu ada batch ke-2 dst dengan tanggal
     * kedaluwarsa beda-beda, kolom ini TIDAK pernah ikut sinkron. Status
     * kedaluwarsa yang sebenarnya harus dihitung dari batch yang MASIH
     * ADA stoknya (quantity > 0), diambil yang paling awal kedaluwarsa —
     * itu yang PALING MENDESAK dan yang seharusnya menentukan status
     * bahan ini secara keseluruhan (badge, filter, dashboard), BUKAN
     * kolom statis di baris material. SEBELUMNYA isNearExpiry()/
     * isExpired() baca $this->expiry_date langsung — bisa bilang "aman"
     * padahal ada batch yang sudah lewat kedaluwarsa (atau sebaliknya).
     */
    public function earliestActiveExpiryDate(): ?\Illuminate\Support\Carbon
    {
        $date = $this->batches()
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->min('expiry_date');

        return $date ? \Illuminate\Support\Carbon::parse($date) : null;
    }

    /**
     * "Mendekati" = kedaluwarsa dalam 30 hari ke depan atau sudah lewat —
     * dipakai buat badge peringatan, bukan pemblokiran (staff tetap bisa
     * pakai/keluarkan barangnya, sistem cuma mengingatkan).
     */
    public function isNearExpiry(): bool
    {
        $date = $this->earliestActiveExpiryDate();

        return $date !== null && $date->lte(now()->addDays(30));
    }

    public function isExpired(): bool
    {
        $date = $this->earliestActiveExpiryDate();

        return $date !== null && $date->isPast();
    }

    /**
     * Catat 1 kejadian masuk/keluar SEKALIGUS update current_stock —
     * berbeda dari InventoryItem::recordMovement() yang cuma toggle
     * status, di sini quantity-nya harus dijumlah/dikurangkan. Dibungkus
     * transaction + row lock supaya 2 staff yang input barengan tidak
     * saling menimpa saldo.
     *
     * Setiap "masuk" JUGA bikin 1 batch baru (1 kejadian Catat Stok = 1
     * batch, TIDAK dipecah lagi per botol/wadah — sempat begitu, tapi
     * kerumitannya tidak sepadan) — supaya beberapa batch bahan yang sama
     * bisa dilacak terpisah kalau tanggal masuk/kedaluwarsanya beda-beda.
     * Setiap "keluar" mengonsumsi batch FIFO (received_date paling lama
     * duluan) lewat consumeBatchesFifo() — current_stock TETAP jadi
     * sumber kebenaran utama untuk validasi & tampilan, batch cuma
     * pelacakan tambahan.
     *
     * @param  int|null  $storeId  Penanda cabang (Topik 4, Fase 1,
     *         2026-09-19) -- OPSIONAL, murni tag "cabang mana yang
     *         melakukan kejadian ini", TIDAK memecah current_stock (masih
     *         1 angka nasional). Dibiarkan null kalau tidak relevan
     *         (mis. stok awal/import Excel yang company-wide).
     *
     * @throws \InvalidArgumentException kalau stok keluar melebihi stok yang tersedia.
     */
    public function recordMovement(string $type, float $quantity, ?int $userId, ?string $note = null, ?string $receivedDate = null, ?string $expiryDate = null, ?float $unitCost = null, ?int $storeId = null): RawMaterialMovement
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Jumlah harus lebih besar dari 0.');
        }

        return DB::transaction(function () use ($type, $quantity, $userId, $note, $receivedDate, $expiryDate, $unitCost, $storeId) {
            $material = self::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if ($type === 'out' && $material->current_stock < $quantity) {
                throw new \InvalidArgumentException("Stok tidak cukup — sisa stok saat ini {$material->current_stock} {$material->unit}.");
            }

            if ($type === 'in') {
                $material->batches()->create([
                    'quantity' => $quantity,
                    // Opsional per batch — supaya valuasi stok bisa dihitung
                    // dari harga beli batch itu SENDIRI, bukan cuma 1 harga
                    // rata-rata di raw_materials.unit_cost. Kalau kosong,
                    // dipakai harga terakhir yang tersimpan di material
                    // sebagai perkiraan (dan sinkron balik di bawah).
                    'unit_cost' => $unitCost ?? $material->unit_cost,
                    'received_date' => $receivedDate ?? now()->toDateString(),
                    'expiry_date' => $expiryDate,
                    'created_by' => $userId,
                ]);

                // "Harga terakhir" di material ikut diperbarui kalau admin
                // isi harga baru saat Catat Stok — dipakai sebagai default
                // saran harga untuk batch berikutnya.
                if ($unitCost !== null && $unitCost !== (float) $material->unit_cost) {
                    $material->update(['unit_cost' => $unitCost]);
                }
            } else {
                static::consumeBatchesFifo($material, $quantity);
            }

            $material->update([
                'current_stock' => $type === 'in'
                    ? $material->current_stock + $quantity
                    : $material->current_stock - $quantity,
            ]);

            $movement = $material->movements()->create([
                'type' => $type,
                'quantity' => $quantity,
                // Cuma relevan untuk 'in' — salinan harga batch yang baru
                // dibuat, supaya riwayat pergerakan bisa tampilkan harga
                // langsung tanpa perlu buka tab Batch terpisah.
                'unit_cost' => $type === 'in' ? ($unitCost ?? $material->unit_cost) : null,
                'note' => $note,
                'user_id' => $userId,
                'store_id' => $storeId,
            ]);

            $this->setRawAttributes($material->getAttributes());

            return $movement;
        });
    }

    /**
     * Kurangi batch tertua duluan sampai $quantity terpenuhi. Kalau total
     * quantity di semua batch < $quantity (mis. data lama sebelum fitur
     * batch ada, atau sudah pernah "Sesuaikan Stok" tanpa batch matching)
     * — sisa yang tidak tertutup batch DIBIARKAN, current_stock (bukan
     * jumlah batch) yang tetap jadi sumber kebenaran utama.
     */
    private static function consumeBatchesFifo(self $material, float $quantity): void
    {
        $remaining = $quantity;

        $batches = $material->batches()->where('quantity', '>', 0)->lockForUpdate()->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) break;

            $take = min((float) $batch->quantity, $remaining);
            $batch->decrement('quantity', $take);
            $remaining = round($remaining - $take, 2);
        }
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
    public function adjustStock(float $actualQuantity, ?int $userId, ?string $note = null, ?int $storeId = null): ?RawMaterialMovement
    {
        return DB::transaction(function () use ($actualQuantity, $userId, $note, $storeId) {
            $material = self::where('id', $this->id)->lockForUpdate()->firstOrFail();
            $delta = round($actualQuantity - (float) $material->current_stock, 2);

            if (abs($delta) < 0.01) {
                return null;
            }

            if ($delta > 0) {
                // Ketemu stok lebih dari catatan sistem — dicatat sebagai
                // batch baru "tidak diketahui asalnya" (tanggal masuk hari
                // ini, tanpa kedaluwarsa) supaya tetap ikut FIFO ke depannya.
                $material->batches()->create([
                    'quantity' => $delta,
                    'received_date' => now()->toDateString(),
                    'expiry_date' => null,
                    'is_adjustment' => true,
                    'created_by' => $userId,
                ]);
            } else {
                static::consumeBatchesFifo($material, abs($delta));
            }

            $material->update(['current_stock' => $actualQuantity]);

            $movement = $material->movements()->create([
                'type' => 'adjustment',
                'quantity' => $delta,
                'note' => $note,
                'user_id' => $userId,
                'store_id' => $storeId,
            ]);

            $this->setRawAttributes($material->getAttributes());

            return $movement;
        });
    }

    /**
     * Pembatalan kejadian PALING TERAKHIR — sama fitur dengan
     * ConsumableItem::reverseLastMovement()/InventoryItem, ditambahkan
     * di audit 2026-09-14 (RawMaterial sebelumnya satu-satunya yang
     * tidak punya ini, inkonsisten). BEDA dari ConsumableItem karena
     * bahan baku pakai batch FIFO:
     * - type 'in': batch yang dibuat movement ini ikut DIHAPUS PRESISI —
     *   aman karena guard di Resource menjamin ini movement TERAKHIR
     *   untuk bahan ini, jadi batch yang baru dibuatnya pasti batch
     *   TERBARU (belum ada 'in'/'adjustment naik' lain setelahnya yang
     *   bisa bikin batch baru lagi).
     * - type 'out'/'adjustment': batch TIDAK disentuh sama sekali —
     *   sistem tidak menyimpan batch mana & berapa yang dikonsumsi FIFO
     *   per kejadian, jadi mengembalikannya presisi ke batch asal tidak
     *   mungkin tanpa data tambahan. Cuma current_stock yang dikoreksi
     *   (sama seperti pola ConsumableItem, yang memang tidak punya
     *   batch sama sekali). Staff perlu tahu ini — lihat modalDescription
     *   di RawMaterialMovementResource.
     */
    public function reverseLastMovement(RawMaterialMovement $movement, ?int $userId): void
    {
        if ($movement->raw_material_id !== $this->id) {
            throw new \InvalidArgumentException('Baris riwayat ini bukan milik bahan ini.');
        }

        DB::transaction(function () use ($movement, $userId) {
            $material = self::where('id', $this->id)->lockForUpdate()->firstOrFail();

            $isLatest = ! $material->movements()->where('id', '>', $movement->id)->exists();
            if (! $isLatest) {
                throw new \InvalidArgumentException('Cuma bisa membatalkan riwayat paling terakhir — sudah ada kejadian lain setelah ini.');
            }

            $reversedStock = match ($movement->type) {
                'in' => (float) $material->current_stock - (float) $movement->quantity,
                'out' => (float) $material->current_stock + (float) $movement->quantity,
                'adjustment' => (float) $material->current_stock - (float) $movement->quantity,
                default => (float) $material->current_stock,
            };

            if ($movement->type === 'in') {
                $latestBatch = $material->batches()->orderByDesc('id')->first();
                $latestBatch?->delete();
            }

            $material->update(['current_stock' => max(0, $reversedStock)]);

            $movement->delete();

            $material->movements()->create([
                'type' => 'correction',
                'quantity' => 0,
                'note' => 'Koreksi: membatalkan pencatatan "' . match ($movement->type) {
                    'in' => 'Masuk',
                    'out' => 'Keluar',
                    'adjustment' => 'Penyesuaian (Opname)',
                    default => $movement->type,
                } . '" yang salah.',
                'user_id' => $userId,
                // Koreksi mewarisi penanda cabang movement yang dibatalkan.
                'store_id' => $movement->store_id,
            ]);

            $this->setRawAttributes($material->getAttributes());
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'category', 'received_date', 'unit', 'purchase_unit', 'purchase_conversion_factor', 'current_stock', 'reorder_point', 'unit_cost', 'expiry_date', 'notes'])
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