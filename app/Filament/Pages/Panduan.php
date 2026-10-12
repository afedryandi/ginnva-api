<?php

namespace App\Filament\Pages;

use App\Support\GuideCatalog;
use Filament\Pages\Page;

/**
 * Halaman "Panduan" untuk staf (2026-10-12, audit UX "onboarding & bantuan"): alur singkat per modul yang kompleks.
 * Terbuka untuk semua staf panel; yang tampil hanya panduan modul yang bisa diakses staf itu (akses penuh melihat semua).
 * Isi ada di App\Support\GuideCatalog.
 */
class Panduan extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';
    protected static ?string $navigationLabel = 'Panduan';
    protected static ?string $title = 'Panduan Penggunaan';
    protected static ?string $slug = 'panduan';
    protected static ?int $navigationSort = 999;
    protected static string $view = 'filament.pages.panduan';

    public string $search = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessStaffArea() ?? false;
    }

    /**
     * Panduan yang boleh dilihat user ini, difilter pencarian (judul, ringkasan, langkah, tips).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getGuides(): array
    {
        $user = auth()->user();
        $needle = mb_strtolower(trim($this->search));

        return array_values(array_filter(GuideCatalog::all(), function (array $guide) use ($user, $needle) {
            $allowed = $guide['menus'] === []
                || ($user?->isFullAccess() ?? false)
                || collect($guide['menus'])->contains(fn (string $menu) => $user?->hasMenuAccess($menu) ?? false);

            if (! $allowed) {
                return false;
            }

            if ($needle === '') {
                return true;
            }

            $haystack = mb_strtolower(implode(' ', array_merge([$guide['title'], $guide['summary']], $guide['steps'], $guide['tips'])));

            return str_contains($haystack, $needle);
        }));
    }

    /** Teks panduan memakai **tebal** sederhana; diubah jadi HTML dengan escape lebih dulu supaya aman. */
    public static function format(string $text): string
    {
        return preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', e($text));
    }
}
