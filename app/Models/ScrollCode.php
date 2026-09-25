<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ScrollCode extends Model
{
    use LogsActivity;

    protected $fillable = [
        'code',
        'film_product_id',
        'store_id',
        'status',
        'usage_count',
        'max_usage',
        'total_length_meters',
        'remaining_length_meters',
        'purchase_cost',
        'allocated_at',
        'used_at',
        'warranty_code',
    ];

    protected $casts = [
        'total_length_meters'     => 'decimal:2',
        'remaining_length_meters' => 'decimal:2',
        'purchase_cost'           => 'decimal:2',
        'allocated_at'            => 'datetime',
        'used_at'                 => 'datetime',
    ];

    /**
     * Harga per meter, diturunkan dari purchase_cost/total_length_meters
     * -- null kalau salah satu belum diisi (audit Majoo f7, "Kolom Laba
     * Kotor"). BUKAN disimpan sebagai kolom sendiri supaya tidak bisa
     * drift dari 2 sumber (purchase_cost & total_length_meters) yang
     * masing-masing bisa diedit terpisah lewat "Edit Panjang & Harga".
     */
    public function costPerMeter(): ?float
    {
        if ($this->purchase_cost === null || $this->total_length_meters === null || (float) $this->total_length_meters <= 0) {
            return null;
        }

        return (float) $this->purchase_cost / (float) $this->total_length_meters;
    }

    public function filmProduct()
    {
        // withTrashed() (audit Daftar Produk 2026-09-25) -- riwayat/
        // traceability roll garansi tetap tampilkan nama produk asli
        // walau produknya sudah dihapus dari katalog aktif.
        return $this->belongsTo(FilmProduct::class)->withTrashed();
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Sisi balik InventoryItem::scrollCode() — unit fisik gudang (kardus/
     * gulungan) yang dikaitkan ke kode ini, kalau sudah pernah didaftarkan
     * lewat menu Barang.
     */
    public function inventoryItem()
    {
        return $this->hasOne(InventoryItem::class);
    }

    /**
     * 1 kode gulungan sekarang bisa dipakai >1 warranty (PPF maupun
     * Window Film, sejak tidak lagi single-use otomatis) — kolom
     * warranty_code di tabel ini cuma menyimpan 1 nilai (warranty
     * TERAKHIR yang memakainya, gampang ketimpa), jadi TIDAK bisa
     * diandalkan untuk menampilkan semua pemakai. Query langsung ke
     * tabel warranties lewat 4 kolom roll_number* untuk dapat daftar
     * lengkapnya.
     */
    public function warranties()
    {
        return \App\Models\Warranty::query()
            ->where('roll_number', $this->code)
            ->orWhere('roll_number_2', $this->code)
            ->orWhere('roll_number_front', $this->code)
            ->orWhere('roll_number_side_rear', $this->code);
    }

    /**
     * Riwayat tiap kejadian "Catat Pemakaian" — beda dari usage_count
     * (cuma angka kumulatif tanpa jejak per kejadian/catatan).
     */
    public function usages()
    {
        return $this->hasMany(ScrollCodeUsage::class)->with('booking:id,booking_number,customer_name')->latest();
    }

    /**
     * Catat pemakaian sekian meter dari gulungan ini — dipanggil dari
     * Filament (ScrollCodeResource/InventoryItemResource) maupun app
     * mobile (staff scan barang, "Catat Pemakaian"). usage_count ikut
     * naik (dipakai gabungan dengan hitungan mobil di WarrantyObserver),
     * dan status otomatis 'used' begitu sisa mencapai 0 — TIDAK perlu
     * "Tandai Habis" manual lagi kalau total_length_meters diisi. Setiap
     * pemakaian JUGA dicatat sebagai 1 baris riwayat (scroll_code_usages)
     * lengkap dengan catatan opsional, supaya bisa dilihat lagi nanti
     * dipakai untuk apa/mobil mana.
     *
     * @throws \InvalidArgumentException kalau meter yang diminta melebihi sisa,
     *         atau gulungan ini belum punya total_length_meters (tidak bisa
     *         dilacak per meter).
     */
    public function recordUsage(float $meters, ?int $userId = null, ?string $note = null, ?int $bookingId = null): void
    {
        if ($meters <= 0) {
            throw new \InvalidArgumentException('Jumlah meter harus lebih besar dari 0.');
        }

        DB::transaction(function () use ($meters, $userId, $note, $bookingId) {
            $scrollCode = self::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if ($scrollCode->remaining_length_meters === null) {
                throw new \InvalidArgumentException('Gulungan ini belum punya data Total Panjang — isi dulu lewat menu Kode Gulungan atau Produk PPF/WF.');
            }

            if ($meters > (float) $scrollCode->remaining_length_meters) {
                // Framing "tidak cukup — sisa X" disamakan dengan
                // RawMaterial::recordMovement()/ConsumableItem::recordMovement()
                // untuk validasi kuantitas kurang yang setara.
                throw new \InvalidArgumentException("Meter tidak cukup — sisa panjang gulungan cuma {$scrollCode->remaining_length_meters} meter.");
            }

            $remaining = round((float) $scrollCode->remaining_length_meters - $meters, 2);

            $scrollCode->update([
                'remaining_length_meters' => $remaining,
                'usage_count' => $scrollCode->usage_count + 1,
                'status' => $remaining <= 0 ? 'used' : $scrollCode->status,
                'used_at' => $remaining <= 0 ? now() : $scrollCode->used_at,
            ]);

            $scrollCode->usages()->create([
                'meters' => $meters,
                'note' => $note,
                'user_id' => $userId,
                'booking_id' => $bookingId,
            ]);

            $this->setRawAttributes($scrollCode->getAttributes());
        });
    }

    /**
     * Batalkan/koreksi 1 baris "Catat Pemakaian" yang salah input di
     * lapangan (mis. staff terburu-buru salah ketik meter) — mengembalikan
     * meternya ke remaining_length_meters, mengurangi usage_count, lalu
     * menghapus baris riwayatnya. HANYA dibuka lewat admin (full-access),
     * bukan staff biasa — koreksi stok fisik harus lewat 1 pintu yang bisa
     * dipertanggungjawabkan, dicatat di activity log (lihat LogsActivity di
     * atas) sebagai perubahan pada ScrollCode ini.
     *
     * Status yang sudah 'used' cuma dibuka balik ke 'allocated' kalau baris
     * yang dibatalkan ini TERBUKTI baris pemakaian TERAKHIR (id tertinggi)
     * — sama pola dengan MaterialMemoStockService::isLatestUsage(), supaya
     * tidak salah membuka balik gulungan yang sebenarnya sudah habis oleh
     * pemakaian lain yang lebih baru.
     */
    public function reverseUsage(ScrollCodeUsage $usage): void
    {
        if ($usage->scroll_code_id !== $this->id) {
            throw new \InvalidArgumentException('Baris riwayat ini bukan milik kode gulungan ini.');
        }

        DB::transaction(function () use ($usage) {
            $scrollCode = self::where('id', $this->id)->lockForUpdate()->firstOrFail();

            $isLatest = ! $scrollCode->usages()->where('id', '>', $usage->id)->exists();

            $restored = round((float) $scrollCode->remaining_length_meters + (float) $usage->meters, 2);

            $scrollCode->update([
                'remaining_length_meters' => $restored,
                'usage_count' => max(0, $scrollCode->usage_count - 1),
                'status' => ($scrollCode->status === 'used' && $isLatest) ? 'allocated' : $scrollCode->status,
                'used_at' => ($scrollCode->status === 'used' && $isLatest) ? null : $scrollCode->used_at,
            ]);

            $usage->delete();

            $this->setRawAttributes($scrollCode->getAttributes());
        });
    }

    /**
     * Riwayat "Mutasi Roll Film antar cabang" -- keputusan atasan
     * 2026-09-19 (Topik 4, Fase 1). Lihat ScrollCodeTransfer & migrasi
     * create_scroll_code_transfers_table.
     */
    public function transfers()
    {
        return $this->hasMany(ScrollCodeTransfer::class)->latest();
    }

    /**
     * Pindahkan kepemilikan gulungan ini ke store lain + catat 1 baris
     * riwayat mutasi -- pola SAMA PERSIS dengan Asset transfer
     * (AssetResource::transfer action), disederhanakan (tanpa
     * assigned_to/kondisi fisik, cuma store + alasan) karena frekuensi
     * mutasi bahan dikonfirmasi jarang (per bulan/lebih jarang).
     *
     * Cuma kode berstatus 'allocated' yang boleh dimutasi -- 'used' sudah
     * terpasang fisik di kendaraan (tidak ada yang bisa dipindah),
     * 'unallocated' pakai jalur "Alokasi ke Toko" (bulk action yang
     * sudah ada), bukan mutasi.
     *
     * @throws \InvalidArgumentException kalau status bukan 'allocated',
     *         atau tujuan sama dengan toko asal.
     */
    public function transferTo(int $toStoreId, ?string $reason, ?int $userId): ScrollCodeTransfer
    {
        return DB::transaction(function () use ($toStoreId, $reason, $userId) {
            $scrollCode = self::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if ($scrollCode->status !== 'allocated') {
                throw new \InvalidArgumentException('Cuma kode gulungan berstatus "Dialokasi" yang bisa dimutasi -- kode "Belum Dialokasi" pakai Alokasi ke Toko, kode "Terpakai" sudah terpasang fisik.');
            }

            if ((int) $scrollCode->store_id === $toStoreId) {
                throw new \InvalidArgumentException('Toko tujuan sama dengan toko asal.');
            }

            $transfer = ScrollCodeTransfer::create([
                'scroll_code_id' => $scrollCode->id,
                'from_store_id' => $scrollCode->store_id,
                'to_store_id' => $toStoreId,
                'reason' => $reason,
                'performed_by' => $userId,
            ]);

            $scrollCode->update(['store_id' => $toStoreId]);

            $this->setRawAttributes($scrollCode->getAttributes());

            return $transfer;
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'store_id', 'total_length_meters', 'remaining_length_meters', 'max_usage', 'purchase_cost'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('scroll_code')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Kode gulungan \"{$this->code}\" didaftarkan",
                'updated' => "Kode gulungan \"{$this->code}\" diubah",
                'deleted' => "Kode gulungan \"{$this->code}\" dihapus",
                default => "Kode gulungan \"{$this->code}\" — {$eventName}",
            });
    }
}
