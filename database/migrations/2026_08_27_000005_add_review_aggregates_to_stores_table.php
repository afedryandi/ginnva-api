<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agregat StoreReview di-cache di sini (bukan dihitung ulang tiap request)
 * — SEBELUMNYA data sentimen+tag+komentar yang customer isi berakhir jadi
 * "silo data mati": tidak pernah dijumlahkan/ditampilkan di mana pun,
 * padahal ini bukti sosial yang seharusnya membantu customer memilih toko.
 * Kolom ini SENGAJA tidak ditambahkan ke $fillable Store — cuma
 * diperbarui lewat StoreReviewObserver, bukan bisa diedit manual staff
 * lewat form (supaya angkanya selalu konsisten dengan baris store_reviews
 * yang sesungguhnya). Lihat audit modul Review Toko 2026-08-27.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->unsignedInteger('reviews_count')->default(0)->after('is_active');
            $table->unsignedInteger('positive_reviews_count')->default(0)->after('reviews_count');
        });

        // Backfill dari data yang sudah ada — supaya toko yang sudah
        // punya review sebelum migration ini tidak mulai dari 0 palsu.
        $counts = \Illuminate\Support\Facades\DB::table('store_reviews')
            ->selectRaw('store_id, COUNT(*) as total, SUM(CASE WHEN sentiment = ? THEN 1 ELSE 0 END) as positive', ['positive'])
            ->groupBy('store_id')
            ->get();

        foreach ($counts as $row) {
            \Illuminate\Support\Facades\DB::table('stores')
                ->where('id', $row->store_id)
                ->update([
                    'reviews_count'           => $row->total,
                    'positive_reviews_count'  => $row->positive,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['reviews_count', 'positive_reviews_count']);
        });
    }
};
