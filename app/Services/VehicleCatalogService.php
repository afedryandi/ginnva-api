<?php

namespace App\Services;

use App\Models\Vehicle;

/**
 * Katalog kendaraan untuk dropdown merek → model di aplikasi (Fase 1 pengerjaan ulang booking, 2026-10-15), lengkap dengan
 * size_category yang menentukan harga PPF/kaca film. Hanya kendaraan yang sudah punya model. Casing merek dinormalkan
 * (data lama campur "Toyota"/"TOYOTA") — alasan lengkapnya di QuotationController::options().
 */
class VehicleCatalogService
{
    /**
     * @return array{brands: array<int, string>, vehicles: array<int, array<string, mixed>>}
     */
    public function options(): array
    {
        $brands = Vehicle::select('brand')->distinct()->orderBy('brand')->pluck('brand');

        $canonicalCasing = [];
        foreach ($brands as $brand) {
            $canonicalCasing[mb_strtolower($brand)] = $brand;
        }

        $vehicles = Vehicle::select('id', 'brand', 'model', 'variant', 'size_category')
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
