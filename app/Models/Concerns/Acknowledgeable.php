<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dipakai model yang muncul di widget "Perlu Perhatian" Dashboard Inventaris (RawMaterial, ConsumableItem, Asset). Tombol "Tandai
 * Ditinjau" hanya menyembunyikan baris SEMENTARA, bukan mengubah data barangnya. Baris muncul lagi bila:
 * - datanya berubah setelah ditinjau (stok masuk/keluar, status berubah, apa pun yang menyentuh updated_at);
 * - masa berlaku tinjauan habis (config inventory.review_valid_days, bawaan 7 hari), karena kedaluwarsa dan "tidak bergerak" berubah
 *   karena waktu, bukan karena data, dan tanpa batas waktu baris yang memburuk bisa tersembunyi selamanya;
 * - kondisinya memburuk karena waktu (bahan baku yang batchnya kedaluwarsa SESUDAH ditinjau; lihat RawMaterial).
 * Tinjauan bisa dibatalkan, dan baris yang sedang disembunyikan bisa dilihat di tabel lewat filter "Sudah ditinjau".
 */
trait Acknowledgeable
{
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public static function reviewValidDays(): int
    {
        return max(1, (int) config('inventory.review_valid_days', 7));
    }

    /** Kondisi tambahan yang membuka kembali baris yang ditinjau; model boleh menimpa (ditambahkan sebagai OR). */
    protected static function reopenedReviewConditions($query): void
    {
    }

    /** Padanan reopenedReviewConditions() untuk satu record, dipakai saat menentukan tombol yang tampil. */
    protected function reviewReopened(): bool
    {
        return false;
    }

    /** Baris yang masih perlu ditinjau: belum pernah, datanya berubah sesudahnya, masa berlakunya habis, atau kondisinya memburuk. */
    public function scopeNeedsReview(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('reviewed_at')
                ->orWhereColumn('reviewed_at', '<', 'updated_at')
                ->orWhere('reviewed_at', '<', now()->subDays(static::reviewValidDays()));

            static::reopenedReviewConditions($q);
        });
    }

    /** Baris yang sedang disembunyikan karena tinjauan yang masih berlaku. */
    public function scopeReviewed(Builder $query): Builder
    {
        return $query->whereNotNull('reviewed_at')->whereNot(fn ($q) => $q->needsReview());
    }

    public function isAcknowledged(): bool
    {
        return $this->reviewed_at !== null
            && $this->updated_at !== null
            && $this->reviewed_at->gte($this->updated_at)
            && $this->reviewed_at->gte(now()->subDays(static::reviewValidDays()))
            && ! $this->reviewReopened();
    }

    public function acknowledge(int $userId): void
    {
        // $timestamps = false SENGAJA supaya updated_at TIDAK ikut maju ke
        // waktu tinjau ini — reviewed_at harus tetap bisa dibandingkan ke
        // waktu PERUBAHAN DATA terakhir (isAcknowledged() di atas), bukan
        // waktu tinjau itu sendiri, kalau tidak baris akan langsung
        // "un-acknowledge" diri sendiri begitu disimpan.
        $this->timestamps = false;
        // forceFill(), bukan update() — reviewed_at/reviewed_by sengaja
        // TIDAK ditambahkan ke $fillable masing-masing model (bukan field
        // yang boleh diisi dari form Filament/API biasa, cuma lewat aksi
        // "Tandai Ditinjau" ini).
        $this->forceFill([
            'reviewed_at' => now(),
            'reviewed_by' => $userId,
        ])->save();
        $this->timestamps = true;
    }

    /** Membatalkan tinjauan: baris kembali muncul di "Perlu Perhatian". */
    public function unacknowledge(): void
    {
        $this->timestamps = false;
        $this->forceFill(['reviewed_at' => null, 'reviewed_by' => null])->save();
        $this->timestamps = true;
    }
}
