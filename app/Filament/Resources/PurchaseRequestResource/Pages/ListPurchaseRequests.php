<?php

namespace App\Filament\Resources\PurchaseRequestResource\Pages;

use App\Exports\PurchaseRequestExport;
use App\Filament\Resources\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

class ListPurchaseRequests extends ListRecords
{
    protected static string $resource = PurchaseRequestResource::class;

    /**
     * "Ekspor Permohonan Pembelian" (audit 2026-09-12, temuan pola
     * standar) — scope store sama dengan getEloquentQuery() resource
     * ini.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->tooltip('Mengekspor daftar sesuai filter dan pencarian yang sedang aktif')
                ->action(fn ($livewire) => Excel::download(
                    new PurchaseRequestExport(\App\Filament\Resources\PurchaseRequestResource::canSeeCost(), $livewire->getFilteredSortedTableQuery()),
                    'permohonan-pembelian-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Actions\Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->tooltip('Mengekspor daftar sesuai filter dan pencarian yang sedang aktif')
                ->action(function ($livewire) {
                    // Query tabel sudah dibatasi ke toko akun ini (PurchaseRequestResource::getEloquentQuery()) dan mengikuti filter/urutan layar.
                    $requests = $livewire->getFilteredSortedTableQuery()->with(['store', 'requester'])->get();

                    $pdf = Pdf::loadView('pdf.purchase_requests', ['requests' => $requests, 'showCost' => \App\Filament\Resources\PurchaseRequestResource::canSeeCost()])->setPaper('a4', 'landscape');
                    $filename = 'permohonan-pembelian-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),

            // Satu nomor induk (PP-...) berisi beberapa barang; tiap barang tetap satu baris yang diputuskan dan diterima sendiri.
            Actions\Action::make('multi')
                ->label('Ajukan Beberapa Barang')
                ->icon('heroicon-o-queue-list')
                ->color('primary')
                ->visible(fn () => PurchaseRequestResource::canCreate())
                ->modalHeading('Ajukan Beberapa Barang')
                ->modalDescription('Semua barang diajukan dalam satu nomor permohonan. Atasan bisa menyetujui per barang atau sekaligus.')
                ->modalWidth('4xl')
                ->form(function () {
                    $isFull = auth()->user()?->isFullAccess() ?? false;

                    return [
                        \Filament\Forms\Components\Select::make('store_id')
                            ->label('Toko / Workshop')
                            ->options(fn () => \App\Models\Store::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->default($isFull ? null : auth()->user()?->store_id)
                            ->disabled(! $isFull)
                            ->dehydrated(),

                        \Filament\Forms\Components\Repeater::make('items')
                            ->label('Barang')
                            ->minItems(1)
                            ->maxItems(\App\Services\PurchaseRequestGroupService::MAX_LINES)
                            ->defaultItems(2)
                            ->addActionLabel('Tambah Barang')
                            ->columns(4)
                            ->schema([
                                \Filament\Forms\Components\Select::make('item_type')
                                    ->label('Jenis')
                                    ->options(\App\Support\HiddenInventoryTypes::filter([
                                        'raw_material' => 'Bahan Baku',
                                        'consumable_item' => 'Barang Habis Pakai',
                                        'asset' => 'Aset Baru',
                                        'other' => 'Lainnya',
                                    ]))
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn (\Filament\Forms\Set $set) => $set('item_id', null)),

                                \Filament\Forms\Components\Select::make('item_id')
                                    ->label('Barang')
                                    ->searchable()
                                    ->options(fn (\Filament\Forms\Get $get) => match ($get('item_type')) {
                                        'raw_material' => \App\Models\RawMaterial::active()->orderBy('name')->pluck('name', 'id'),
                                        'consumable_item' => \App\Models\ConsumableItem::active()->orderBy('name')->pluck('name', 'id'),
                                        default => [],
                                    })
                                    ->visible(fn (\Filament\Forms\Get $get) => in_array($get('item_type'), ['raw_material', 'consumable_item'], true))
                                    ->required(fn (\Filament\Forms\Get $get) => in_array($get('item_type'), ['raw_material', 'consumable_item'], true)),

                                \Filament\Forms\Components\TextInput::make('item_name')
                                    ->label('Nama Barang')
                                    ->maxLength(255)
                                    ->visible(fn (\Filament\Forms\Get $get) => in_array($get('item_type'), ['asset', 'other'], true))
                                    ->required(fn (\Filament\Forms\Get $get) => in_array($get('item_type'), ['asset', 'other'], true)),

                                \Filament\Forms\Components\TextInput::make('unit')
                                    ->label('Satuan')
                                    ->maxLength(30)
                                    ->visible(fn (\Filament\Forms\Get $get) => $get('item_type') === 'other'),

                                \Filament\Forms\Components\TextInput::make('quantity')
                                    ->label('Jumlah')
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->required()
                                    ->default(1),
                            ]),

                        \Filament\Forms\Components\Textarea::make('reason')
                            ->label('Alasan / Catatan')
                            ->rows(2),
                    ];
                })
                ->action(function (array $data) {
                    try {
                        $group = app(\App\Services\PurchaseRequestGroupService::class)->submit(
                            auth()->user(),
                            isset($data['store_id']) ? (int) $data['store_id'] : null,
                            $data['reason'] ?? null,
                            $data['items'] ?? [],
                        );
                    } catch (\InvalidArgumentException $e) {
                        \Filament\Notifications\Notification::make()->title('Tidak bisa mengajukan')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    app(\App\Services\PurchaseRequestNotifier::class)->notifyNewGroup($group);

                    \Filament\Notifications\Notification::make()
                        ->title("Permohonan {$group->group_number} diajukan")
                        ->body($group->lines->count() . ' barang menunggu persetujuan.')
                        ->success()->send();
                }),

            Actions\CreateAction::make(),
        ];
    }

    /**
     * Status sebagai tab di atas daftar, dengan jumlah di tab (mengikuti pembatasan toko pengguna). "Semua" tetap bawaan supaya tidak ada
     * permohonan yang tersembunyi; "Menunggu" berjumlah merah-amber karena itu yang perlu diputuskan.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $base = fn () => PurchaseRequestResource::getEloquentQuery()->withoutEagerLoads();

        return [
            'semua' => Tab::make('Semua'),
            'menunggu' => Tab::make('Menunggu')
                ->badge($base()->where('status', 'pending')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),
            'disetujui' => Tab::make('Disetujui')
                ->badge($base()->where('status', 'approved')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved')),
            'selesai' => Tab::make('Terpenuhi')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'fulfilled')),
            'ditolak' => Tab::make('Ditolak / Dibatalkan')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['rejected', 'cancelled'])),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'semua';
    }
}
