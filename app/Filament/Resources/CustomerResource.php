<?php

namespace App\Filament\Resources;

use App\Exports\CustomerExport;
use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Customer;
use App\Models\Partner;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Maatwebsite\Excel\Facades\Excel;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Marketing/Konten';

    protected static ?int $navigationSort = 60;

    protected static ?string $navigationLabel = 'Akun Customer';

    protected static ?string $modelLabel = 'Customer';

    protected static ?string $pluralModelLabel = 'Akun Customer';

    /**
     * Read-only untuk semua admin — akun customer dibuat sendiri oleh
     * pengguna lewat OTP di mobile app, admin tidak perlu (dan tidak
     * boleh) membuat/edit akun customer secara manual. Resource ini
     * murni untuk MELIHAT siapa saja yang sudah daftar, plus riwayat
     * warranty & booking mereka.
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

    /**
     * Aksi khusus untuk set referral MANUAL — dipakai sebelum app rilis
     * (belum ada jalur mobile buat customer isi kode referral sendiri),
     * atau untuk koreksi data. SENGAJA dipisah dari form() resource ini
     * (yang sisanya tetap read-only, lihat komentar canViewAny()) supaya
     * cuma dua field referral ini yang bisa admin ubah, bukan buka akses
     * edit nama/email/dll.
     *
     * ADA DUA VARIAN kelas (setReferralTableAction/setReferralHeaderAction)
     * karena di Filament v3.3, action untuk ROW TABEL wajib
     * Filament\Tables\Actions\Action (butuh method ->table() yang dipanggil
     * internal saat dipasang di table()->actions()), sedangkan action untuk
     * HEADER HALAMAN wajib Filament\Actions\Action (Tables\Actions\Action
     * tidak diterima di sana, lihat error "Header actions must be an
     * instance of Filament\Actions\Action"). Konfigurasinya sama persis,
     * cuma dibagi lewat referralFormSchema()/referralFillForm() supaya
     * tidak duplikat logic.
     */
    private static function referralFormSchema(Customer $record): array
    {
        return [
            // Cuma boleh salah satu — customer ini direferensikan customer
            // LAIN atau Partner, tidak dua-duanya sekaligus (supaya jelas
            // siapa sumber referralnya). Diamankan 2 lapis: UI saling
            // mengunci lewat ->live()+->disabled() di bawah, DAN action()
            // di bawah membersihkan yang satu lagi kalau somehow dua-duanya
            // kekirim (mis. race condition/bypass UI).
            Forms\Components\Select::make('referred_by_customer_id')
                ->label('Direferensikan Customer (Ajak Teman)')
                ->relationship(
                    name: 'referredBy',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query) => $query->where('id', '!=', $record->id),
                )
                ->getOptionLabelFromRecordUsing(fn (Customer $c) => "{$c->name} ({$c->referral_code})")
                ->searchable(['name', 'phone_number', 'referral_code'])
                ->preload()
                ->nullable()
                ->live()
                ->disabled(fn (Forms\Get $get) => filled($get('referred_by_partner_id')))
                // WAJIB — field disabled() secara default TIDAK dehydrated
                // (tidak ikut terkirim ke $data saat submit). Tanpa ini,
                // begitu admin clear field ini lalu isi field satunya
                // (yang bikin field ini ke-disable lagi sebelum sempat
                // tersimpan), nilai "sudah dikosongkan" itu tidak pernah
                // sampai ke server — DB tetap menyimpan nilai lama, bukan
                // null. Efeknya dua field bisa kembali terisi berbarengan
                // di database walau UI-nya sudah saling mengunci.
                ->dehydrated(true)
                ->helperText('Kosongkan dulu "Direferensikan Partner" di bawah kalau mau isi ini.'),

            Forms\Components\Select::make('referred_by_partner_id')
                ->label('Direferensikan Partner (Mitra Bisnis)')
                ->relationship(name: 'referredByPartner', titleAttribute: 'business_name')
                ->getOptionLabelFromRecordUsing(fn (Partner $record) => "{$record->business_name} ({$record->referral_code})")
                ->searchable(['business_name', 'referral_code'])
                ->preload()
                ->nullable()
                ->live()
                ->disabled(fn (Forms\Get $get) => filled($get('referred_by_customer_id')))
                ->dehydrated(true)
                ->helperText('Penanda saja — poin tetap baru diberikan lewat "Proses Referral" di Booking setelah customer ini booking & selesai. Kode referral partner di form itu akan otomatis ter-prefill dari sini. Kosongkan dulu "Direferensikan Customer" di atas kalau mau isi ini.'),
        ];
    }

    private static function referralFillForm(Customer $record): array
    {
        return [
            'referred_by_customer_id' => $record->referred_by_customer_id,
            'referred_by_partner_id' => $record->referred_by_partner_id,
        ];
    }

    /**
     * Lapis pengaman kedua (server-side) untuk aturan "cuma boleh salah
     * satu" — UI sudah saling mengunci lewat ->disabled(), tapi kalau
     * somehow dua-duanya kekirim, prioritaskan yang baru diisi (bukan
     * gagal diam-diam menyimpan kombinasi yang tidak seharusnya mungkin).
     */
    private static function saveReferral(Customer $record, array $data): void
    {
        if (filled($data['referred_by_customer_id'] ?? null) && filled($data['referred_by_partner_id'] ?? null)) {
            // Yang berubah dari nilai lama dianggap input yang dimaksud.
            if ((int) $data['referred_by_customer_id'] !== (int) $record->referred_by_customer_id) {
                $data['referred_by_partner_id'] = null;
            } else {
                $data['referred_by_customer_id'] = null;
            }
        }

        $record->update($data);
    }

    public static function setReferralTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('setReferral')
            ->label('Set Referral')
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->form(fn (Customer $record): array => static::referralFormSchema($record))
            ->fillForm(fn (Customer $record): array => static::referralFillForm($record))
            ->action(fn (Customer $record, array $data) => static::saveReferral($record, $data));
    }

    public static function setReferralHeaderAction(): Action
    {
        return Action::make('setReferral')
            ->label('Set Referral')
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->form(fn (Customer $record): array => static::referralFormSchema($record))
            ->fillForm(fn (Customer $record): array => static::referralFillForm($record))
            ->action(fn (Customer $record, array $data) => static::saveReferral($record, $data));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Akun')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama')
                        ->disabled(),

                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->disabled(),

                    Forms\Components\TextInput::make('phone_number')
                        ->label('No. WhatsApp')
                        ->disabled(),

                    Forms\Components\Placeholder::make('email_verified_at')
                        ->label('Email Terverifikasi')
                        ->content(fn (?Customer $record) => $record?->email_verified_at?->format('d M Y H:i') ?? 'Belum'),
                ]),

            Forms\Components\Section::make('Referral')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('referral_code')
                        ->label('Kode Referral Miliknya')
                        ->content(fn (?Customer $record) => $record?->referral_code ?? '—'),

                    Forms\Components\Placeholder::make('referredBy.name')
                        ->label('Diajak Customer')
                        ->content(fn (?Customer $record) => $record?->referredBy?->name
                            ? "{$record->referredBy->name} (kode: {$record->referredBy->referral_code})"
                            : '—'),

                    Forms\Components\Placeholder::make('referredByPartner.business_name')
                        ->label('Direferensikan Partner')
                        ->content(fn (?Customer $record) => $record?->referredByPartner?->business_name
                            ? "{$record->referredByPartner->business_name} (kode: {$record->referredByPartner->referral_code})"
                            : '—'),

                    Forms\Components\Placeholder::make('referrals_list')
                        ->label('Customer yang Direferensikan (Ajak Teman)')
                        ->columnSpanFull()
                        ->content(function (?Customer $record) {
                            if (! $record) return '—';
                            $names = $record->referrals()->pluck('name')->filter()->values();
                            if ($names->isEmpty()) return 'Belum ada yang memakai kode referral ini.';
                            return new HtmlString($names->map(fn ($n) => e($n))->implode('<br>'));
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('phone_number')
                    ->label('No. WhatsApp')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('warranties_count')
                    ->label('Jumlah Garansi')
                    ->counts('warranties'),

                Tables\Columns\TextColumn::make('bookings_count')
                    ->label('Jumlah Booking')
                    ->counts('bookings'),

                Tables\Columns\TextColumn::make('referral_code')
                    ->label('Kode Referral')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('referredBy.name')
                    ->label('Diajak Customer')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('referredByPartner.business_name')
                    ->label('Direferensikan Partner')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('referrals_count')
                    ->label('Jml. Mengajak')
                    ->counts('referrals')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Daftar Pada')
                    ->dateTime('d M Y')
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('export')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn () => auth()->user()?->isFullAccess())
                    ->action(fn () => Excel::download(
                        new CustomerExport(),
                        'customers-' . now()->format('Ymd') . '.xlsx'
                    )),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::setReferralTableAction(),
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
            'index' => Pages\ListCustomers::route('/'),
            'view' => Pages\ViewCustomer::route('/{record}'),
        ];
    }
}
