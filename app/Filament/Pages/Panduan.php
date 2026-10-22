<?php

namespace App\Filament\Pages;

use App\Support\GuideCatalog;
use Filament\Pages\Page;

/**
 * Halaman "Panduan" untuk staf (2026-10-12, audit UX "onboarding & bantuan"): alur singkat per modul yang kompleks.
 * Terbuka untuk semua staf panel; yang tampil hanya panduan modul yang bisa diakses staf itu (akses penuh melihat semua).
 * Isi ada di App\Support\GuideCatalog.
 *
 * Pencarian berjalan SEPENUHNYA di browser (Alpine), bukan Livewire: isinya statis, jadi tidak perlu memanggil server di
 * setiap ketikan. Versi Livewire (wire:model.live) memicu "419 Page Expired" di server dev pada permintaan kedua.
 */
class Panduan extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';
    protected static ?string $navigationLabel = 'Panduan';
    protected static ?string $title = 'Panduan Penggunaan';
    protected static ?string $slug = 'panduan';
    // Di dropdown "Lainnya" (sejajar Master Data / Notifikasi / Sistem). Percobaan pertama tanpa grup tidak terlihat di bar
    // atas panel ini (2026-10-12). Tautan kedua ada di menu avatar (lihat AdminPanelProvider).
    protected static ?string $navigationGroup = 'Lainnya';
    protected static ?int $navigationSort = 70;
    protected static string $view = 'filament.pages.panduan';

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessStaffArea() ?? false;
    }

    /**
     * Panduan yang boleh dilihat user ini.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getGuides(): array
    {
        return GuideCatalog::forUser(auth()->user());
    }

    /** Teks yang dicari di browser (huruf kecil): judul, ringkasan, langkah, dan tips tanpa penanda tebal. */
    public static function searchText(array $guide): string
    {
        return mb_strtolower(str_replace('**', '', implode(' ', array_merge([$guide['title'], $guide['summary']], $guide['steps'], $guide['tips'], [(string) ($guide['note'] ?? '')]))));
    }

    /** Teks panduan memakai **tebal** sederhana; diubah jadi HTML dengan escape lebih dulu supaya aman. */
    public static function format(string $text): string
    {
        return preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', e($text));
    }
}
