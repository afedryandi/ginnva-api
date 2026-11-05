<?php

namespace App\Filament\Resources\NotificationCampaignResource\Pages;

use App\Filament\Resources\NotificationCampaignResource;
use Filament\Resources\Pages\ListRecords;

class ListNotificationCampaigns extends ListRecords
{
    protected static string $resource = NotificationCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('export')
                ->label('Export Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                ->action(function () {
                    try {
                        activity('report_export')->causedBy(auth()->user())->withProperties(['report' => 'notification_campaign', 'format' => 'xlsx'])->log('Export Kampanye Notifikasi (xlsx)');
                    } catch (\Throwable $e) {
                        report($e);
                    }

                    return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\NotificationCampaignExport(), 'kampanye-notifikasi-' . now()->format('Ymd') . '.xlsx');
                }),
        ];
    }
}
