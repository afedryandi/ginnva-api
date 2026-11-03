<?php

namespace App\Filament\InventoryWidgets\Concerns;

use App\Models\RawMaterial;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tinjauan baris "Perlu Perhatian" untuk tabel Dashboard Inventaris (audit 2026-11-02): filter Perlu ditinjau / Sudah ditinjau,
 * kolom siapa dan kapan meninjau, tombol Tandai Ditinjau, dan tombol Batalkan Tinjauan. Keduanya butuh izin Ubah eksplisit pada menu
 * barangnya. Aturan kapan baris muncul lagi ada di Acknowledgeable.
 */
trait ManagesReview
{
    protected static function reviewFilter(): Tables\Filters\SelectFilter
    {
        return Tables\Filters\SelectFilter::make('review')
            ->label('Tinjauan')
            ->options(['pending' => 'Perlu ditinjau', 'reviewed' => 'Sudah ditinjau (disembunyikan)'])
            ->default('pending')
            ->selectablePlaceholder(false)
            ->query(fn (Builder $query, array $data) => ($data['value'] ?? 'pending') === 'reviewed' ? $query->reviewed() : $query->needsReview());
    }

    /** Siapa dan kapan; hanya tampil bila filter "Sudah ditinjau" aktif. */
    protected static function reviewColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('review_info')
            ->label('Ditinjau')
            ->state(fn ($record) => $record->reviewed_at
                ? ($record->reviewedBy?->name ?? 'User terhapus') . ', ' . $record->reviewed_at->diffForHumans()
                : '—')
            ->visible(fn ($livewire) => ($livewire->tableFilters['review']['value'] ?? 'pending') === 'reviewed');
    }

    /** @return array<int, Tables\Actions\Action> */
    protected static function reviewActions(string $resource): array
    {
        $canUpdate = fn () => auth()->user()?->hasModuleAction($resource, 'update', false) ?? false;
        $days = RawMaterial::reviewValidDays();

        return [
            Tables\Actions\Action::make('acknowledge')
                ->label('Tandai Ditinjau')
                ->icon('heroicon-o-eye')
                ->color('gray')
                // Menyembunyikan baris untuk semua orang: butuh izin Ubah eksplisit pada menu barangnya.
                ->visible(fn ($record) => $canUpdate() && ! $record->isAcknowledged())
                ->requiresConfirmation()
                ->modalDescription("Sembunyikan baris ini dari \"Perlu Perhatian\" selama {$days} hari? Muncul lagi lebih cepat bila datanya berubah atau kondisinya memburuk (mis. kedaluwarsa). Bisa dibatalkan lewat filter \"Sudah ditinjau\".")
                ->action(function ($record) {
                    $record->acknowledge(auth()->id());
                    Notification::make()->title('Ditandai sudah ditinjau')->success()->send();
                }),

            Tables\Actions\Action::make('unacknowledge')
                ->label('Batalkan Tinjauan')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn ($record) => $canUpdate() && $record->isAcknowledged())
                ->requiresConfirmation()
                ->modalDescription('Tampilkan kembali baris ini di "Perlu Perhatian"?')
                ->action(function ($record) {
                    $record->unacknowledge();
                    Notification::make()->title('Tinjauan dibatalkan')->success()->send();
                }),
        ];
    }
}
