<?php

namespace App\Filament\Resources\NotificationDeliveryResource\Pages;

use App\Filament\Resources\NotificationDeliveryResource;
use App\Models\NotificationDelivery;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListNotificationDeliveries extends ListRecords
{
    protected static string $resource = NotificationDeliveryResource::class;

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
                        activity('report_export')->causedBy(auth()->user())->withProperties(['report' => 'notification_delivery', 'format' => 'xlsx'])->log('Export Log Pengiriman (xlsx)');
                    } catch (\Throwable $e) {
                        report($e);
                    }

                    return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\NotificationDeliveryExport(), 'log-pengiriman-' . now()->format('Ymd') . '.xlsx');
                }),
        ];
    }

    /** Ringkasan 24 jam terakhir: angka yang menjawab "apakah push benar-benar sampai". */
    public function getSubheading(): string|Htmlable|null
    {
        $counts = NotificationDelivery::where('sent_at', '>=', now()->subDay())
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $delivered = (int) ($counts[NotificationDelivery::DELIVERED] ?? 0);
        $failed = (int) ($counts[NotificationDelivery::FAILED] ?? 0);
        $dead = (int) ($counts[NotificationDelivery::DEAD_TOKEN] ?? 0);
        $waiting = (int) ($counts[NotificationDelivery::ACCEPTED] ?? 0);

        $judged = $delivered + $failed + $dead;
        $rate = $judged > 0 ? round($delivered / $judged * 100) . '% terkirim' : 'belum ada kuitansi';

        return "24 jam terakhir: {$delivered} terkirim, {$failed} gagal, {$dead} token mati, {$waiting} menunggu kuitansi ({$rate}).";
    }
}
