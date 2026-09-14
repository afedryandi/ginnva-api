<?php

namespace App\Filament\Resources\RewardRedemptionResource\Pages;

use App\Exports\RewardRedemptionExport;
use App\Filament\Resources\RewardRedemptionResource;
use App\Models\RewardRedemption;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListRewardRedemptions extends ListRecords
{
    protected static string $resource = RewardRedemptionResource::class;

    /**
     * "Ekspor Klaim Reward" (audit 2026-09-14, temuan pola standar) —
     * tidak ada CreateAction (redemption cuma dibuat lewat mobile app).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new RewardRedemptionExport,
                    'klaim-reward-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Actions\Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $redemptions = RewardRedemption::query()->with('reward')->orderByDesc('created_at')->get();
                    $pdf = Pdf::loadView('pdf.reward_redemptions', ['redemptions' => $redemptions])->setPaper('a4', 'landscape');
                    $filename = 'klaim-reward-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }
}
