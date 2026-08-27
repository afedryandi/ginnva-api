<?php

namespace App\Filament\InventoryWidgets;

use App\Filament\Resources\RawMaterialResource;
use App\Models\RawMaterial;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class MaterialsNeedingAttentionWidget extends BaseWidget
{
    protected static ?string $heading = 'Bahan Baku Perlu Perhatian';

    protected int|string|array $columnSpan = 'full';

    // Lihat catatan di InventoryStatsOverview — lazy load default Filament
    // menyebabkan request Livewire susulan yang gagal 419.
    protected static bool $isLazy = false;

    // Jangan tampilkan sama sekali kalau user tidak punya akses menu
    // Bahan Baku — sama alasannya dengan filter kartu statistik di
    // InventoryStatsOverview, supaya tidak bocor data yang sebenarnya
    // tidak boleh dia lihat.
    public static function canView(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || ($user?->hasMenuAccess(RawMaterialResource::class) ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                RawMaterial::query()->where(function ($query) {
                    $query->where(fn ($q) => $q->whereNotNull('reorder_point')->whereColumn('current_stock', '<=', 'reorder_point'))
                        // Dihitung dari batch yang MASIH ADA stoknya, bukan
                        // kolom expiry_date induk (cuma snapshot pendaftaran,
                        // tidak sinkron begitu ada batch ke-2 dst) — lihat
                        // RawMaterial::earliestActiveExpiryDate().
                        ->orWhereHas('batches', fn ($q) => $q->where('quantity', '>', 0)
                            ->whereNotNull('expiry_date')
                            ->whereDate('expiry_date', '<=', now()->addDays(30)))
                        // Dead stock: ada stok tapi tidak ada pergerakan
                        // dalam DEAD_STOCK_DAYS hari (lihat RawMaterial::isDeadStock()).
                        ->orWhere(fn ($q) => $q->where('current_stock', '>', 0)
                            ->where('updated_at', '<', now()->subDays(RawMaterial::DEAD_STOCK_DAYS)));
                })
                    // Baris yang sudah "Tandai Ditinjau" DAN belum ada
                    // perubahan lagi sejak itu disembunyikan — lihat
                    // Acknowledgeable::isAcknowledged().
                    ->where(fn ($q) => $q->whereNull('reviewed_at')->orWhereColumn('reviewed_at', '<', 'updated_at'))
                    ->withMin(['batches as earliest_expiry' => fn ($q) => $q->where('quantity', '>', 0)->whereNotNull('expiry_date')], 'expiry_date')
                    // Paling mendesak duluan: sudah kedaluwarsa dulu, baru
                    // yang stoknya sudah 0, baru sisanya — bukan urutan PK.
                    ->orderByRaw("CASE WHEN earliest_expiry IS NOT NULL AND earliest_expiry < ? THEN 0 ELSE 1 END", [now()->toDateString()])
                    ->orderByRaw('current_stock ASC')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Bahan'),

                Tables\Columns\TextColumn::make('current_stock')
                    ->label('Stok')
                    ->formatStateUsing(fn ($state, RawMaterial $record) => number_format((float) $state, 2) . ' ' . $record->unit),

                // Saran reorder sederhana: selisih antara ambang stok
                // menipis dan stok saat ini (berapa unit lagi yang perlu
                // dipesan supaya kembali di atas ambang). Tanpa lead-time
                // atau kecepatan pemakaian — cuma target minimum.
                Tables\Columns\TextColumn::make('reorder_suggestion')
                    ->label('Saran Reorder')
                    ->state(function (RawMaterial $record) {
                        if ($record->reorder_point === null || ! $record->isLowStock()) {
                            return '—';
                        }
                        $deficit = max(0, (float) $record->reorder_point - (float) $record->current_stock);

                        return $deficit > 0 ? '+' . number_format($deficit, 2) . ' ' . $record->unit : '—';
                    }),

                Tables\Columns\TextColumn::make('earliest_expiry_display')
                    ->label('Kedaluwarsa')
                    ->badge()
                    ->color(fn (RawMaterial $record) => $record->isExpired() ? 'danger' : ($record->isNearExpiry() ? 'warning' : 'gray'))
                    ->state(function (RawMaterial $record) {
                        $date = $record->earliestActiveExpiryDate();
                        if (! $date) return '—';
                        $days = now()->startOfDay()->diffInDays($date, false);
                        if ($days < 0) return 'Lewat ' . abs($days) . ' hari (' . $date->format('d M Y') . ')';
                        if ($days <= 7) return $days . ' hari lagi (' . $date->format('d M Y') . ')';

                        return $date->format('d M Y');
                    })
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->badge()
                    ->state(function (RawMaterial $record) {
                        $reasons = [];
                        if ($record->isLowStock()) $reasons[] = 'Stok Menipis';
                        if ($record->isExpired()) $reasons[] = 'Kedaluwarsa';
                        elseif ($record->isNearExpiry()) $reasons[] = 'Mendekati Kedaluwarsa';
                        if ($record->isDeadStock()) $reasons[] = 'Tidak Bergerak (' . RawMaterial::DEAD_STOCK_DAYS . '+ hari)';

                        return implode(' + ', $reasons);
                    })
                    ->color('danger'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Terakhir Diubah')
                    ->since()
                    ->tooltip(fn (RawMaterial $record) => $record->updated_at?->format('d M Y H:i')),
            ])
            ->actions([
                Tables\Actions\Action::make('acknowledge')
                    ->label('Tandai Ditinjau')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Sembunyikan baris ini dari "Perlu Perhatian"? Akan muncul lagi otomatis kalau ada perubahan baru (stok masuk/keluar, dst).')
                    ->action(function (RawMaterial $record) {
                        $record->acknowledge(auth()->id());
                        Notification::make()->title('Ditandai sudah ditinjau')->success()->send();
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading('Semua bahan baku dalam kondisi baik')
            ->emptyStateDescription('Tidak ada bahan baku yang stoknya menipis, mendekati/sudah kedaluwarsa, atau tidak bergerak.')
            ->emptyStateIcon('heroicon-o-check-circle')
            // Matikan auto-refresh berkala — request Livewire otomatis
            // tiap beberapa detik ini yang ternyata ikut gagal 419 juga
            // (lihat catatan $isLazy di atas).
            ->poll(null);
    }
}
