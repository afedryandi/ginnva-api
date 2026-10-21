<?php

namespace App\Filament\Pages;

use App\Support\NotificationCatalog;
use Filament\Pages\Page;

/**
 * "Katalog Notifikasi" (Notifikasi Fase 0, 2026-10-21): daftar baca-saja semua tempat di kode yang mengirim push ke pelanggan atau staf,
 * beserta penerimanya dan apakah staf juga mendapat notifikasi di lonceng panel. Sumbernya App\Support\NotificationCatalog (dijaga tes).
 */
class NotificationCatalogPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $cluster = \App\Filament\Clusters\NotifikasiCluster::class;

    protected static ?string $navigationLabel = 'Katalog Notifikasi';

    protected static ?string $title = 'Katalog Notifikasi';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.pages.notification-catalog';

    public string $search = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || (($user?->canAccessStaffArea() ?? false) && $user->hasMenuAccess(static::class));
    }

    public const AUDIENCE_LABELS = [
        'pelanggan' => 'Pelanggan',
        'staf_toko' => 'Staf toko (dan super admin)',
        'pengguna' => 'Pengguna tertentu (mis. penerima persetujuan, karyawan yang bersangkutan)',
        'pemantau' => 'Direksi pemantau booking',
    ];

    /** @return array<string, list<array<string, mixed>>> entri tersaring pencarian, dikelompokkan per penerima */
    public function getGroups(): array
    {
        $needle = mb_strtolower(trim($this->search));

        return collect(NotificationCatalog::all())
            ->filter(fn (array $e) => $needle === '' || str_contains(mb_strtolower($e['title'] . ' ' . $e['source']), $needle))
            ->groupBy('audience')
            ->map(fn ($group) => $group->values()->all())
            ->all();
    }

    /** @return array{total: int, customer: int, staff: int, staff_push_only: int} */
    public function getSummary(): array
    {
        $all = collect(NotificationCatalog::all());
        $staff = $all->where('audience', '!=', 'pelanggan');

        return [
            'total' => $all->count(),
            'customer' => $all->where('audience', 'pelanggan')->count(),
            'staff' => $staff->count(),
            'staff_push_only' => $staff->where('panel_inbox', false)->count(),
        ];
    }
}
