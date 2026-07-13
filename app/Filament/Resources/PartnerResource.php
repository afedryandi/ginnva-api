<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PartnerResource\Pages;
use App\Models\Partner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PartnerResource extends Resource
{
    protected static ?string $model = Partner::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Partnership Referral';

    protected static ?string $navigationLabel = 'Partner';

    protected static ?string $modelLabel = 'Partner';

    protected static ?string $pluralModelLabel = 'Partner';

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Akun Login Partner')
                ->description('Partner login di mobile app pakai email + password ini (endpoint yang sama dengan staff).')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->unique(table: 'users', column: 'email', ignoreRecord: true, modifyRuleUsing: fn ($rule, $record) => $record ? $rule->ignore($record->user_id, 'id') : $rule)
                        ->disabled(fn (?Partner $record) => $record !== null)
                        ->dehydrated()
                        ->helperText(fn (?Partner $record) => $record ? 'Email tidak bisa diubah setelah akun dibuat.' : null),

                    Forms\Components\TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->required(fn (string $context): bool => $context === 'create')
                        ->dehydrated(fn ($state) => filled($state))
                        ->minLength(8)
                        ->same('passwordConfirmation')
                        ->live(debounce: 500)
                        ->helperText(fn (string $context) => $context === 'create'
                            ? 'Minimal 8 karakter.'
                            : 'Kosongkan kalau tidak ingin ganti password.'),

                    Forms\Components\TextInput::make('passwordConfirmation')
                        ->label('Konfirmasi Password')
                        ->password()
                        ->revealable()
                        ->required(fn (string $context): bool => $context === 'create')
                        ->dehydrated(false),
                ]),

            Forms\Components\Section::make('Data Partner')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('business_name')
                        ->label('Nama Partner / Usaha')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone')
                        ->label('No. Telepon / HP')
                        ->tel()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('referral_code')
                        ->label('Kode Referral')
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (?Partner $record) => $record !== null)
                        ->helperText('Kode ini otomatis dibuat sistem — dibagikan partner ke kenalan/customernya.'),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'active'   => 'Aktif',
                            'inactive' => 'Nonaktif',
                        ])
                        ->required()
                        ->default('active'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('business_name')
                    ->label('Nama Partner')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable(),

                Tables\Columns\TextColumn::make('referral_code')
                    ->label('Kode Referral')
                    ->badge()
                    ->color('info')
                    ->copyable()
                    ->copyMessage('Kode referral disalin'),

                Tables\Columns\TextColumn::make('points_balance')
                    ->label('Saldo Poin')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => 'active',
                        'danger'  => 'inactive',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active'   => 'Aktif',
                        'inactive' => 'Nonaktif',
                        default    => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Bergabung')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active'   => 'Aktif',
                        'inactive' => 'Nonaktif',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPartners::route('/'),
            'create' => Pages\CreatePartner::route('/create'),
            'edit'   => Pages\EditPartner::route('/{record}/edit'),
        ];
    }
}
