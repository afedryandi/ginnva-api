<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PartnershipInquiryResource\Pages;
use App\Models\PartnershipInquiry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PartnershipInquiryResource extends Resource
{
    protected static ?string $model = PartnershipInquiry::class;

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?string $navigationLabel = 'Pengajuan Kemitraan';

    protected static ?string $modelLabel = 'Pengajuan Kemitraan';

    protected static ?string $pluralModelLabel = 'Pengajuan Kemitraan';

    protected static ?string $navigationBadgeColor = 'warning';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('status', 'new')->count();

        return $count > 0 ? (string) $count : null;
    }

    /**
     * Sama seperti ProductInquiryResource — sifatnya nasional (bukan
     * milik toko tertentu), jadi semua admin lihat data yang sama.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'regional_admin']) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Pengajuan')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('applicant_name')
                        ->label('Nama Pemohon')
                        ->disabled(),

                    Forms\Components\TextInput::make('phone_number')
                        ->label('No. Telepon')
                        ->disabled(),

                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->disabled(),

                    Forms\Components\TextInput::make('city')
                        ->label('Kota')
                        ->disabled(),

                    Forms\Components\Textarea::make('message')
                        ->label('Pesan dari Pemohon')
                        ->disabled()
                        ->columnSpanFull(),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'new' => 'Baru',
                            'contacted' => 'Sudah Dihubungi',
                            'rejected' => 'Ditolak',
                        ])
                        ->required(),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan Internal')
                        ->placeholder('Catatan follow-up, hasil komunikasi, dll — tidak terlihat oleh pemohon.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('applicant_name')
                    ->label('Nama Pemohon')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('phone_number')
                    ->label('No. Telepon')
                    ->searchable(),

                Tables\Columns\TextColumn::make('city')
                    ->label('Kota')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'new',
                        'info' => 'contacted',
                        'danger' => 'rejected',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'new' => 'Baru',
                        'contacted' => 'Sudah Dihubungi',
                        'rejected' => 'Ditolak',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'new' => 'Baru',
                        'contacted' => 'Sudah Dihubungi',
                        'rejected' => 'Ditolak',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Follow Up'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPartnershipInquiries::route('/'),
            'edit' => Pages\EditPartnershipInquiry::route('/{record}/edit'),
        ];
    }
}
