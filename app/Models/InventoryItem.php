<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class InventoryItem extends Model
{
    use LogsActivity;

    protected $fillable = [
        'code',
        'name',
        'category',
        'scroll_code_id',
        'status',
        'notes',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class)->latest();
    }

    /**
     * Opsional — hanya terisi kalau barangnya PPF/window film dan sudah
     * dikaitkan ke kode gulungan tertentu (lihat ScrollCode::inventoryItem()
     * untuk sisi baliknya).
     */
    public function scrollCode(): BelongsTo
    {
        return $this->belongsTo(ScrollCode::class);
    }

    /**
     * Kode unik untuk 1 unit fisik (kardus/gulungan) — dikodekan ke QR
     * yang ditempel ke barang. Format acak (bukan sequential) supaya
     * orang tidak bisa menebak-nebak kode kardus lain hanya dari 1 kode
     * yang terlihat.
     */
    public static function generateCode(): string
    {
        do {
            $code = 'INV-' . strtoupper(Str::random(8));
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /**
     * Catat 1 kejadian keluar/masuk sekaligus update status — dipakai
     * dari endpoint scan mobile app. Karena 1 kardus = 1 unit, tidak ada
     * kuantitas untuk dihitung — cuma perlu jaga supaya tidak bisa
     * "masuk" barang yang statusnya memang sudah di gudang (in_stock),
     * atau "keluar" barang yang memang sudah keluar (out). Dibungkus
     * transaction + row lock supaya 2 staff yang scan barang sama nyaris
     * bersamaan tidak saling menimpa.
     *
     * @throws \InvalidArgumentException kalau transisi status tidak masuk akal
     *         (mis. "catat masuk" untuk barang yang sudah in_stock).
     *
     * @param int|null $storeId Toko tujuan — cuma dipakai kalau barangnya
     *        punya kode gulungan terkait DAN $type === 'out'. Untuk staff
     *        biasa ini SELALU toko staff itu sendiri (lihat
     *        StaffInventoryController::storeMovement()), jadi tidak perlu
     *        dipilih manual — cuma full-access yang isi ini manual karena
     *        tidak terikat 1 toko.
     */
    public function recordMovement(string $type, ?int $userId, ?string $note = null, ?int $storeId = null): InventoryMovement
    {
        return DB::transaction(function () use ($type, $userId, $note, $storeId) {
            $item = self::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if ($type === 'in' && $item->status === 'in_stock') {
                throw new \InvalidArgumentException('Barang ini sudah tercatat ada di gudang (in_stock).');
            }
            if ($type === 'out' && $item->status === 'out') {
                throw new \InvalidArgumentException('Barang ini sudah tercatat keluar sebelumnya.');
            }

            $item->update(['status' => $type === 'in' ? 'in_stock' : 'out']);

            // Barang PPF/window film yang punya kode gulungan terkait:
            // status DAN toko kodenya ikut sinkron otomatis, supaya menu
            // Kode Gulungan tidak perlu diurus manual terpisah lagi untuk
            // kasus per-barang (tetap dipakai buat pra-alokasi massal).
            // "used" tidak pernah ditimpa di sini — itu cuma berubah lewat
            // pemakaian warranty sungguhan / tombol "Tandai Habis".
            if ($item->scroll_code_id) {
                $scrollCode = ScrollCode::find($item->scroll_code_id);

                if ($scrollCode?->status === 'unallocated' && $type === 'out') {
                    $scrollCode->update(['status' => 'allocated', 'allocated_at' => now(), 'store_id' => $storeId]);
                } elseif ($scrollCode?->status === 'allocated' && $type === 'in') {
                    $scrollCode->update(['status' => 'unallocated', 'allocated_at' => null, 'store_id' => null]);
                }
            }

            $movement = $item->movements()->create([
                'type' => $type,
                'note' => $note,
                'user_id' => $userId,
            ]);

            $this->setRawAttributes($item->getAttributes());

            return $movement;
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'category', 'status', 'scroll_code_id', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('inventory_item')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Barang inventaris \"{$this->name}\" ({$this->code}) didaftarkan",
                'updated' => "Barang inventaris \"{$this->name}\" ({$this->code}) diubah",
                'deleted' => "Barang inventaris \"{$this->name}\" ({$this->code}) dihapus",
                default => "Barang inventaris \"{$this->name}\" — {$eventName}",
            });
    }
}
