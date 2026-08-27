<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dipakai model yang muncul di widget "Perlu Perhatian" Dashboard
 * Inventaris (RawMaterial, ConsumableItem, Asset) — tombol "Tandai
 * Ditinjau" di widget cuma menyembunyikan baris SEMENTARA, bukan
 * menghapus/mengubah data barangnya. Begitu record ini diubah lagi
 * setelah ditinjau (stok masuk/keluar, status berubah, dsb — apa pun
 * yang menyentuh updated_at), otomatis muncul lagi di widget karena
 * isAcknowledged() jadi false lagi — supaya "ditinjau" tidak dipakai
 * untuk menyembunyikan masalah BARU yang kebetulan sama jenisnya.
 */
trait Acknowledgeable
{
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isAcknowledged(): bool
    {
        return $this->reviewed_at !== null
            && $this->updated_at !== null
            && $this->reviewed_at->gte($this->updated_at);
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
}