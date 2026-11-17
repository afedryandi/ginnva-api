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
        'flow',
        'request_status',
        'request_note',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'reject_reason',
        'fulfilled_by',
        'fulfilled_at',
        // Booking terkait -- OPSIONAL (diminta 2026-09-09, boleh diisi
        // kapan saja, tidak wajib saat memo dibuat), UNIQUE per booking
        // (selalu 1 booking = 1 memo; kalau ada tambahan barang di
        // tengah pekerjaan, memo yang SAMA diedit -- bukan bikin memo
        // baru). Dipakai supaya Detail Penjualan/View Booking bisa
        // menampilkan inventori yang terpakai. Lihat migrasi
        // 2026_09_09_000001.
        'booking_id',
        'vehicle_info',
        'vehicle_brand',
        'vehicle_model',
        'vehicle_plate',
        'spk_number',
        'notes',
        'created_by',
        'unlocked_at',
        'unlocked_by',
        'unlock_reason',
    ];

    protected $casts = [
        'unlocked_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'fulfilled_at' => 'datetime',
    ];

    public const FLOW_DIRECT = 'direct';

    public const FLOW_REQUEST = 'request';

    public const REQ_PENDING = 'pending';

    public const REQ_APPROVED = 'approved';

    public const REQ_REJECTED = 'rejected';

    public const REQ_FULFILLED = 'fulfilled';

    public const REQUEST_STATUS_LABELS = [
        'pending' => 'Menunggu Persetujuan',
        'approved' => 'Disetujui, silakan hubungi staf gudang',
        'rejected' => 'Ditolak',
        'fulfilled' => 'Sudah diserahkan',
    ];

    protected static function booted(): void
    {
        // Teks gabungan kendaraan selalu mengikuti isian merek/tipe/plat (panel, API, dan prefill booking sama-sama lewat sini).
        static::saving(function (self $memo) {
            if ($memo->isDirty(['vehicle_brand', 'vehicle_model', 'vehicle_plate'])) {
                $memo->vehicle_info = self::composeVehicleInfo($memo->vehicle_brand, $memo->vehicle_model, $memo->vehicle_plate) ?? $memo->vehicle_info;
            }
        });
    }

    /** Plat dibakukan: huruf besar, spasi tunggal ("b 1234 xyz" menjadi "B 1234 XYZ"). */
    public static function normalizePlate(?string $plate): ?string
    {
        $plate = trim(preg_replace('/\s+/', ' ', (string) $plate));

        return $plate === '' ? null : mb_strtoupper($plate);
    }

    /** Teks gabungan untuk tampilan dan pencarian, sama bentuknya dengan isian otomatis dari booking: "Toyota Avanza - B 1234 XYZ". */
    public static function composeVehicleInfo(?string $brand, ?string $model, ?string $plate): ?string
    {
        $vehicle = trim(implode(' ', array_filter([trim((string) $brand), trim((string) $model)])));
        $info = trim($vehicle . ($plate ? ($vehicle !== '' ? ' - ' : '') . $plate : ''));

        return $info !== '' ? $info : null;
    }

    public function isRequestFlow(): bool
    {
        return $this->flow === self::FLOW_REQUEST;
    }

    /** Selesai diserahkan: barang dan jumlahnya terkunci, bahkan untuk akun berizin Ubah. Hanya akses penuh yang bisa membuka (unlocked_at). */
    public function isHandoverLocked(): bool
    {
        return $this->isRequestFlow() && $this->request_status === self::REQ_FULFILLED && $this->unlocked_at === null;
    }

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
    public function isBookingLocked(): bool
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

    /** Terkunci karena booking-nya Selesai, atau karena barangnya sudah selesai diserahkan (memo pengajuan). */
    public function isLocked(): bool
    {
        return $this->isBookingLocked() || $this->isHandoverLocked();
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
            ->logOnly(['memo_number', 'store_id', 'booking_id', 'vehicle_info', 'vehicle_brand', 'vehicle_model', 'vehicle_plate', 'spk_number', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
