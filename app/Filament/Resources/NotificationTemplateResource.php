<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationTemplateResource\Pages;
use App\Models\NotificationTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Templat Notifikasi" (Notifikasi Fase 5, 2026-10-21): pesan yang disimpan dari menu Kirim Notifikasi ("Simpan sebagai templat")
 * untuk dipakai ulang. Di sini templat diubah namanya, disunting isinya, atau dihapus.
 */
class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-bookmark';

    protected static ?string $cluster = \App\Filament\Clusters\NotifikasiCluster::class;

    protected static ?string $navigationLabel = 'Templat Notifikasi';

    protected static ?string $modelLabel = 'Templat';

    protected static ?string $pluralModelLabel = 'Templat Notifikasi';

    protected static ?int $navigationSort = 9;

    private static function allowed(string $action): bool
    {
        $user = auth()->user();

        return (bool) ($user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, $action, true));
    }

    public static function canViewAny(): bool { return static::allowed('view'); }
    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return static::allowed('edit'); }
    public static function canDelete($record): bool { return static::allowed('delete'); }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Nama Templat')->required()->maxLength(100),
            Forms\Components\Select::make('audience')->label('Untuk')->options(['customer' => 'Pelanggan', 'partner' => 'Partner'])->required(),
            Forms\Components\Select::make('category')->label('Jenis Pesan (pelanggan)')->options(['promo' => 'Promosi', 'info' => 'Informasi penting'])->placeholder('—'),
            Forms\Components\TextInput::make('title')->label('Judul Notifikasi')->required()->maxLength(200),
            Forms\Components\Textarea::make('body')->label('Isi Pesan')->required()->maxLength(500)->rows(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('audience')
                    ->label('Untuk')
                    ->formatStateUsing(fn (string $state) => $state === 'partner' ? 'Partner' : 'Pelanggan')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('category')
                    ->label('Jenis')
                    ->formatStateUsing(fn (?string $state) => match ($state) { 'promo' => 'Promosi', 'info' => 'Informasi', default => '—' })
                    ->badge(),
                Tables\Columns\TextColumn::make('title')->label('Judul')->limit(50)->wrap(),
                Tables\Columns\TextColumn::make('created_at')->label('Dibuat')->dateTime('d M Y')->sortable(),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationTemplates::route('/'),
            'edit'  => Pages\EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
