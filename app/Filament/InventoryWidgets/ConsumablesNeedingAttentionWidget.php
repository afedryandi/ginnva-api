<?php

namespace App\Filament\InventoryWidgets;

use App\Filament\Resources\ConsumableItemResource;
use App\Models\ConsumableItem;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ConsumablesNeedingAttentionWidget extends BaseWidget
{
    protected static ?string $heading = 'Barang Habis Pakai Perlu Perhatian';

    protected int|string|array $columnSpan = 'full';

    // Lihat catatan di InventoryStatsOverview — lazy load default Filament
    // menyebabkan request Livewire susulan yang gagal 419.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || ($user?->hasMenuAccess(ConsumableItemResource::class) ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ConsumableItem::query()
                    ->where(function ($query) {
                        $query->where(fn ($q) => $q->whereNotNull('reorder_point')->whereColumn('current_stock', '<=', 'reorder_point'))
                            // Dead stock: ada stok tapi tidak ada pergerakan
                            // dalam DEAD_STOCK_DAYS hari (lihat ConsumableItem::isDeadStock()).
                            ->orWhere(fn ($q) => $q->where('current_stock', '>', 0)
                                ->where('updated_at', '<', now()->subDays(ConsumableItem::DEAD_STOCK_DAYS)));
                    })
                    // Baris yang sudah "Tandai Ditinjau" DAN belum ada
                    // perubahan lagi sejak itu disembunyikan.
                    ->where(fn ($q) => $q->whereNull('reviewed_at')->orWhereColumn('reviewed_at', '<', 'updated_at'))
                    // Paling menipis (selisih terbesar di bawah ambang) duluan.
                    ->orderByRaw('(reorder_point - current_stock) DESC')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Barang'),

                Tables\Columns\TextColumn::make('current_stock')
                    ->label('Stok')
                    ->formatStateUsing(fn ($state, ConsumableItem $record) => number_format((float) $state, 2) . ' ' . $record->unit),

                Tables\Columns\TextColumn::make('reorder_point')
                    ->label('Ambang Stok Menipis')
                    ->formatStateUsing(fn ($state, ConsumableItem $record) => number_format((float) $state, 2) . ' ' . $record->unit),

                // Saran reorder sederhana: berapa unit lagi supaya kembali
                // di atas ambang. '—' untuk baris yang di-flag karena dead
                // stock saja (bukan low-stock) — reorder tidak relevan
                // kalau stoknya justru berlebih tapi tidak terpakai.
                Tables\Columns\TextColumn::make('reorder_suggestion')
                    ->label('Saran Reorder')
                    ->state(fn (ConsumableItem $record) => $record->isLowStock()
                        ? '+' . number_format(max(0, (float) $record->reorder_point - (float) $record->current_stock), 2) . ' ' . $record->unit
                        : '—')
                    ->badge()
                    ->color('danger'),

                // Sekarang bisa lebih dari 1 kemungkinan alasan (menipis
                // dan/atau tidak bergerak), jadi badge ini TIDAK konstan
                // lagi seperti sebelumnya.
                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->badge()
                    ->state(function (ConsumableItem $record) {
                        $reasons = [];
                        if ($record->isLowStock()) $reasons[] = 'Stok Menipis';
                        if ($record->isDeadStock()) $reasons[] = 'Tidak Bergerak (' . ConsumableItem::DEAD_STOCK_DAYS . '+ hari)';

                        return implode(' + ', $reasons);
                    })
                    ->color('danger'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Terakhir Diubah')
                    ->since()
                    ->tooltip(fn (ConsumableItem $record) => $record->updated_at?->format('d M Y H:i')),
            ])
            ->actions([
                Tables\Actions\Action::make('acknowledge')
                    ->label('Tandai Ditinjau')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Sembunyikan baris ini dari "Perlu Perhatian"? Akan muncul lagi otomatis kalau ada perubahan baru (stok masuk/keluar, dst).')
                    ->action(function (ConsumableItem $record) {
                        $record->acknowledge(auth()->id());
                        Notification::make()->title('Ditandai sudah ditinjau')->success()->send();
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading('Semua barang habis pakai dalam kondisi baik')
            ->emptyStateDescription('Tidak ada barang habis pakai yang stoknya menipis atau tidak bergerak.')
            ->emptyStateIcon('heroicon-o-check-circle')
            // Matikan auto-refresh berkala — sama alasannya dengan
            // MaterialsNeedingAttentionWidget.
            ->poll(null);
    }
}
