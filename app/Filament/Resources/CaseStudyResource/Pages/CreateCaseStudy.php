<?php

namespace App\Filament\Resources\CaseStudyResource\Pages;

use App\Filament\Resources\CaseStudyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCaseStudy extends CreateRecord
{
    protected static string $resource = CaseStudyResource::class;

    /**
     * Item baru otomatis ditaruh di urutan paling akhir (sort_order
     * tertinggi + 1), supaya tidak menyelip ke tengah urutan yang sudah
     * diatur admin sebelumnya lewat drag-reorder.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['sort_order'] = (\App\Models\CaseStudy::max('sort_order') ?? 0) + 1;

        return $data;
    }
}