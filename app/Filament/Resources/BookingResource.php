<?php

namespace App\Filament\Resources;

use App\Exports\BookingExport;
use App\Filament\Resources\BookingResource\Pages;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\ReferralPointService;
use App\Services\ServiceReminderService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Booking Instalasi';

    protected static ?string $modelLabel = 'Booking';

    protected static ?string $pluralModelLabel = 'Booking Instalasi';

    protected static ?string $navigationBadgeColor = 'warning';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', 'pending')->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canAccessStaffArea() ?? false;
    }

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->isFullAccess();

        return $form->schema([
            Forms\Components\Section::make('Sumber Booking')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('source')
                        ->label('Asal Booking')
                        ->options([
                            'app'       => '📱 Mobile App',
                            'whatsapp'  => '💬 WhatsApp',
                            'walk_in'   => '🚶 Walk-in',
                        ])
                        ->default('whatsapp')
                        ->required()
                        ->live()
                        ->disabledOn('edit'),

                    Forms\Components\TextInput::make('booking_number')
                        ->label('No. Booking')
                        ->disabled()
                        ->hiddenOn('create'),
                ]),

            Forms\Components\Section::make('Data Customer')
                ->columns(2)
                ->schema([
                    // Booking dari app — pilih dari akun terdaftar
                    Forms\Components\Select::make('customer_id')
                        ->label('Akun Customer (App)')
                        ->options(fn () => Customer::orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($c) => [
                                $c->id => trim(($c->name ?? 'Tanpa Nama') . ' — ' . $c->email),
                            ])
                        )
                        ->searchable()
                        ->nullable()
                        ->visible(fn (Forms\Get $get) => $get('source') === 'app')
                        ->disabledOn('edit'),

                    // Booking manual (WA/walk-in) — input nama & nomor langsung
                    Forms\Components\TextInput::make('customer_name')
                        ->label('Nama Customer')
                        ->required(fn (Forms\Get $get) => in_array($get('source'), ['whatsapp', 'walk_in']))
                        ->visible(fn (Forms\Get $get) => in_array($get('source'), ['whatsapp', 'walk_in']))
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone_number')
                        ->label('No. WhatsApp / Telepon')
                        ->tel()
                        ->visible(fn (Forms\Get $get) => in_array($get('source'), ['whatsapp', 'walk_in']))
                        ->maxLength(50),
                ]),

            Forms\Components\Section::make('Detail Booking')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('store_id')
                        ->label('Toko / Workshop')
                        ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->live()
                        ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id)
                        ->disabled(! $isSuperAdmin)
                        ->afterStateUpdated(function (Forms\Set $set, $state) {
                            $set('installers', []);
                            // Isi ulang kapasitas dari default toko yang
                            // baru dipilih — ganti toko berarti konteksnya
                            // beda total, jadi angka lama (dari toko
                            // sebelumnya) tidak relevan lagi.
                            $set('capacity_per_day', Store::find($state)?->install_capacity_per_day ?? 3);
                        }),

                    Forms\Components\Select::make('installers')
                        ->label('Installer Bertugas')
                        ->relationship('installers', 'name')
                        ->helperText('Bisa pilih lebih dari 1 installer. Installer hanya bisa lihat & chat teks di booking yang ditugaskan ke dirinya di mobile app.')
                        ->options(fn (Forms\Get $get) => User::where('store_id', $get('store_id'))
                            ->whereHas('roles', fn ($q) => $q->where('name', 'installer'))
                            ->pluck('name', 'id')
                        )
                        ->multiple()
                        ->searchable()
                        ->disabled(fn (Forms\Get $get) => ! $get('store_id')),

                    // "Jenis Layanan" adalah satu-satunya pemicu — pilih
                    // "Kaca Film + PPF" langsung kalau booking mencakup
                    // keduanya. Kedua boolean product_kaca_film/product_ppf
                    // inilah yang benar-benar dipakai untuk progress tahap
                    // (Booking::stageColumnFor()), diturunkan otomatis dari
                    // pilihan ini — tidak ada toggle terpisah lagi.
                    // Key opsi HARUS persis sama dengan string yang dikirim
                    // mobile app (lihat SERVICE_TYPES di app/booking/index.tsx)
                    // — service_type cuma kolom string biasa, jadi kalau
                    // key-nya beda, value booking dari app tidak match opsi
                    // manapun dan select tampil kosong.
                    Forms\Components\Select::make('service_type')
                        ->label('Jenis Layanan')
                        ->options([
                            'Kaca Film (Window Film)'                      => 'Kaca Film (Window Film)',
                            'Pelindung Cat (PPF)'                          => 'Pelindung Cat (PPF)',
                            'Kaca Film (Window Film), Pelindung Cat (PPF)' => 'Kaca Film + PPF',
                            'Konsultasi Produk'                            => 'Konsultasi Produk',
                            'Klaim Garansi'                                => 'Klaim Garansi',
                            'Lainnya'                                      => 'Lainnya (isi di catatan)',
                        ])
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                            $isPpf = in_array($state, ['Pelindung Cat (PPF)', 'Kaca Film (Window Film), Pelindung Cat (PPF)'], true);

                            $set('product_kaca_film', in_array($state, ['Kaca Film (Window Film)', 'Kaca Film (Window Film), Pelindung Cat (PPF)'], true));
                            $set('product_ppf', $isPpf);

                            // Cuma auto-isi default kalau staff belum
                            // sentuh field durasi manual (kosong ATAU masih
                            // salah satu nilai default) — supaya ganti
                            // Jenis Layanan tidak diam-diam menimpa durasi
                            // yang sudah sengaja diedit staff.
                            $current = $get('duration_days');
                            if (! $current || in_array((int) $current, [Booking::DEFAULT_DURATION_DAYS_PPF, Booking::DEFAULT_DURATION_DAYS_DEFAULT], true)) {
                                $set('duration_days', $isPpf ? Booking::DEFAULT_DURATION_DAYS_PPF : Booking::DEFAULT_DURATION_DAYS_DEFAULT);
                            }
                        })
                        // Booking dari mobile app (source=app) sudah dipilih
                        // sendiri oleh customer — tampilkan apa adanya, jangan
                        // bisa diubah staff. Booking walk-in/WhatsApp (staff
                        // yang input manual) tetap bisa dipilih seperti biasa.
                        ->disabled(fn (Forms\Get $get) => $get('source') === 'app')
                        ->dehydrated()
                        ->helperText(fn (Forms\Get $get) => $get('source') === 'app'
                            ? 'Dipilih oleh customer lewat mobile app — tidak bisa diubah di sini.'
                            : null)
                        ->required(),

                    Forms\Components\DatePicker::make('preferred_date')
                        ->label('Tanggal Diinginkan')
                        ->required()
                        ->live()
                        ->minDate(fn () => $form->getOperation() === 'create' ? now() : null),

                    Forms\Components\TextInput::make('preferred_time')
                        ->label('Jam Diinginkan')
                        ->placeholder('Contoh: 09:00')
                        ->maxLength(50),

                    Forms\Components\TextInput::make('duration_days')
                        ->label('Lama Pengerjaan (hari)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(14)
                        ->default(Booking::DEFAULT_DURATION_DAYS_DEFAULT)
                        ->live()
                        ->required()
                        ->helperText('Berapa hari mobil ini makan slot instalasi (dipakai cek kapasitas). Default PPF 3 hari, lainnya 1 hari — sesuaikan kalau instalasi ini diperkirakan lebih cepat/lama.'),

                    // Default-nya diambil dari setting toko
                    // (install_capacity_per_day, lihat StoreResource), tapi
                    // field ini SENDIRI tidak disimpan ke database — staff
                    // boleh ubah sesaat kalau kapasitas hari itu beda dari
                    // biasanya (mis. 1 installer izin) TANPA mengubah
                    // setting toko permanen. Lihat
                    // CreateBooking::mutateFormDataBeforeCreate()/
                    // EditBooking::mutateFormDataBeforeSave() (dibuang dari
                    // $data sebelum sampai ke Booking::create()/update()).
                    Forms\Components\TextInput::make('capacity_per_day')
                        ->label('Kapasitas Instalasi / Hari')
                        ->numeric()
                        ->minValue(1)
                        ->default(fn (Forms\Get $get) => Store::find($get('store_id'))?->install_capacity_per_day ?? 3)
                        ->live()
                        ->required()
                        ->helperText('Default dari setting toko — bisa diubah sesaat kalau kapasitas hari ini beda (tidak mengubah setting toko).'),

                    // Live preview sisa slot instalasi per tanggal — dihitung
                    // dari booking 'confirmed' lain yang tanggal kerjanya
                    // overlap (lihat Booking::confirmedOverlapCount()).
                    // Booking 'pending' SENGAJA tidak ikut dihitung di sini
                    // supaya staff tahu berapa slot BENAR-BENAR tersisa saat
                    // mau approve — bukan seolah-olah sudah penuh cuma
                    // karena banyak yang masih menunggu triase.
                    Forms\Components\Placeholder::make('capacity_preview')
                        ->label('Sisa Slot Instalasi')
                        ->columnSpanFull()
                        ->content(function (Forms\Get $get, ?Booking $record) {
                            $storeId = $get('store_id');
                            $dateStr = $get('preferred_date');

                            if (! $storeId || ! $dateStr) {
                                return 'Pilih toko & tanggal dulu untuk lihat sisa slot.';
                            }

                            $capacity = max(1, (int) ($get('capacity_per_day') ?: 3));
                            $duration = max(1, (int) ($get('duration_days') ?: 1));
                            $store = Store::find($storeId);
                            $day = \Illuminate\Support\Carbon::parse($dateStr);

                            // Hari libur toko dilewati, tidak dihitung
                            // sebagai hari kerja — sama seperti
                            // Booking::fullDatesInRange() yang benar-benar
                            // menegakkan ini saat approve.
                            $lines = [];
                            $counted = 0;
                            $daysScanned = 0;

                            // Batas 90 hari kalender — jaring pengaman
                            // kalau data Jam Operasional toko salah isi
                            // (mis. semua hari ditandai libur), supaya
                            // Placeholder ini tidak nge-hang halaman admin.
                            while ($counted < $duration && $daysScanned < 90) {
                                $daysScanned++;

                                if ($store?->isClosedOn($day)) {
                                    $lines[] = $day->format('d M Y') . ': Toko libur';
                                    $day = $day->copy()->addDay();
                                    continue;
                                }

                                $counted++;
                                $used = Booking::confirmedOverlapCount($storeId, $day, $record?->id);
                                $remaining = max(0, $capacity - $used);

                                $lines[] = $day->format('d M Y') . ": {$remaining}/{$capacity} slot"
                                    . ($remaining <= 0 ? ' — PENUH' : '');

                                $day = $day->copy()->addDay();
                            }

                            if ($counted < $duration) {
                                $lines[] = 'Toko ini sepertinya tutup terus-menerus — cek lagi Jam Operasional toko di menu Toko.';
                            }

                            return new \Illuminate\Support\HtmlString(implode('<br>', array_map('e', $lines)));
                        }),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->placeholder('Tipe mobil, warna, permintaan khusus, dll.')
                        ->columnSpanFull(),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'pending'   => 'Menunggu Konfirmasi',
                            'confirmed' => 'Dikonfirmasi',
                            'completed' => 'Selesai',
                            'cancelled' => 'Dibatalkan',
                        ])
                        ->default('confirmed')
                        ->required(),

                    // Tanggal reminder servis berkala — ditentukan MANUAL oleh
                    // store manager per booking (interval beda-beda per kasus,
                    // mis. Kaca Film vs PPF), bukan dihitung otomatis oleh
                    // sistem. Command terjadwal (SendServiceReminders) yang
                    // otomatis kirim WhatsApp+Push+Email begitu tanggal ini tiba.
                    Forms\Components\DatePicker::make('next_service_reminder_at')
                        ->label('Tanggal Reminder Servis')
                        ->helperText('Kosongkan kalau belum perlu reminder. Sistem otomatis kirim WhatsApp/Push/Email ke customer pada tanggal ini.')
                        ->minDate(now())
                        ->native(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking_number')
                    ->label('No. Booking')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('display_name')
                    ->label('Customer')
                    ->getStateUsing(fn (Booking $record): string =>
                        $record->customer_name
                            ?? $record->customer?->name
                            ?? $record->customer?->email
                            ?? '—'
                    )
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('customer_name', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($q) => $q
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                        )
                    ),

                Tables\Columns\TextColumn::make('phone_number')
                    ->label('No. Telepon')
                    ->getStateUsing(fn (Booking $record): string =>
                        $record->phone_number ?? $record->customer?->phone_number ?? '—'
                    )
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('source')
                    ->label('Asal')
                    ->colors([
                        'primary' => 'app',
                        'success' => 'whatsapp',
                        'gray'    => 'walk_in',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'app'      => '📱 App',
                        'whatsapp' => '💬 WA',
                        'walk_in'  => '🚶 Walk-in',
                        default    => $state,
                    }),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('installers.name')
                    ->label('Installer')
                    ->badge()
                    ->placeholder('Belum ditugaskan')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('service_type')
                    ->label('Layanan')
                    ->limit(25),

                Tables\Columns\TextColumn::make('preferred_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable()
                    ->description(fn (Booking $record) => $record->duration_days > 1
                        ? "{$record->duration_days} hari (s/d {$record->end_date?->format('d M')})"
                        : null),

                Tables\Columns\TextColumn::make('preferred_time')
                    ->label('Jam')
                    ->placeholder('—'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'info'    => 'confirmed',
                        'success' => 'completed',
                        'danger'  => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'   => 'Menunggu',
                        'confirmed' => 'Dikonfirmasi',
                        'completed' => 'Selesai',
                        'cancelled' => 'Dibatalkan',
                        default     => $state,
                    }),

                Tables\Columns\TextColumn::make('next_service_reminder_at')
                    ->label('Reminder Servis')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->description(fn ($record) => $record->next_service_reminder_at
                        ? ($record->service_reminder_sent_at ? 'Sudah terkirim' : 'Belum terkirim')
                        : null)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Tables\Grouping\Group::make('preferred_date')
                    ->label('Tanggal')
                    ->date('l, d M Y')
                    ->collapsible(),
            ])
            ->defaultGroup('preferred_date')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending'   => 'Menunggu',
                        'confirmed' => 'Dikonfirmasi',
                        'completed' => 'Selesai',
                        'cancelled' => 'Dibatalkan',
                    ]),

                Tables\Filters\SelectFilter::make('source')
                    ->label('Asal Booking')
                    ->options([
                        'app'      => 'Mobile App',
                        'whatsapp' => 'WhatsApp',
                        'walk_in'  => 'Walk-in',
                    ]),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),

                Tables\Filters\Filter::make('upcoming')
                    ->label('Hanya yang akan datang')
                    ->query(fn (Builder $query) => $query->whereDate('preferred_date', '>=', today()))
                    ->default(),
            ])
            ->actions([
                // Nominal transaksi & kode referral partner SENGAJA diproses
                // bareng di sini (Filament), bukan saat "Selesaikan Booking"
                // di mobile app lagi — dipisah supaya staff toko fokus ke
                // operasional instalasi, sementara nominal & poin referral
                // dicek/diinput belakangan (mis. oleh kasir).
                Tables\Actions\Action::make('process_referral')
                    ->label('Proses Referral')
                    ->icon('heroicon-o-gift')
                    ->color('warning')
                    ->visible(fn (Booking $record) => $record->status === 'completed')
                    ->form([
                        Forms\Components\TextInput::make('transaction_amount')
                            ->label('Nominal Transaksi')
                            ->numeric()
                            ->minValue(0)
                            ->default(fn (Booking $record) => $record->transaction_amount)
                            ->helperText('Dipakai untuk menghitung poin referral (1 poin / Rp10.000).'),

                        Forms\Components\TextInput::make('referral_code')
                            ->label('Kode Referral Partner')
                            ->maxLength(20)
                            // Prioritas: kode yang sudah tersimpan di booking ini
                            // dulu; kalau belum ada, coba ambil dari penanda
                            // "Direferensikan Partner" yang admin catat manual di
                            // akun customer (lihat CustomerResource::setReferralAction())
                            // — supaya staff tidak perlu ingat/ketik ulang manual.
                            ->default(fn (Booking $record) => $record->referral_code
                                ?? $record->customer?->referredByPartner?->referral_code)
                            ->helperText('Kosongkan & simpan untuk membatalkan/menghapus kode referral yang salah input.'),
                    ])
                    ->action(function (Booking $record, array $data) {
                        $record->update([
                            'transaction_amount' => $data['transaction_amount'] !== '' ? $data['transaction_amount'] : null,
                            'referral_code'       => $data['referral_code'] ?: null,
                        ]);

                        $messages = [];
                        $service = app(ReferralPointService::class);

                        // 1. Referral Partner (bisnis mitra) — dari kode yang
                        // diinput manual di form ini.
                        if ($data['referral_code']) {
                            try {
                                $partner = $service->awardForBooking($record->fresh());
                                if ($partner) {
                                    $messages[] = "Poin diberikan ke partner {$partner->business_name}.";
                                }
                            } catch (RuntimeException $e) {
                                $messages[] = '⚠️ Partner: ' . $e->getMessage();
                            }
                        }

                        // 2. Bonus "ajak teman" antar-customer — independen,
                        // otomatis dari referred_by_customer_id yang sudah
                        // tersimpan di akun customer sejak Complete Profile,
                        // tidak butuh kode diinput ulang di sini.
                        try {
                            $referrer = $service->awardForCustomerReferral($record->fresh());
                            if ($referrer) {
                                $messages[] = "Bonus ajak teman diberikan ke {$referrer->name}.";
                            }
                        } catch (RuntimeException $e) {
                            $messages[] = '⚠️ Ajak Teman: ' . $e->getMessage();
                        }

                        Notification::make()
                            ->title($messages ? implode(' ', $messages) : 'Nominal transaksi disimpan.')
                            ->success()
                            ->send();
                    }),

                // Kirim reminder maintenance/servis (WA+Push+Email) kapan
                // saja tanpa menunggu tanggal `next_service_reminder_at`
                // terjadwal — dipakai store manager begitu instalasi
                // PPF/Kaca Film selesai dan customer perlu diingatkan
                // jadwal servis berkala. Logic pengiriman sama persis
                // dengan command terjadwal (lihat ServiceReminderService).
                Tables\Actions\Action::make('send_maintenance_reminder')
                    ->label('Kirim Pengingat')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('info')
                    ->visible(fn (Booking $record) => $record->status === 'completed'
                        && ($record->customer_id || $record->phone_number))
                    ->requiresConfirmation()
                    ->modalHeading('Kirim Pengingat Maintenance')
                    ->modalDescription(fn (Booking $record) => $record->service_reminder_sent_at
                        ? 'Pengingat sebelumnya terkirim pada ' . $record->service_reminder_sent_at->format('d M Y H:i') . '. Kirim lagi sekarang?'
                        : 'Kirim pengingat servis berkala ke customer lewat WhatsApp, Push, dan Email?')
                    ->action(function (Booking $record) {
                        $results = app(ServiceReminderService::class)->sendFor($record, force: true);
                        $sent = array_keys(array_filter($results));

                        $notification = Notification::make()->title($sent
                            ? 'Pengingat terkirim lewat: ' . implode(', ', $sent)
                            : 'Pengingat gagal terkirim di semua kanal — cek kontak customer & konfigurasi WhatsApp.');

                        $sent ? $notification->success()->send() : $notification->danger()->send();
                    }),

                Tables\Actions\EditAction::make(),
                // Booking yang sudah diproses referral (partner_id terisi)
                // sudah menambah saldo poin Partner yang cash-convertible —
                // saldo itu TIDAK ikut hilang kalau booking-nya dihapus
                // (PartnerPointTransaction pakai reference_id lepas, bukan
                // FK sungguhan), jadi hapus booking bisa menghilangkan
                // jejak audit tanpa membatalkan saldo. Cuma super_admin/
                // direksi yang boleh hapus booking.
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ])
            ->headerActions([
                Tables\Actions\Action::make('export')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->action(function () {
                        $storeId = auth()->user()?->isFullAccess()
                            ? null
                            : auth()->user()?->store_id;

                        return Excel::download(
                            new BookingExport($storeId),
                            'booking-' . now()->format('Ymd') . '.xlsx'
                        );
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->isFullAccess()),
                ]),
            ])
            ->defaultSort('preferred_date');
    }

    public static function getRelations(): array
    {
        return [
            BookingResource\RelationManagers\MessagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'edit'   => Pages\EditBooking::route('/{record}/edit'),
        ];
    }
}
