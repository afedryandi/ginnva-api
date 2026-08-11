<?php

use App\Models\ScrollCode;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Perbaikan data lama — sebelum fix "roll PPF terkunci setelah 1x
     * pakai" (lihat WarrantyObserver), kode gulungan PPF langsung
     * di-set status='used' TANPA pernah menaikkan usage_count, jadi
     * kode yang sebenarnya sudah pernah dipakai warranty tetap
     * menampilkan "0" di kolom Terpakai (mis. 260518020101001,
     * 260518020101002 — dilaporkan user, tapi bug ini sistemik untuk
     * SEMUA kode PPF yang sempat dipakai sebelum fix).
     *
     * Dihitung dari jumlah warranty ASLI yang benar-benar memakai kode
     * itu (lewat ScrollCode::warranties(), cek 4 kolom roll_number* di
     * tabel warranties) — BUKAN asal di-set ke 1 — supaya kode yang
     * sengaja ditandai "Tandai Habis" manual tanpa pernah benar-benar
     * dipakai (usage_count=0 di situ memang valid) tidak ikut berubah.
     */
    public function up(): void
    {
        ScrollCode::where('status', 'used')
            ->where('usage_count', 0)
            ->chunkById(200, function ($scrollCodes) {
                foreach ($scrollCodes as $scrollCode) {
                    $realUsageCount = $scrollCode->warranties()->count();

                    if ($realUsageCount > 0) {
                        $scrollCode->update(['usage_count' => $realUsageCount]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Tidak ada yang perlu dibalik — ini murni perbaikan data yang
        // salah, bukan perubahan skema. Membalikkannya ke 0 lagi cuma
        // akan mengembalikan bug yang sedang diperbaiki.
    }
};
