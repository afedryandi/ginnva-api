<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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
        'received_date',
        'scroll_code_id',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'received_date' => 'date',
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
     * Riwayat "Catat Pemakaian" (meter) dari kode gulungan terkait barang
     * ini — ditampilkan di InventoryItemResource supaya staff yang kerja
     * dari Produk PPF/WF tidak perlu pindah ke menu Kode Gulungan untuk
     * lihat riwayatnya. hasManyThrough() TIDAK bisa dipakai apa adanya di
     * sini karena arah relasinya kebalik dari asumsi normalnya (biasanya
     * "through" yang punya FK ke parent, di sini PARENT (InventoryItem)
     * yang punya FK ke "through" lewat kolom scroll_code_id) — makanya
     * firstKey/localKey ditukar posisi dari default.
     */
    public function scrollCodeUsages(): HasManyThrough
    {
        return $this->hasManyThrough(
            ScrollCodeUsage::class,
            ScrollCode::class,
            'id',              // firstKey: kolom di scroll_codes yang dicocokkan ke localKey
            'scroll_code_id',  // secondKey: FK di scroll_code_usages yang mengarah ke scroll_codes
            'scroll_code_id',  // localKey: kolom di inventory_items yang mengarah ke scroll_codes.id
            'id'               // secondLocalKey: primary key scroll_codes
        );
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
                // Cuma relevan untuk 'out' ("ke toko mana") — 'in' berarti
                // balik ke gudang pusat, tidak terikat 1 toko tertentu.
                'destination_store_id' => $type === 'out' ? $storeId : null,
                'user_id' => $userId,
            ]);

            $this->setRawAttributes($item->getAttributes());

            return $movement;
        });
    }

    /**
     * Koreksi resmi kalau staff salah scan/salah pilih tipe (mis. maksud
     * hati "keluar" ke toko A tapi kepencet "masuk", atau salah scan
     * barang) — SEBELUMNYA satu-satunya jalan cuma catat movement
     * kebalikannya secara manual, yang menyisakan riwayat 2 baris (1
     * salah + 1 pembetulan) tanpa keterangan bahwa salah satunya adalah
     * kesalahan. Ini membatalkan movement TERAKHIR (dan HANYA kalau itu
     * benar-benar yang terbaru — sama pola dengan ScrollCode::reverseUsage())
     * lalu mengembalikan status barang ke sebelumnya, TERBATAS full-access
     * karena mengubah riwayat yang idealnya permanen.
     *
     * @throws \InvalidArgumentException kalau movement ini bukan yang
     *         terbaru, atau movement-nya berhubungan dengan sinkronisasi
     *         status ScrollCode yang sudah berubah lagi sejak itu.
     */
    public function reverseLastMovement(InventoryMovement $movement, ?int $userId): void
    {
        if ($movement->inventory_item_id !== $this->id) {
            throw new \InvalidArgumentException('Baris riwayat ini bukan milik barang ini.');
        }

        DB::transaction(function () use ($movement, $userId) {
            $item = self::where('id', $this->id)->lockForUpdate()->firstOrFail();

            $isLatest = ! $item->movements()->where('id', '>', $movement->id)->exists();
            if (! $isLatest) {
                throw new \InvalidArgumentException('Cuma bisa membatalkan riwayat paling terakhir — sudah ada kejadian lain setelah ini.');
            }

            // Kembalikan status ke KEBALIKAN dari movement yang dibatalkan
            // ('in' yang dibatalkan berarti balik ke 'out', dst).
            $item->update(['status' => $movement->type === 'in' ? 'out' : 'in_stock']);

            if ($item->scroll_code_id) {
                $scrollCode = ScrollCode::where('id', $item->scroll_code_id)->lockForUpdate()->first();

                if ($scrollCode?->status === 'allocated' && $movement->type === 'out') {
                    $scrollCode->update(['status' => 'unallocated', 'allocated_at' => null, 'store_id' => null]);
                } elseif ($scrollCode?->status === 'unallocated' && $movement->type === 'in') {
                    $scrollCode->update(['status' => 'allocated', 'allocated_at' => now(), 'store_id' => $movement->destination_store_id]);
                }
            }

            $movement->delete();

            $item->movements()->create([
                'type' => 'correction',
                'note' => 'Koreksi: membatalkan pencatatan "' . ($movement->type === 'in' ? 'Masuk' : 'Keluar') . '" yang salah.',
                'user_id' => $userId,
            ]);

            $this->setRawAttributes($item->getAttributes());
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'category', 'received_date', 'status', 'scroll_code_id', 'notes'])
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