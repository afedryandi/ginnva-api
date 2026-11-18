<?php

namespace App\Filament\Pages;

use App\Models\Store;
use Filament\Pages\Dashboard;
use Livewire\Attributes\Url;

/**
 * Dashboard Utama (/admin) — override Filament\Pages\Dashboard bawaan
 * (audit "Dashboard Utama" 2026-09-25, gap "standar enterprise dashboard":
 * sebelumnya tidak ada filter cabang terpusat sama sekali, beda dari
 * Dashboard Penjualan yang sudah punya $storeId + dropdown "Semua
 * cabang"/toko tertentu untuk full-access).
 *
 * BUKAN lagi ->widgets([...]) otomatis lewat <x-filament-widgets::widgets>
 * (itu tidak bisa mengirim param mount() per-widget) — view kustom
 * dashboard-home.blade.php me-render tiap widget lewat @livewire(...,
 * ['storeId' => $this->storeId]), PERSIS pola yang sudah terbukti di
 * SalesDashboard.php. canView() tiap widget dicek MANUAL di blade (bukan
 * otomatis lewat komponen <x-filament-widgets::widgets>) supaya
 * hasMenuAccess() per staff tetap dihormati persis seperti sebelumnya.
 *
 * $storeId #[Url] (sama alasan SalesDashboard) — supaya link yang sudah
 * difilter ke 1 cabang bisa dibookmark/dibagikan, bertahan lewat refresh.
 */
class DashboardHome extends Dashboard
{
    protected static string $view = 'filament.pages.dashboard-home';

    // Nama halaman: "Dashboard" (sebelumnya "Dasbor"/"Dashboard Utama"). Ikon outline saat biasa, solid saat aktif.
    protected static ?string $title = 'Dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $activeNavigationIcon = 'heroicon-s-home';

    #[Url(as: 'cabang')]
    public ?int $storeId = null;

    public function mount(): void
    {
        // Staff toko (bukan full-access) tidak pernah boleh override
        // cabang lewat query string manual — dikunci null di sini,
        // effectiveStoreId() di tiap widget sudah mengabaikan $storeId
        // untuk non-full-access, tapi dibersihkan juga di URL supaya
        // link yang dibagikan tidak menyesatkan (seolah bisa pilih cabang).
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $this->storeId = null;
        }
    }

    public function updatedStoreId(): void
    {
        // Livewire otomatis re-render widget lewat wire:key yang
        // menyertakan storeId (lihat blade) — tidak perlu reset cache
        // apa pun di sini, beda dari SalesDashboard yang punya
        // $resultCache manual.
    }

    /** Judul halaman berupa sapaan menurut jam, mis. "Selamat siang, Antony". */
    public function getHeading(): string|IlluminateContractsSupportHtmlable
    {
        $hour = (int) now()->format('G');
        $greeting = match (true) {
            $hour < 11 => 'Selamat pagi',
            $hour < 15 => 'Selamat siang',
            $hour < 19 => 'Selamat sore',
            default => 'Selamat malam',
        };
        $name = trim((string) (explode(' ', trim((string) auth()->user()?->name))[0] ?? ''));

        return $name !== '' ? "{$greeting}, {$name}" : $greeting;
    }

    /** Anak judul: ruang lingkup data (toko) dan tanggal hari ini. */
    public function getSubheading(): ?string
    {
        $options = $this->getStoreOptions();
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;
        $scope = $this->storeId
            ? ($options[$this->storeId] ?? 'Toko terpilih')
            : ($isFullAccess ? 'Semua toko' : (auth()->user()?->store?->name ?? 'Toko Anda'));

        return $scope . ' · ' . now()->locale('id')->translatedFormat('l, j F Y');
    }

    /** Daftar toko untuk filter (full-access saja) — sama dengan SalesDashboard. */
    public function getStoreOptions(): array
    {
        return Store::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
