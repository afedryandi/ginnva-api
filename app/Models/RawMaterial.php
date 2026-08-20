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
        'received_date',
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
        'received_date' => 'date',
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
     * Setiap "masuk" JUGA bikin 1 batch baru (mis. 1 tong/drum baru
     * datang) — supaya beberapa batch bahan yang sama bisa dilacak
     * terpisah (tanggal masuk/kedaluwarsa beda-beda). Setiap "keluar"
     * mengonsumsi batch FIFO (received_date paling lama duluan) lewat
     * consumeBatchesFifo() — current_stock TETAP jadi sumber kebenaran
     * utama untuk validasi & tampilan, batch cuma pelacakan tambahan.
     *
     * @throws \InvalidArgumentException kalau stok keluar melebihi stok yang tersedia.
     */
    public function recordMovement(string $type, float $quantity, ?int $userId, ?string $note = null, ?string $receivedDate = null, ?string $expiryDate = null, int $containerCount = 1): RawMaterialMovement
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Jumlah harus lebih besar dari 0.');
        }

        if ($containerCount < 1) {
            throw new \InvalidArgumentException('Jumlah botol/wadah minimal 1.');
        }

        // Dibagi dalam satuan "sen" (1/100) pakai bilangan bulat — round()
        // biasa per botol lalu sisa ditumpuk ke botol terakhir BISA
        // menghasilkan quantity 0 atau malah NEGATIF kalau containerCount
        // terlalu besar dibanding quantity (mis. quantity=1, 200 botol —
        // pembulatan per botol ke atas bikin total kebablasan, sisa untuk
        // botol terakhir jadi minus). Guard ini menolak kombinasi yang
        // tidak bisa dibagi rata minimal 0.01 per botol.
        $totalCents = (int) round($quantity * 100);
        if ($type === 'in' && $containerCount > $totalCents) {
            throw new \InvalidArgumentException("Jumlah botol/wadah ({$containerCount}) terlalu banyak untuk jumlah total {$quantity} — minimal 0.01 per botol.");
        }

        return DB::transaction(function () use ($type, $quantity, $userId, $note, $receivedDate, $expiryDate, $containerCount, $totalCents) {
            $material = self::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if ($type === 'out' && $material->current_stock < $quantity) {
                throw new \InvalidArgumentException("Stok tidak cukup — sisa stok saat ini {$material->current_stock} {$material->unit}.");
            }

            if ($type === 'in') {
                // 1 batch = 1 botol fisik, supaya bisa dipantau sisa per
                // botolnya sendiri-sendiri (bukan cuma total gabungan).
                // Sisa pembagian (dalam sen) ditebar +1 ke botol PALING
                // AWAL, bukan ditumpuk semua ke botol terakhir — supaya
                // tidak ada satu botol pun yang bisa jadi 0/negatif.
                $baseCents = intdiv($totalCents, $containerCount);
                $remainderCents = $totalCents % $containerCount;

                for ($i = 0; $i < $containerCount; $i++) {
                    $containerCents = $baseCents + ($i < $remainderCents ? 1 : 0);

                    $material->batches()->create([
                        'quantity' => $containerCents / 100,
                        'received_date' => $receivedDate ?? now()->toDateString(),
                        'expiry_date' => $expiryDate,
                        'created_by' => $userId,
                    ]);
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
                'note' => $note,
                'user_id' => $userId,
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
    public function adjustStock(float $actualQuantity, ?int $userId, ?string $note = null): ?RawMaterialMovement
    {
        return DB::transaction(function () use ($actualQuantity, $userId, $note) {
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
            ]);

            $this->setRawAttributes($material->getAttributes());

            return $movement;
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'category', 'received_date', 'unit', 'current_stock', 'reorder_point', 'unit_cost', 'expiry_date', 'notes'])
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
