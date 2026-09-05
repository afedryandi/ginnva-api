<?php

namespace App\Models;

use App\Models\Concerns\Acknowledgeable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Asset extends Model
{
    use LogsActivity;
    use Acknowledgeable;

    protected $fillable = [
        'asset_tag',
        'name',
        'category',
        'received_date',
        'status',
        'assigned_to',
        'store_id',
        'purchase_date',
        'purchase_cost',
        'useful_life_years',
        'salvage_value',
        'chart_of_account_id',
        'accumulated_depreciation_account_id',
        'next_maintenance_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'received_date' => 'date',
        'purchase_date' => 'date',
        'purchase_cost' => 'decimal:2',
        'salvage_value' => 'decimal:2',
        'next_maintenance_date' => 'date',
        'reviewed_at'   => 'datetime',
    ];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * 2 akun Bagan Akun untuk penyusutan otomatis — lihat
     * DepreciationPostingService & migration add_depreciation_accounts_
     * to_assets_table untuk penjelasan kenapa 2 kolom terpisah (bukan
     * di-derive dari Asset::category yang teks bebas).
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }

    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'accumulated_depreciation_account_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(AssetTransfer::class)->latest();
    }

    /**
     * Satu sumber kebenaran untuk store-scoping — SEBELUMNYA aturan "non-
     * full-access cuma boleh lihat/ubah aset tokonya sendiri" diimplementasi
     * ULANG secara manual di 4 tempat terpisah (AssetResource::getEloquentQuery(),
     * CreateAsset::mutateFormDataBeforeCreate(), AssetController::belongsToUserScope(),
     * AssetController::update()) — berisiko lupa disinkronkan kalau ada
     * entry point baru (mis. fitur transfer massal). Semua sekarang
     * panggil method ini.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isFullAccess()) {
            return $query;
        }

        return $query->where('store_id', $user->store_id);
    }

    public static function isVisibleTo(User $user, self $asset): bool
    {
        return $user->isFullAccess() || $asset->store_id === $user->store_id;
    }

    /**
     * Toko default saat aset baru didaftarkan — full-access boleh pilih
     * bebas (null = belum ditentukan), non-full-access selalu tokonya
     * sendiri (tidak bisa daftarkan aset "lepas" ke toko lain).
     */
    public static function defaultStoreIdFor(User $user): ?int
    {
        return $user->isFullAccess() ? null : $user->store_id;
    }

    /**
     * Nilai buku saat ini — metode garis lurus (straight-line) sederhana:
     * (harga beli - nilai residu) dibagi rata sepanjang umur ekonomis,
     * dikurangkan sesuai tahun yang sudah berjalan sejak tanggal beli,
     * TIDAK PERNAH turun di bawah nilai residu. Null kalau data yang
     * dibutuhkan (harga beli, tanggal beli, umur ekonomis) belum lengkap
     * — supaya tidak menampilkan angka seolah-olah pasti padahal cuma
     * tebakan dari data kosong.
     */
    public function currentBookValue(): ?float
    {
        if ($this->purchase_cost === null || $this->purchase_date === null || ! $this->useful_life_years) {
            return null;
        }

        $cost = (float) $this->purchase_cost;
        $salvage = (float) ($this->salvage_value ?? 0);
        $yearsElapsed = $this->purchase_date->diffInDays(now()) / 365;
        $annualDepreciation = ($cost - $salvage) / $this->useful_life_years;
        $value = $cost - ($annualDepreciation * $yearsElapsed);

        return round(max($salvage, min($cost, $value)), 2);
    }

    /**
     * Kode unik per aset — dikodekan ke QR (sama pola dengan
     * InventoryItem::generateCode()) supaya bisa ditempel & dicari fisik.
     */
    public static function generateAssetTag(): string
    {
        do {
            $tag = 'ASSET-' . strtoupper(Str::random(8));
        } while (self::where('asset_tag', $tag)->exists());

        return $tag;
    }

    /**
     * Riwayat perpindahan/perubahan kondisi cukup lewat activity log —
     * tidak ada tabel movement khusus di sini karena aset tidak
     * "habis"/berkurang seperti inventory_items atau raw_materials,
     * cuma berubah status/pemegang/lokasi.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'category', 'received_date', 'status', 'assigned_to', 'store_id', 'purchase_date', 'purchase_cost', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('asset')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Aset \"{$this->name}\" ({$this->asset_tag}) didaftarkan",
                'updated' => "Aset \"{$this->name}\" ({$this->asset_tag}) diubah",
                'deleted' => "Aset \"{$this->name}\" ({$this->asset_tag}) dihapus",
                default => "Aset \"{$this->name}\" — {$eventName}",
            });
    }
}