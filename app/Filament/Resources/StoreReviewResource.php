<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StoreReviewResource\Pages;
use App\Models\Store;
use App\Models\StoreReview;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StoreReviewResource extends Resource
{
    protected static ?string $model = StoreReview::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 55;

    protected static ?string $navigationLabel = 'Review Toko';

    protected static ?string $modelLabel = 'Review';

    protected static ?string $pluralModelLabel = 'Review Toko';

    // SEBELUMNYA tidak ada override sama sekali — jadi semua staff yang
    // login panel bisa buka menu ini walau tidak dicentang di "Akses
    // Menu"-nya. Ditambahkan supaya konsisten dengan resource lain yang
    // dibatasi lewat menu_access.
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    // SEBELUMNYA tidak ada override sama sekali — cuma "aman" secara
    // tidak sengaja karena tidak ada DeleteAction terpasang di table().
    // Ditambahkan eksplisit supaya tidak bergantung pada "kebetulan tidak
    // ada tombolnya" kalau nanti ada yang menambahkan DeleteAction/akses
    // lain ke resource ini.
    public static function canDelete($record): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    /**
     * store_manager (admin toko) cuma lihat review milik store-nya
     * sendiri — pola sama persis dengan WarrantyResource. super_admin
     * lihat semua.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    /**
     * Badge merah — jumlah review negatif yang belum ditindaklanjuti,
     * supaya store manager langsung tahu ada berapa yang perlu di-follow-up.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->where('sentiment', 'negative')
            ->whereNull('followed_up_at')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Review')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('store.name')
                        ->label('Toko')
                        ->content(fn (?StoreReview $record) => $record?->store?->name ?? '—'),

                    Forms\Components\Placeholder::make('customer.name')
                        ->label('Customer')
                        ->content(fn (?StoreReview $record) => $record?->customer?->name ?? '—'),

                    Forms\Components\Placeholder::make('booking.booking_number')
                        ->label('Booking')
                        ->content(fn (?StoreReview $record) => $record?->booking?->booking_number ?? '—'),

                    Forms\Components\Placeholder::make('sentiment')
                        ->label('Sentimen')
                        ->content(fn (?StoreReview $record) => match ($record?->sentiment) {
                            'positive' => 'Puas',
                            'neutral'  => 'Biasa Saja',
                            'negative' => 'Kurang Puas',
                            default    => '—',
                        }),

                    Forms\Components\Placeholder::make('tags')
                        ->label('Aspek yang Disebut')
                        ->columnSpanFull()
                        ->content(fn (?StoreReview $record) => collect($record?->tags ?? [])
                            ->map(fn ($tag) => StoreReview::TAGS[$tag] ?? $tag)
                            ->implode(', ') ?: '—'),

                    Forms\Components\Placeholder::make('comment')
                        ->label('Komentar')
                        ->columnSpanFull()
                        ->content(fn (?StoreReview $record) => $record?->comment ?: '—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tanggal')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Customer')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('booking.booking_number')
                    ->label('Booking')
                    ->placeholder('—'),

                Tables\Columns\BadgeColumn::make('sentiment')
                    ->label('Sentimen')
                    ->colors([
                        'success' => 'positive',
                        'warning' => 'neutral',
                        'danger'  => 'negative',
                    ])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'positive' => 'Puas',
                        'neutral'  => 'Biasa Saja',
                        'negative' => 'Kurang Puas',
                        default    => $state,
                    }),

                Tables\Columns\TextColumn::make('tags')
                    ->label('Aspek')
                    ->formatStateUsing(fn (?array $state) => collect($state ?? [])
                        ->map(fn ($tag) => StoreReview::TAGS[$tag] ?? $tag)
                        ->implode(', ') ?: '—')
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('comment')
                    ->label('Komentar')
                    ->limit(50)
                    ->placeholder('—')
                    ->wrap(),

                Tables\Columns\IconColumn::make('followed_up_at')
                    ->label('Ditindaklanjuti')
                    ->boolean()
                    ->getStateUsing(fn (StoreReview $record) => $record->followed_up_at !== null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('sentiment')
                    ->label('Sentimen')
                    ->options([
                        'positive' => 'Puas',
                        'neutral'  => 'Biasa Saja',
                        'negative' => 'Kurang Puas',
                    ]),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id'))
                    ->visible(fn () => auth()->user()?->isFullAccess()),

                Tables\Filters\TernaryFilter::make('followed_up_at')
                    ->label('Sudah Ditindaklanjuti')
                    ->nullable(),
            ])
            ->actions([
                // Cuma relevan untuk review negatif — supaya store manager
                // tahu keluhan mana yang sudah/belum ditangani.
                Tables\Actions\Action::make('mark_followed_up')
                    ->label('Tandai Ditindaklanjuti')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (StoreReview $record) => $record->sentiment === 'negative' && ! $record->followed_up_at)
                    ->requiresConfirmation()
                    ->modalDescription('Tandai review ini sudah ditindaklanjuti (sudah dihubungi/diselesaikan)?')
                    ->action(function (StoreReview $record) {
                        $record->update([
                            'followed_up_at' => now(),
                            'followed_up_by' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title('Review ditandai sudah ditindaklanjuti')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStoreReviews::route('/'),
            'view'  => Pages\ViewStoreReview::route('/{record}'),
        ];
    }
}
