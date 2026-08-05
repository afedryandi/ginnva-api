<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PartnershipInquiryResource\Pages;
use App\Models\Partner;
use App\Models\PartnershipInquiry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class PartnershipInquiryResource extends Resource
{
    protected static ?string $model = PartnershipInquiry::class;

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Kemitraan & Sales Referral';

    protected static ?string $modelLabel = 'Pengajuan Kemitraan';

    protected static ?string $pluralModelLabel = 'Kemitraan & Sales Referral';

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
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Pengajuan')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('category')
                        ->label('Kategori')
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            'sales' => 'Sales GIIAS',
                            'franchise' => 'Franchise',
                            default => $state ?? '-',
                        })
                        ->disabled()
                        ->dehydrated(false),

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
                        ->visible(fn (?PartnershipInquiry $record) => $record?->category !== 'sales')
                        ->disabled(),

                    Forms\Components\TextInput::make('car_brand')
                        ->label('Merek Mobil')
                        ->visible(fn (?PartnershipInquiry $record) => $record?->category === 'sales')
                        ->disabled(),

                    Forms\Components\TextInput::make('dealer_name')
                        ->label('Dealer')
                        ->visible(fn (?PartnershipInquiry $record) => $record?->category === 'sales')
                        ->disabled(),

                    Forms\Components\Textarea::make('message')
                        ->label('Pesan dari Pemohon')
                        ->visible(fn (?PartnershipInquiry $record) => $record?->category !== 'sales')
                        ->disabled()
                        ->columnSpanFull(),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'new' => 'Baru',
                            'contacted' => 'Sudah Dihubungi',
                            'deal' => 'Deal — Siap Jadi Partner',
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
                Tables\Columns\BadgeColumn::make('category')
                    ->label('Kategori')
                    ->colors([
                        'gray' => 'franchise',
                        'primary' => 'sales',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'franchise' => 'Franchise',
                        'sales' => 'Sales GIIAS',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('applicant_name')
                    ->label('Nama Pemohon')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('phone_number')
                    ->label('No. Telepon')
                    ->searchable(),

                Tables\Columns\TextColumn::make('city')
                    ->label('Kota')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('car_brand')
                    ->label('Merek Mobil')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('dealer_name')
                    ->label('Dealer')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'new',
                        'info' => 'contacted',
                        'success' => 'deal',
                        'danger' => 'rejected',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'new' => 'Baru',
                        'contacted' => 'Sudah Dihubungi',
                        'deal' => 'Deal',
                        'rejected' => 'Ditolak',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('partner.referral_code')
                    ->label('Kode Referral')
                    ->badge()
                    ->color('info')
                    ->placeholder('Belum jadi partner'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori')
                    ->options([
                        'franchise' => 'Franchise',
                        'sales' => 'Sales GIIAS',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'new' => 'Baru',
                        'contacted' => 'Sudah Dihubungi',
                        'deal' => 'Deal',
                        'rejected' => 'Ditolak',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('jadikan_partner')
                    ->label('Jadikan Partner')
                    ->icon('heroicon-o-user-plus')
                    ->color('success')
                    ->visible(fn (PartnershipInquiry $record) => $record->status === 'deal' && ! $record->partner_id)
                    ->form([
                        Forms\Components\TextInput::make('business_name')
                            ->label('Nama Partner / Usaha')
                            ->required(),
                        Forms\Components\TextInput::make('email')
                            ->label('Email (untuk login)')
                            ->email()
                            ->required()
                            ->unique(table: 'users', column: 'email')
                            ->helperText('Wajib diisi kalau pemohon tidak mencantumkan email saat mengajukan.'),
                        Forms\Components\TextInput::make('phone')
                            ->label('No. Telepon')
                            ->tel(),
                        Forms\Components\TextInput::make('password')
                            ->label('Password Awal')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->helperText('Sampaikan ke partner secara manual — bisa diganti nanti lewat fitur lupa password di app.'),
                    ])
                    ->fillForm(fn (PartnershipInquiry $record) => [
                        'business_name' => $record->applicant_name,
                        'email'         => $record->email,
                        'phone'         => $record->phone_number,
                    ])
                    ->action(function (PartnershipInquiry $record, array $data) {
                        // Dibungkus transaction — kalau update() gagal
                        // setelah createAccount() berhasil, akun User+Partner
                        // yang baru dibuat ikut di-rollback juga (tombol ini
                        // baru hilang setelah partner_id benar-benar
                        // tersimpan, jadi tanpa transaction ada celah re-klik
                        // bikin akun duplikat kalau langkah kedua gagal).
                        $partner = DB::transaction(function () use ($record, $data) {
                            $partner = Partner::createAccount($data);
                            $record->update(['partner_id' => $partner->id]);

                            return $partner;
                        });

                        Notification::make()
                            ->title('Akun partner dibuat')
                            ->body("Kode referral: {$partner->referral_code}")
                            ->success()
                            ->send();
                    }),

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
