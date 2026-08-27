<?php

namespace App\Console\Commands;

use App\Filament\Resources\RawMaterialResource;
use App\Models\RawMaterial;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Alert kedaluwarsa bahan baku yang AKTIF — jalan harian (lihat
 * routes/console.php), kirim ke bell notifikasi Filament (bukan cuma
 * badge pasif yang baru kelihatan kalau admin sendiri buka halaman Bahan
 * Baku). Cuma menghitung bahan yang BELUM "Tandai Ditinjau" (lihat
 * App\Models\Concerns\Acknowledgeable) — supaya bahan yang sudah
 * ditinjau tidak terus-menerus memicu notifikasi baru tiap hari selama
 * kondisinya belum berubah.
 */
class NotifyExpiringMaterials extends Command
{
    protected $signature = 'materials:notify-expiring';

    protected $description = 'Kirim notifikasi ke admin kalau ada bahan baku menipis/kedaluwarsa/tidak bergerak yang belum ditinjau';

    public function handle(): int
    {
        $lowStockCount = RawMaterial::query()
            ->whereNotNull('reorder_point')
            ->whereColumn('current_stock', '<=', 'reorder_point')
            ->where(fn ($q) => $q->whereNull('reviewed_at')->orWhereColumn('reviewed_at', '<', 'updated_at'))
            ->count();

        $expiryCount = RawMaterial::query()
            ->whereHas('batches', fn ($q) => $q->where('quantity', '>', 0)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', now()->addDays(30)))
            ->where(fn ($q) => $q->whereNull('reviewed_at')->orWhereColumn('reviewed_at', '<', 'updated_at'))
            ->count();

        $deadStockCount = RawMaterial::query()
            ->where('current_stock', '>', 0)
            ->where('updated_at', '<', now()->subDays(RawMaterial::DEAD_STOCK_DAYS))
            ->where(fn ($q) => $q->whereNull('reviewed_at')->orWhereColumn('reviewed_at', '<', 'updated_at'))
            ->count();

        $total = $lowStockCount + $expiryCount + $deadStockCount;

        if ($total === 0) {
            $this->info('Tidak ada bahan baku yang perlu diberi tahu hari ini.');

            return self::SUCCESS;
        }

        $bodyLines = [];
        if ($lowStockCount > 0) $bodyLines[] = "{$lowStockCount} bahan stoknya menipis";
        if ($expiryCount > 0) $bodyLines[] = "{$expiryCount} bahan mendekati/sudah kedaluwarsa";
        if ($deadStockCount > 0) $bodyLines[] = "{$deadStockCount} bahan tidak bergerak";

        // Full-access selalu dapat, ditambah user lain yang memang punya
        // akses menu Bahan Baku (sama pola akses dengan yang dipakai
        // widget "Perlu Perhatian" Dashboard Inventaris).
        $recipients = User::all()->filter(fn (User $user) => $user->isFullAccess()
            || $user->hasMenuAccess(RawMaterialResource::class));

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title('Bahan Baku Perlu Perhatian')
                ->body(implode(', ', $bodyLines) . ' — belum ditinjau.')
                ->warning()
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('Lihat')
                        ->url(RawMaterialResource::getUrl('index'))
                        ->markAsRead(),
                ])
                ->sendToDatabase($recipient);
        }

        $this->info("Notifikasi terkirim ke {$recipients->count()} admin ({$total} bahan perlu perhatian).");

        return self::SUCCESS;
    }
}
