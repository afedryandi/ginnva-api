<?php

namespace App\Filament\Resources\StockOpnameResource\Pages;

use App\Exports\StockOpnameExport;
use App\Filament\Resources\StockOpnameResource;
use App\Models\StockOpname;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Maatwebsite\Excel\Facades\Excel;

class ViewStockOpname extends ViewRecord
{
    protected static string $resource = StockOpnameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('apply')
                ->label('Terapkan')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn () => (auth()->user()?->isFullAccess() ?? false) && $this->record->isDraft())
                ->requiresConfirmation()
                ->modalDescription('Selisih hasil hitung diterapkan ke stok terkini. Setelah diterapkan, sesi tidak bisa dibatalkan; koreksi dengan menghitung ulang.')
                ->action(function () {
                    try {
                        app(\App\Services\StockOpnameService::class)->apply($this->record, auth()->id());
                    } catch (\RuntimeException $e) {
                        \Filament\Notifications\Notification::make()->title('Tidak bisa menerapkan')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    \Filament\Notifications\Notification::make()->title('Sesi diterapkan, stok disesuaikan.')->success()->send();
                    $this->record->refresh();
                }),

            Actions\Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(new StockOpnameExport($this->record->load('items', 'store')), 'stok-opname-' . $this->record->opname_number . '.xlsx')),

            Actions\Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $opname = $this->record->load('items', 'store');
                    $pdf = Pdf::loadView('pdf.stock_opname', ['opname' => $opname])->setPaper('a4', 'portrait');

                    return response()->streamDownload(fn () => print($pdf->output()), 'stok-opname-' . $opname->opname_number . '.pdf');
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('opname_number')->label('Nomor'),
            TextEntry::make('status')->label('Status')->badge()
                ->color(fn (string $state) => match ($state) {
                    StockOpname::STATUS_DRAFT => 'warning',
                    StockOpname::STATUS_APPLIED => 'success',
                    default => 'gray',
                })
                ->formatStateUsing(fn (string $state) => StockOpname::STATUS_LABELS[$state] ?? $state),
            TextEntry::make('store.name')->label('Toko'),
            TextEntry::make('opname_date')->label('Tanggal Opname')->date('d M Y'),
            TextEntry::make('summary')->label('Ringkasan')
                ->state(function (StockOpname $record) {
                    $total = $record->items()->count();
                    $diff = $record->items()->where('delta', '!=', 0)->count();

                    return "{$total} item dihitung, {$diff} berselisih";
                }),
            TextEntry::make('creator.name')->label('Dicatat Oleh')->placeholder('—'),
            TextEntry::make('snapshot_at')->label('Stok sistem diambil')->dateTime('d M Y, H:i')->placeholder('—'),
            TextEntry::make('applied_at')->label('Diterapkan')->dateTime('d M Y, H:i')
                ->placeholder('Belum')
                ->visible(fn (StockOpname $record) => $record->status !== StockOpname::STATUS_CANCELLED),
            TextEntry::make('applier.name')->label('Diterapkan Oleh')->placeholder('—')
                ->visible(fn (StockOpname $record) => $record->status === StockOpname::STATUS_APPLIED),
            TextEntry::make('cancelled_at')->label('Dibuang')->dateTime('d M Y, H:i')
                ->visible(fn (StockOpname $record) => $record->status === StockOpname::STATUS_CANCELLED),
            TextEntry::make('cancel_reason')->label('Alasan Dibuang')->placeholder('—')->columnSpanFull()
                ->visible(fn (StockOpname $record) => $record->status === StockOpname::STATUS_CANCELLED),
            TextEntry::make('notes')->label('Catatan')->placeholder('—')->columnSpanFull(),
        ])->columns(2);
    }
}