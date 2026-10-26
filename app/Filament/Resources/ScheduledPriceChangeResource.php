<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ScheduledPriceChangeResource\Pages;
use App\Models\FilmProduct;
use App\Models\ScheduledPriceChange;
use App\Services\ScheduledPriceChangeService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use RuntimeException;

/**
 * "Perubahan Harga Terjadwal" (audit ACCURATE 5, Penyesuaian Harga Jual dengan tanggal efektif): naik atau turun sekian persen untuk
 * semua produk film aktif (atau yang dipilih) pada tanggal tertentu. Pratinjau ditampilkan sebelum disimpan; saat tanggalnya tiba harga
 * berubah otomatis (perintah harian) dan harga lama serta barunya tercatat di riwayat aktivitas. Hanya akses penuh.
 */
class ScheduledPriceChangeResource extends Resource
{
    protected static ?string $model = ScheduledPriceChange::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-date-range';

    protected static ?string $cluster = \App\Filament\Clusters\ProdukCluster::class;

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Perubahan Harga Terjadwal';

    protected static ?string $modelLabel = 'Perubahan Harga';

    protected static ?string $pluralModelLabel = 'Perubahan Harga Terjadwal';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    /** Hanya yang masih terjadwal yang bisa diubah; yang sudah berlaku atau dibatalkan menjadi riwayat. */
    public static function canEdit($record): bool
    {
        return (auth()->user()?->isFullAccess() ?? false) && ($record === null || $record->isScheduled());
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Perubahan')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama')
                        ->placeholder('Mis. Kenaikan harga November 2026')
                        ->required()
                        ->maxLength(150)
                        ->columnSpanFull(),

                    Forms\Components\DatePicker::make('effective_date')
                        ->label('Berlaku Mulai')
                        ->native(false)
                        ->required()
                        ->minDate(today())
                        ->helperText('Harga berubah otomatis pada dini hari tanggal ini. Booking dan invoice yang sudah dibuat tidak berubah.'),

