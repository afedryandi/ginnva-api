<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MaterialMemo extends Model
{
    use LogsActivity;

    // Global Scope store-id (audit framework 2026-09-14, "Isolasi data
    // multi-tenant") — lihat App\Models\Scopes\StoreScope. Pola manual
    // yang sudah ada di MaterialMemoResource::getEloquentQuery() SENGAJA
    // DIBIARKAN (bukan dihapus) sebagai defense-in-depth; filter dari
    // scope ini no-op/redundan di sana, tapi jadi satu-satunya proteksi
    // untuk query LANGSUNG ke MaterialMemo:: di tempat lain (widget/
    // report/service) yang sebelumnya rawan lupa di-scope.
    use HasStoreScope;

    protected $fillable = [
        'memo_number',
        'store_id',
        // Booking terkait -- OPSIONAL (diminta 2026-09-09, boleh diisi
        // kapan saja, tidak wajib saat memo dibuat), UNIQUE per booking
        // (selalu 1 booking = 1 memo; kalau ada tambahan barang di
        // tengah pekerjaan, memo yang SAMA diedit -- bukan bikin memo
        // baru). Dipakai supaya Detail Penjualan/View Booking bisa
        // menampilkan inventori yang terpakai. Lihat migrasi
        // 2026_09_09_000001.
        'booking_id',
        'vehicle_info',
        'spk_number',
        'notes',
        'created_by',
        'unlocked_at',
        'unlocked_by',
        'unlock_reason',
    ];

    protected $casts = [
        'unlocked_at' => 'datetime',
    ];

    /**
     * Format: MEMO-YYYYMMDD-XXXX (urut per hari). Dipanggil di dalam
     * transaction oleh controller supaya tidak race-condition dobel nomor.
     */
    public static function generateMemoNumber(): string
    {
        $datePart = now()->format('Ymd');
        $prefix = "MEMO-{$datePart}-";

        // Ambil urutan TERBESAR hari ini, bukan jumlah baris: kalau memo hari ini pernah dihapus, jumlah baris mengecil
        // dan nomor berikutnya menabrak memo yang masih ada (retry pun menghasilkan nomor yang sama terus).
        $last = static::withoutGlobalScopes()
            ->where('memo_number', 'like', $prefix . '%')
            ->pluck('memo_number')
            ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
            ->max() ?? 0;

        return sprintf('MEMO-%s-%04d', $datePart, $last + 1);
    }

    /**
     * Terkunci kalau booking-nya sudah Selesai (pemakaian bahan sudah dibukukan sebagai HPP, jadi mengubah memo membuat stok dan pembukuan
     * tidak cocok), kecuali akses penuh sudah membukanya (unlocked_at terisi). Booking Dibatalkan tidak mengunci: sisa bahan masih harus
     * bisa dikembalikan. Memo tanpa booking tidak pernah terkunci.
     */
    public function isLocked(): bool
    {
        if (! $this->booking_id || $this->unlocked_at !== null) {
            return false;
        }

        // Relasi booking sering dimuat dengan kolom terbatas tanpa status (mis. di API), jadi status dibaca dari relasi hanya bila kolomnya ada.
        $loaded = $this->relationLoaded('booking') ? $this->booking : null;
        $status = ($loaded && array_key_exists('status', $loaded->getAttributes()))
            ? $loaded->status
            : Booking::withoutGlobalScopes()->whereKey($this->booking_id)->value('status');

        return $status === 'completed';
    }

    /** Booking-nya Selesai tetapi kuncinya sudah dibuka akses penuh (bisa dikunci lagi). */
    public function isUnlockedAfterCompletion(): bool
    {
        if (! $this->booking_id || $this->unlocked_at === null) {
            return false;
        }

        return Booking::withoutGlobalScopes()->whereKey($this->booking_id)->value('status') === 'completed';
    }

    /** Memo yang masih punya baris bahan/barang habis pakai tanpa catatan pengembalian (stok sudah keluar, belum diselesaikan). */
    public function scopeHasUnreturned($query)
    {
        return $query->whereHas('items', fn ($q) => $q->whereIn('item_type', ['raw_material', 'consumable_item'])->whereNull('qty_returned'));
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialMemoItem::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['memo_number', 'store_id', 'booking_id', 'vehicle_info', 'spk_number', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
