<?php

namespace App\Filament\Widgets;

use App\Support\BackupStatus;
use Filament\Widgets\Widget;

/**
 * Kartu "Cadangan Database" di Dashboard Utama, khusus akses penuh: kapan cadangan terakhir dibuat, ukurannya,
 * dan apakah salinan off-site sudah diatur. Ide dari audit ACCURATE 5 (menu Berkas, Cadangan Data):
 * pemilik bisa melihat sendiri bahwa cadangan jalan tanpa harus membuka server.
 */
class BackupStatusWidget extends Widget
{
    protected static string $view = 'filament.widgets.backup-status';

    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 90;

    public static function canView(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public function getStatus(): array
    {
        return BackupStatus::latest();
    }
}
