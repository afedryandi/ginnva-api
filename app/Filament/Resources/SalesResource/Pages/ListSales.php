<?php

namespace App\Filament\Resources\SalesResource\Pages;

use App\Filament\Resources\SalesResource;
use Filament\Resources\Pages\ListRecords;

// Sengaja tidak override getHeaderActions() — tidak ada CreateAction,
// resource ini murni laporan read-only (lihat komentar di SalesResource).
//
// TIDAK LAGI punya getHeaderWidgets() (audit 2026-09-11, temuan #3) —
// stat card SEKARANG dirender lewat Table::header() di SalesResource
// (baca $livewire->getFilteredTableQuery(), REAKTIF ikut filter yang
// sedang aktif), bukan header widget statis yang cuma tampilkan total
// keseluruhan data. Lihat App\Filament\Widgets\SalesDetailStatsWidget
// untuk agregasi SQL-nya (dipakai bersama, satu implementasi).
class ListSales extends ListRecords
{
    protected static string $resource = SalesResource::class;
}
