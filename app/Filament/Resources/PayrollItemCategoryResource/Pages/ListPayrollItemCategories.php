<?php

namespace App\Filament\Resources\PayrollItemCategoryResource\Pages;

use App\Filament\Resources\PayrollItemCategoryResource;
use App\Models\PayrollItemCategory;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPayrollItemCategories extends ListRecords
{
    protected static string $resource = PayrollItemCategoryResource::class;

    public function mount(): void
    {
        // Kategori bawaan dan akun barunya dibuat bila belum ada (aman dipanggil berulang).
        PayrollItemCategory::ensureDefaults();

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
