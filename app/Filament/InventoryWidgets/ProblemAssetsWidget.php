<?php

namespace App\Filament\InventoryWidgets;

use App\Filament\Resources\AssetResource;
use App\Models\Asset;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ProblemAssetsWidget extends BaseWidget
{
    protected static ?string $heading = 'Aset Bermasalah';

    protected int|string|array $columnSpan = 'full';

    // Lihat catatan di InventoryStatsOverview — lazy load default Filament
    // menyebabkan request Livewire susulan yang gagal 419.
    protected static bool $isLazy = false;

    // Sama alasannya dengan MaterialsNeedingAttentionWidget — jangan
    // tampilkan sama sekali kalau tidak punya akses menu Aset.
    public static function canView(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || ($user?->hasMenuAccess(AssetResource::class) ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            // AssetResource::getEloquentQuery() sudah scope ke toko sendiri
            // untuk non-full-access, tapi widget ini query Asset::query()
            // langsung (bukan lewat resource), jadi scope-nya diulang di
            // sini juga — kalau tidak, store_manager bisa lihat aset
            // bermasalah milik toko lain lewat dashboard walau tidak bisa
            // lewat menu Aset itu sendiri.
            ->query(Asset::query()
                ->whereIn('status', ['rusak', 'diperbaiki', 'hilang'])
                ->when(
                    ! (auth()->user()?->isFullAccess() ?? false),
                    fn ($query) => $query->where('store_id', auth()->user()?->store_id)
                ))
            ->columns([
                Tables\Columns\TextColumn::make('asset_tag')
                    ->label('Kode')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Aset'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'diperbaiki',
                        'danger'  => ['rusak', 'hilang'],
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'diperbaiki' => 'Diperbaiki',
                        'rusak'      => 'Rusak',
                        'hilang'     => 'Hilang',
                        default      => $state,
                    }),

                Tables\Columns\TextColumn::make('assignee.name')
                    ->label('Dipegang Oleh')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Lokasi')
                    ->placeholder('Kantor Pusat'),
            ])
            ->paginated(false)
            ->poll(null);
    }
}
