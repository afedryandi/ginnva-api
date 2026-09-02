<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Daftar karyawan yang BELUM punya baris Attendance apa pun hari ini
 * (belum clock-in via app, dan belum dicatat manual/dinas luar admin) —
 * sebelumnya cuma ada ANGKA "N karyawan belum absen" di kartu statistik
 * dashboard (DashboardStatsWidget), tapi kliknya justru mengarah ke
 * daftar yang SUDAH absen (filter entry_type=clock), bukan yang belum.
 * Tidak ada cara sama sekali melihat SIAPA saja yang belum absen sampai
 * widget ini dibuat.
 *
 * Alpha/Izin OTOMATIS baru dibuat MarkAbsences untuk hari yang SUDAH
 * LEWAT (dini hari besoknya) — untuk hari yang masih berjalan, "belum
 * ada baris Attendance" itu satu-satunya sinyal yang valid, jadi widget
 * ini murni whereDoesntHave, bukan baca status/entry_type apa pun.
 *
 * Ditaruh di halaman List Absensi Karyawan (bukan dashboard utama)
 * supaya langsung ketemu di menu yang sama, tanpa nambah widget baru
 * di dashboard yang sudah padat.
 */
class NotCheckedInTodayWidget extends BaseWidget
{
    protected static ?string $heading = 'Belum Absen Hari Ini';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;

        return $table
            ->query(User::query()
                ->where('is_active', true)
                ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
                ->when(
                    ! $isFullAccess,
                    fn ($query) => $query->where('store_id', auth()->user()?->store_id)
                )
                ->whereDoesntHave('attendances', fn ($q) => $q->whereDate('date', today()))
                ->orderBy('name'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Karyawan')
                    ->searchable(),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title()),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->visible($isFullAccess),
            ])
            ->paginated(false)
            ->emptyStateHeading('Semua karyawan sudah tercatat hari ini')
            ->emptyStateDescription('Tidak ada yang belum absen, dicatat manual, atau dinas luar.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->poll('60s');
    }
}
