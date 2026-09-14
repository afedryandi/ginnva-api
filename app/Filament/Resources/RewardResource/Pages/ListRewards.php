<?php

namespace App\Filament\Resources\RewardResource\Pages;

use App\Exports\RewardExport;
use App\Filament\Resources\RewardResource;
use App\Models\Reward;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListRewards extends ListRecords
{
    protected static string $resource = RewardResource::class;

    /**
     * "Ekspor Reward" (audit 2026-09-14, temuan pola standar).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new RewardExport,
                    'katalog-reward-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Actions\Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $rewards = Reward::query()->orderBy('points_cost')->get();
                    $pdf = Pdf::loadView('pdf.rewards', ['rewards' => $rewards])->setPaper('a4', 'portrait');
                    $filename = 'katalog-reward-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),

            Actions\CreateAction::make(),
        ];
    }
}
