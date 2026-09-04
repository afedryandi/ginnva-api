<?php

namespace App\Filament\Resources\FinanceTransactionResource\Pages;

use App\Filament\Resources\FinanceTransactionResource;
use App\Models\FinanceCategory;
use Filament\Resources\Pages\CreateRecord;

class CreateFinanceTransaction extends CreateRecord
{
    protected static string $resource = FinanceTransactionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        // Field "Toko" dikunci+tidak ikut submit untuk non-full-access
        // (lihat FinanceTransactionResource::form()) — dipaksa ke toko
        // staff itu sendiri di sini, sama pola dengan CreateAsset.
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $data['store_id'] = auth()->user()?->store_id;
        }

        // 'type' DISALIN dari kategori yang dipilih (bukan sekadar
        // percaya nilai Radio di form) — jaring pengaman kalau ada
        // ketidaksinkronan state form (mis. race saat ganti tipe cepat),
        // supaya transaksi tidak pernah tersimpan dengan type yang beda
        // dari kategori aslinya.
        $category = FinanceCategory::find($data['finance_category_id']);
        if ($category) {
            $data['type'] = $category->type;
        }

        return $data;
    }
}
