<?php

namespace App\Services;

use App\Models\Vehicle;

/**
 * Katalog kendaraan untuk dropdown merek → model di aplikasi (Fase 1 pengerjaan ulang booking, 2026-10-15), lengkap dengan
 * size_category yang menentukan harga PPF/kaca film. Hanya kendaraan AKTIF yang sudah punya model (entri merek-saja tampil di panel admin
 * sebagai placeholder; kendaraan nonaktif tidak ditawarkan lagi tetapi riwayatnya utuh).
 *
 * Casing merek dinormalkan: data lama campur "Toyota" (seeder awal) dan "TOYOTA" (migrasi perbaikan data). Kolom `brand` ber-collation
 * case-insensitive, jadi DISTINCT menggabungkannya jadi satu merek, TETAPI dropdown bertingkat di web dan mobile memfilter kendaraan per
 * merek dengan perbandingan JS yang case-sensitive. Tanpa normalisasi ini kendaraan dengan casing berbeda diam-diam tidak pernah muncul
 * di dropdown. Normalisasi dilakukan di backend (satu sumber) supaya tidak diulang di dua frontend. Dipakai oleh GET /api/vehicles/catalog
 * dan GET /api/quotation/options. Ditemukan dan diperbaiki 2026-08-29 (audit modul Kendaraan); dipusatkan di sini 2026-10-21.
 */
class VehicleCatalogService
{
    /**
     * @return array{brands: array<int, string>, vehicles: array<int, array<string, mixed>>}
     */
    public function options(): array
    {
        $brands = Vehicle::active()->select('brand')->distinct()->orderBy('brand')->pluck('brand');

        $canonicalCasing = [];
        foreach ($brands as $brand) {
            $canonicalCasing[mb_strtolower($brand)] = $brand;
        }

        $vehicles = Vehicle::active()->select('id', 'brand', 'model', 'variant', 'size_category')
            ->whereNotNull('model')
            ->orderBy('brand')
            ->orderBy('model')
            ->orderBy('variant')
            ->get()
            ->map(fn (Vehicle $v) => [
                'id' => $v->id,
                'brand' => $canonicalCasing[mb_strtolower($v->brand)] ?? $v->brand,
                'model' => $v->model,
                'variant' => $v->variant,
                'size_category' => $v->size_category,
            ])
            ->values()
            ->all();

        return ['brands' => $brands->values()->all(), 'vehicles' => $vehicles];
    }

    /** Merek dengan casing kanonik yang sama seperti options(). */
    public function canonicalBrand(string $brand): string
    {
        $match = Vehicle::select('brand')->distinct()->pluck('brand')->first(fn ($b) => mb_strtolower($b) === mb_strtolower($brand));

        return $match ?? $brand;
    }
}