                    Forms\Components\TextInput::make('percent')
                        ->label('Persen')
                        ->numeric()
                        ->required()
                        ->minValue(ScheduledPriceChangeService::MIN_PERCENT)
                        ->maxValue(ScheduledPriceChangeService::MAX_PERCENT)
                        ->suffix('%')
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                            if (is_numeric($value) && abs((float) $value) < 0.005) {
                                $fail('Persen tidak boleh nol.');
                            }
                        })
                        ->helperText('Positif menaikkan, negatif menurunkan. Contoh: 5 = naik 5%, -10 = turun 10%.')
                        ->live(onBlur: true),

                    Forms\Components\Select::make('rounding_step')
                        ->label('Pembulatan')
                        ->options(ScheduledPriceChange::ROUNDING_OPTIONS)
                        ->default(1000)
                        ->required()
                        ->live(),

                    Forms\Components\Toggle::make('include_group_prices')
                        ->label('Ikut ubah harga khusus per grup pelanggan')
                        ->helperText('Mati: hanya harga dasar dan harga per ukuran kendaraan.')
                        ->inline(false)
                        ->live(),

                    Forms\Components\Select::make('product_ids')
                        ->label('Produk')
                        ->options(fn () => FilmProduct::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (FilmProduct $p) => [$p->id => "{$p->name} ({$p->sku})"]))
                        ->multiple()
                        ->searchable()
                        ->placeholder('Semua produk film yang aktif')
                        ->helperText('Kosongkan untuk semua produk film aktif.')
                        ->columnSpanFull()
                        ->live(),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Pratinjau')
                ->description('Contoh harga yang akan berubah dengan pengaturan di atas, dihitung dari harga saat ini.')
                ->schema([
                    Forms\Components\Placeholder::make('preview')
                        ->hiddenLabel()
                        ->content(function (Forms\Get $get) {
                            $percent = $get('percent');

                            if (! is_numeric($percent)) {
                                return 'Isi persen untuk melihat pratinjau.';
                            }

                            try {
                                $preview = app(ScheduledPriceChangeService::class)->preview([
                                    'percent' => $percent,
                                    'rounding_step' => $get('rounding_step'),
                                    'product_ids' => $get('product_ids'),
                                    'include_group_prices' => (bool) $get('include_group_prices'),
                                ]);
                            } catch (RuntimeException $e) {
                                return $e->getMessage();
                            }

                            if ($preview['count'] === 0) {
                                return 'Tidak ada harga yang berubah dengan pengaturan ini.';
                            }

                            $rp = fn (float $n) => 'Rp' . number_format($n, 0, ',', '.');
                            $lines = collect($preview['sample'])->map(fn (array $r) => "{$r['product']} ({$r['label']}): {$rp($r['old'])} → {$rp($r['new'])}")->implode("\n");

                            return new \Illuminate\Support\HtmlString(
                                '<p><strong>' . $preview['count'] . ' harga pada ' . $preview['products'] . ' produk akan berubah.</strong></p>'
                                . '<p style="white-space: pre-line; margin-top: .5rem;">' . e($lines) . '</p>'
                                . ($preview['count'] > count($preview['sample']) ? '<p style="margin-top: .5rem;">… dan ' . ($preview['count'] - count($preview['sample'])) . ' lainnya.</p>' : '')
                            );
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->weight('bold'),

                Tables\Columns\TextColumn::make('effective_date')->label('Berlaku Mulai')->date('d M Y')->sortable(),

                Tables\Columns\TextColumn::make('percent')
                    ->label('Persen')
                    ->formatStateUsing(fn ($state) => ((float) $state > 0 ? '+' : '') . rtrim(rtrim(number_format((float) $state, 2, ',', '.'), '0'), ',') . '%')
                    ->badge()
                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : 'info'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ScheduledPriceChange::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        ScheduledPriceChange::STATUS_SCHEDULED => 'warning',
                        ScheduledPriceChange::STATUS_APPLIED => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('affected_prices')->label('Harga Berubah')->placeholder('—'),

                Tables\Columns\TextColumn::make('applied_at')->label('Diterapkan')->dateTime('d M Y H:i')->placeholder('—')->toggleable(),

                Tables\Columns\TextColumn::make('creator.name')->label('Dibuat Oleh')->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Status')->options(ScheduledPriceChange::STATUS_LABELS),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn (ScheduledPriceChange $record) => static::canEdit($record)),

                // Menerapkan sebelum dini hari: hanya bila tanggal efektifnya sudah tiba (mis. perintah harian belum sempat jalan).
                Tables\Actions\Action::make('apply_now')
                    ->label('Terapkan Sekarang')
                    ->icon('heroicon-o-bolt')
                    ->color('warning')
                    ->visible(fn (ScheduledPriceChange $record) => static::canEdit($record) && $record->effective_date->lte(today()))
                    ->requiresConfirmation()
                    ->modalDescription('Harga produk berubah sekarang sesuai pengaturan ini. Langkah ini tidak bisa dibatalkan; kembalikan dengan perubahan harga baru bila perlu.')
                    ->action(function (ScheduledPriceChange $record) {
                        $count = app(ScheduledPriceChangeService::class)->apply($record);

                        if ($count === null) {
                            Notification::make()->title('Perubahan ini sudah tidak terjadwal')->warning()->send();

                            return;
                        }

                        Notification::make()->title("{$count} harga diubah")->success()->send();
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ScheduledPriceChange $record) => static::canEdit($record))
                    ->requiresConfirmation()
                    ->modalDescription('Perubahan ini tidak akan diterapkan.')
                    ->action(function (ScheduledPriceChange $record) {
                        $record->update(['status' => ScheduledPriceChange::STATUS_CANCELLED]);

                        Notification::make()->title('Perubahan harga dibatalkan')->success()->send();
                    }),
            ])
            ->defaultSort('effective_date', 'desc')
            ->emptyStateHeading('Belum ada perubahan harga terjadwal')
            ->emptyStateDescription('Buat satu untuk menaikkan atau menurunkan harga produk film pada tanggal tertentu.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScheduledPriceChanges::route('/'),
            'create' => Pages\CreateScheduledPriceChange::route('/create'),
            'edit' => Pages\EditScheduledPriceChange::route('/{record}/edit'),
        ];
    }
}
