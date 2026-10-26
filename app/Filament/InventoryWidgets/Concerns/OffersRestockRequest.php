<?php

namespace App\Filament\InventoryWidgets\Concerns;

use App\Filament\Resources\PurchaseRequestResource;
use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use App\Models\Store;
use App\Services\RestockRequestService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;

/**
 * Tombol "Buat Permintaan Beli" untuk tabel Perlu Perhatian (bahan baku dan barang habis pakai): hanya untuk barang yang stoknya menipis,
 * belum punya permintaan beli berjalan, dan hanya bagi yang boleh membuat Permohonan Pembelian.
 */
trait OffersRestockRequest
{
    protected static function restockAction(): Tables\Actions\Action
    {
        $service = fn () => app(RestockRequestService::class);

        return Tables\Actions\Action::make('requestPurchase')
            ->label('Buat Permintaan Beli')
            ->icon('heroicon-o-shopping-cart')
            ->color('primary')
            ->visible(fn (RawMaterial|ConsumableItem $record) => $record->isLowStock()
                && PurchaseRequestResource::canCreate()
                && $service()->openRequest($record) === null)
            ->modalHeading(fn (RawMaterial|ConsumableItem $record) => "Permintaan beli: {$record->name}")
            ->modalDescription(fn (RawMaterial|ConsumableItem $record) => $record->preferredSupplier
                ? "Pemasok utama: {$record->preferredSupplier->name}. Permintaan ini menunggu persetujuan seperti Permohonan Pembelian lainnya."
                : 'Belum ada pemasok utama untuk barang ini (bisa diisi di data barangnya). Permintaan ini menunggu persetujuan seperti Permohonan Pembelian lainnya.')
            ->form(function (RawMaterial|ConsumableItem $record) use ($service) {
                $user = auth()->user();
                $isFullAccess = $user?->isFullAccess() ?? false;

                return [
                    Forms\Components\Select::make('store_id')
                        ->label('Toko / Workshop')
                        ->options(fn () => Store::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->default($isFullAccess ? null : $user?->store_id)
                        ->disabled(! $isFullAccess)
                        ->dehydrated(),

                    Forms\Components\TextInput::make('quantity')
                        ->label('Jumlah')
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        ->default($service()->suggestedQuantity($record))
                        ->suffix($record->unit)
                        ->helperText('Terisi selisih antara ambang dan stok saat ini. Ubah sesuai kebutuhan.'),

                    Forms\Components\Textarea::make('note')
                        ->label('Catatan (opsional)')
                        ->rows(2),
                ];
            })
            ->action(function (RawMaterial|ConsumableItem $record, array $data) use ($service) {
                try {
                    $request = $service()->create($record, (int) $data['store_id'], (float) $data['quantity'], $data['note'] ?? null, auth()->user());
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Permintaan beli tidak dibuat')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title("Permintaan beli {$request->request_number} dibuat")
                    ->body('Menunggu persetujuan di menu Permohonan Pembelian.')
                    ->success()
                    ->send();
            });
    }

    /** Kolom nomor permintaan beli yang sedang berjalan, supaya jelas barang mana yang sudah diajukan. */
    protected static function openRequestColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('open_purchase_request')
            ->label('Permintaan Beli')
            ->state(fn (RawMaterial|ConsumableItem $record) => app(RestockRequestService::class)->openRequest($record)?->request_number ?? '—')
            ->badge()
            ->color(fn (string $state) => $state === '—' ? 'gray' : 'info');
    }
}
