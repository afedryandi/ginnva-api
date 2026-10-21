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
        return [];
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
