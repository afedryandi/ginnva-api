<?php

namespace App\Filament\Resources\MaterialMemoResource\Pages;

use App\Filament\Resources\MaterialMemoResource;
use App\Models\MaterialMemo;
use App\Services\MaterialMemoStockService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditMaterialMemo extends EditRecord
{
    protected static string $resource = MaterialMemoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // DeleteAction bawaan Filament langsung cascade-delete baris
            // material_memo_items dari DB (lihat foreignId di migration)
            // TANPA membalik stok/sisa meter yang sudah terpakai sama
            // sekali. Diganti Action custom yang panggil
            // MaterialMemoStockService::reverseAllItems() dulu — sama
            // logika yang dipakai API DELETE /memos/{id} dari mobile.
            Actions\Action::make('delete')
                ->label('Hapus')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Hapus Memo Ini?')
                ->modalDescription('Semua barang di memo ini akan dibalik dulu (stok/sisa meter yang sudah terpakai dikembalikan), baru memonya dihapus permanen.')
                ->action(function () {
                    /** @var MaterialMemo $memo */
                    $memo = $this->record;
                    $memo->load('items');

                    DB::transaction(function () use ($memo) {
                        MaterialMemoStockService::reverseAllItems($memo, auth()->id());
                        $memo->delete();
                    });

                    $this->redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}