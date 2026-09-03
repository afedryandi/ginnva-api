<?php

namespace App\Filament\Resources\MaterialResource\Pages;

use App\Filament\Resources\MaterialResource;
use App\Models\Material;
use Filament\Resources\Pages\CreateRecord;

class CreateMaterial extends CreateRecord
{
    protected static string $resource = MaterialResource::class;

    /**
     * Materi baru otomatis ditaruh di urutan paling akhir DALAM
     * kategorinya sendiri (bukan global) — sort_order sebelumnya SELALU
     * 0 (default kolom) karena tidak ada apa pun yang mengisinya, jadi
     * urutan tampil di /api/materials jadi tidak terkendali admin sama
     * sekali walau kolomnya aktif dipakai query. Sama pola dengan
     * CreateCaseStudy.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['sort_order'] = (Material::where('material_category_id', $data['material_category_id'])->max('sort_order') ?? 0) + 1;

        return $data;
    }
}
