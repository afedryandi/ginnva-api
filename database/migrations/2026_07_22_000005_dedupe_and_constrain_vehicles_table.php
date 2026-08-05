<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ditemukan 3 pasang data kendaraan duplikat exact (brand+model+variant
 * sama persis) di production: Honda Civic, Toyota Fortuner, Hyundai
 * IONIQ 5 — semuanya variant NULL. Sebelum tambah constraint, gabungkan
 * dulu: quotation yang terikat ke ID duplikat dialihkan ke ID yang
 * dipertahankan (ID terkecil/pertama dibuat), baru ID duplikatnya dihapus.
 *
 * unique(['brand','model','variant']) TIDAK cukup sendirian untuk kasus
 * variant NULL — MySQL menganggap dua NULL sebagai TIDAK SAMA di unique
 * index, jadi tetap bisa lolos duplikat baru kalau variant kosong.
 * Constraint ini tetap ditambahkan untuk menangkap kasus variant TERISI;
 * kasus variant NULL ditutup lewat validasi custom di VehicleResource
 * (lihat commit terpisah).
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicateGroups = DB::table('vehicles')
            ->select('brand', 'model', 'variant')
            ->groupBy('brand', 'model', 'variant')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $ids = DB::table('vehicles')
                ->where('brand', $group->brand)
                ->where('model', $group->model)
                ->when(
                    $group->variant === null,
                    fn ($q) => $q->whereNull('variant'),
                    fn ($q) => $q->where('variant', $group->variant)
                )
                ->orderBy('id')
                ->pluck('id');

            $keepId = $ids->first();
            $duplicateIds = $ids->slice(1);

            foreach ($duplicateIds as $dupId) {
                DB::table('quotations')->where('vehicle_id', $dupId)->update(['vehicle_id' => $keepId]);
                DB::table('vehicles')->where('id', $dupId)->delete();
            }
        }

        Schema::table('vehicles', function (Blueprint $table) {
            $table->unique(['brand', 'model', 'variant']);
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropUnique(['brand', 'model', 'variant']);
        });

        // Data yang di-merge tidak dikembalikan — penggabungan duplikat
        // tidak reversibel (ID lama sudah hilang), sesuai kesepakatan
        // umum untuk migration pembersihan data.
    }
};
