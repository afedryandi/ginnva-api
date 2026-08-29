<?php

namespace App\Filament\Resources;

use App\Exports\BookingExport;
use App\Filament\Resources\BookingResource\Pages;
use App\Mail\BookingWatcherAssignedMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\ReferralPointService;
use App\Services\ServiceReminderService;
use App\Support\PhoneFormatter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Booking';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Booking Instalasi';

    protected static ?string $modelLabel = 'Booking';

    protected static ?string $pluralModelLabel = 'Booking Instalasi';

    protected static ?string $navigationBadgeColor = 'warning';

    public static function getEloquentQuery(): Builder
    {
        // Eager-load 'store' — kolom tabel 'preferred_date' memanggil
        // accessor end_date() yang mengakses $this->store per baris; tanpa
        // ini tiap baris booking yang tampil di listing memicu 1 query
        // tambahan (N+1). Lihat audit modul Booking 2026-08-27.
        $query = parent::getEloquentQuery()->with('store');
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
                        // Diwajibkan — SEBELUMNYA booking manual (WA/walk-in)
                        // bisa disimpan tanpa nomor telepon sama sekali,
                        // padahal pengingat servis berkala (WA reminder,
                        // lihat ServiceReminderService) butuh nomor ini.
                        // Tanpa itu, tombol "Kirim Pengingat Maintenance"
                        // gagal total di kanal WhatsApp untuk booking ini.
                        ->required(fn (Forms\Get $get) => in_array($get('source'), ['whatsapp', 'walk_in']))
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
                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get) {
                            $set('installers', []);
                            // Ganti toko SELALU timpa (tidak lewat guard
                            // regenerateCapacitiesIfDatesChanged) — kapasitas
                            // default beda per toko walau kebetulan susunan
                            // tanggalnya sama, jadi baris lama tetap tidak
                            // relevan lagi.
                            $set('capacities', static::defaultCapacityRows($get));
                        }),

                    Forms\Components\Select::make('installers')
                        ->label('Installer Bertugas')
                        // SEBELUMNYA ->relationship('installers','name')
                        // digabung ->options() custom terpisah — TERNYATA
                        // cacat: ->options() cuma dipakai untuk daftar
                        // pilihan AWAL (sebelum diketik), sedangkan
                        // pencarian-sambil-ketik DAN label untuk installer
                        // yang sudah tertaut balik pakai jalur
                        // ->relationship() MENTAH (query ke tabel `users`
                        // TANPA filter role/toko/status Teknisi sama
                        // sekali) — staff bisa cari & pilih direksi/
                        // partner, bahkan installer toko lain. Dikonfirmasi
                        // dari laporan user 2026-08-28 (screenshot: opsi
                        // sampai ke direksi/partner, dan installer toko
                        // lain kelihatan).
                        //
                        // Fix yang benar: modifyQueryUsing() DI DALAM
                        // relationship() — satu query yang sama dipakai
                        // konsisten untuk pilihan awal, pencarian, MAUPUN
                        // resolusi label installer yang sudah tertaut,
                        // tidak ada jalur kedua yang lolos filter.
                        ->relationship(
                            name: 'installers',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn (Builder $query, Forms\Get $get) => $query
                                ->where('store_id', $get('store_id'))
                                ->whereHas('roles', fn ($q) => $q->where('name', 'installer'))
                                ->whereDoesntHave('technician', fn ($q) => $q->whereIn('status', ['pending_review', 'inactive']))
                                ->with('technician'),
                        )
                        ->getOptionLabelFromRecordUsing(function (User $record) {
                            $levelLabels = [
                                'intermediate' => 'Intermediate',
                                'advanced'     => 'Advanced',
                                'mentor'       => 'Mentor',
                            ];

                            return $record->technician?->level
                                ? "{$record->name} ({$levelLabels[$record->technician->level]})"
                                : $record->name;
                        })
                        ->helperText('Bisa pilih lebih dari 1 installer. Installer hanya bisa lihat & chat teks di booking yang ditugaskan ke dirinya di mobile app. Installer dari toko lain, atau berstatus "Menunggu Review"/"Nonaktif" di roster Teknisi, tidak muncul di sini.')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->disabled(fn (Forms\Get $get) => ! $get('store_id')),

                    // SEBELUMNYA cuma bisa diatur dari app staff (tombol
                    // Assignment di layar chat) — ditambahkan di sini juga
                    // supaya staff tidak perlu buka app HP cuma untuk
                    // tugaskan pemantau. Daftar direksi tidak terikat toko
                    // (direksi bisa pantau booking toko mana pun), beda
                    // dari installers yang difilter per toko.
                    Forms\Components\Select::make('watchers')
                        ->label('Direksi Pemantau')
                        ->relationship('watchers', 'name')
                        ->helperText('Direksi yang ditugaskan memantau booking ini — notifikasi chat (push & email) cuma dikirim ke direksi yang dipilih di sini, bukan semua direksi.')
                        ->options(fn () => User::whereHas('roles', fn ($q) => $q->where('name', 'direksi'))
                            ->pluck('name', 'id')
                        )
                        ->multiple()
                        ->searchable(),

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
                        ->minDate(fn () => $form->getOperation() === 'create' ? now() : null)
                        ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => static::regenerateCapacitiesIfDatesChanged($get, $set)),

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
                        ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => static::regenerateCapacitiesIfDatesChanged($get, $set))
                        ->helperText('Berapa hari mobil ini makan slot instalasi (dipakai cek kapasitas). Default PPF 3 hari, lainnya 1 hari — sesuaikan kalau instalasi ini diperkirakan lebih cepat/lama.'),

                    // Info rentang tanggal TERMASUK hari libur — supaya
                    // staff tahu kenapa ada lompatan tanggal di daftar
                    // kapasitas di bawah (mis. 08 → 10 karena 09 libur),
                    // bukan dikira sistem salah hitung. Cuma informasi,
                    // TIDAK ada input di sini.
                    Forms\Components\Placeholder::make('date_range_preview')
                        ->label('Rentang Tanggal Pengerjaan')
                        ->columnSpanFull()
                        ->content(function (Forms\Get $get) {
                            $storeId = $get('store_id');
                            $dateStr = $get('preferred_date');

                            if (! $storeId || ! $dateStr) {
                                return 'Pilih toko & tanggal dulu.';
                            }

                            $duration = max(1, (int) ($get('duration_days') ?: 1));
                            $walk = Booking::calendarWalkWithClosedDays((int) $storeId, \Illuminate\Support\Carbon::parse($dateStr), $duration);

                            $lines = collect($walk['dates'])->map(function (array $row) {
                                $day = \Illuminate\Support\Carbon::parse($row['date']);

                                return $row['closed']
                                    ? $day->format('d M Y') . ': Toko libur'
                                    : $day->format('d M Y') . ': hari kerja';
                            })->all();

                            if (! $walk['complete']) {
                                $lines[] = 'Toko ini sepertinya tutup terus-menerus (>90 hari) — cek lagi Jam Operasional toko di menu Toko.';
                            }

                            return new \Illuminate\Support\HtmlString(implode('<br>', array_map('e', $lines)));
                        }),

                    // Kapasitas tim instalasi bisa BEDA-BEDA tiap tanggal
                    // (mis. 1 tim masih ngerjain mobil dari hari sebelumnya,
                    // atau installer izin) — makanya per-baris tanggal,
                    // bukan 1 angka global. Baris otomatis dibuat ulang
                    // (defaultCapacityRows()) tiap toko/tanggal/durasi
                    // berubah — SEMENTARA tidak ada cara pintar buat
                    // pertahankan angka yang sudah diedit staff kalau cuma
                    // sebagian tanggal yang berubah, staff perlu isi ulang.
                    // Field ini SENDIRI tidak disimpan ke database — dibuang
                    // dari $data sebelum sampai ke Booking::create()/
                    // update(), lihat CreateBooking/EditBooking.
                    Forms\Components\Repeater::make('capacities')
                        ->label('Kapasitas Instalasi per Tanggal')
                        ->columnSpanFull()
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->live()
                        ->default(fn (Forms\Get $get) => static::defaultCapacityRows($get))
                        ->schema([
                            Forms\Components\Hidden::make('date'),
                            Forms\Components\TextInput::make('capacity')
                                ->label(fn (Forms\Get $get, ?Booking $record) => static::capacityRowLabel($get('date'), $get('../../store_id'), $record?->id))
                                ->numeric()
                                ->minValue(1)
                                ->required(),
                        ])
                        ->helperText('Default dari setting toko — sesuaikan per tanggal kalau tim yang available beda dari biasanya. Tidak mengubah setting toko.'),

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
                        // SEBELUMNYA ->minDate(now()) divalidasi ULANG setiap
                        // kali form disimpan, termasuk saat staff sama sekali
                        // tidak menyentuh field ini. Begitu tanggal reminder
                        // yang sudah tersimpan lewat dari hari ini, SELURUH
                        // form gagal disimpan (bug: staff tidak bisa edit
                        // field lain sama sekali). minDate cuma masuk akal
                        // untuk mencegah staff MENGATUR tanggal baru ke masa
                        // lalu — kalau nilainya tidak diubah (tetap sama
                        // dengan yang di DB), jangan divalidasi ulang.
                        // Ditemukan & diperbaiki 2026-08-29.
                        ->minDate(fn (?Booking $record, Forms\Get $get) => optional($record)
                            ->next_service_reminder_at?->toDateString() === $get('next_service_reminder_at')
                            ? null
                            : now())
                        ->native(false),
                ]),
        ])
            // SEBELUMNYA booking yang statusnya sudah 'completed'/'cancelled'
            // (final, tidak bisa dibatalkan lagi — lihat guard action
            // 'cancel' baris ~888) TETAP bisa diedit bebas dari sini: field
            // Status, tanggal, installer, dll semua masih terbuka. Cuma
            // action "batalkan" yang punya guard, halaman Edit biasa tidak.
            // ->disabled() di level Form mem-cascade ke SEMUA komponen anak
            // (dokumentasi Filament v3: "Disabling parts of your form"),
            // jadi seluruh form otomatis read-only begitu record final —
            // tombol Simpan juga disembunyikan, lihat EditBooking::
            // getFormActions(). Ditemukan & diperbaiki 2026-08-29.
            ->disabled(fn (?Booking $record) => in_array($record?->status, ['completed', 'cancelled'], true));
    }

    /**
     * SEBELUMNYA tidak ada halaman View sama sekali — staff yang cuma mau
     * LIHAT detail booking (bukan ubah apa pun) tetap harus masuk ke form
     * Edit penuh, termasuk Repeater kapasitas per tanggal yang bisa
     * ke-utak-atik tanpa sengaja. Infolist read-only ini memisahkan
     * "lihat" dari "ubah" — sama pola dengan QuotationResource. Lihat
     * audit UI/UX Filament Booking 2026-08-27.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Informasi Booking')
                ->columns(3)
                ->schema([
                    TextEntry::make('booking_number')->label('No. Booking'),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => match ($state) {
                            'pending'   => 'Menunggu',
                            'confirmed' => 'Dikonfirmasi',
                            'completed' => 'Selesai',
                            'cancelled' => 'Dibatalkan',
                            default     => $state,
                        })
                        ->color(fn (string $state) => match ($state) {
                            'pending'   => 'warning',
                            'confirmed' => 'info',
                            'completed' => 'success',
                            'cancelled' => 'danger',
                            default     => 'gray',
                        }),
                    TextEntry::make('source')
                        ->label('Asal Booking')
                        ->formatStateUsing(fn (string $state) => match ($state) {
                            'app'      => '📱 Mobile App',
                            'whatsapp' => '💬 WhatsApp',
                            'walk_in'  => '🚶 Walk-in',
                            default    => $state,
                        }),
                    TextEntry::make('store.name')->label('Toko/Workshop')->placeholder('—'),
                    TextEntry::make('installers.name')->label('Installer Bertugas')->badge()->placeholder('Belum ditugaskan'),
                    TextEntry::make('watchers.name')->label('Direksi Pemantau')->badge()->placeholder('—'),
                ]),

            InfolistSection::make('Data Customer')
                ->columns(2)
                ->schema([
                    TextEntry::make('display_name')
                        ->label('Nama Customer')
                        ->state(fn (Booking $record) => $record->customer_name ?? $record->customer?->name ?? $record->customer?->email ?? '—'),
                    TextEntry::make('phone_number')
                        ->label('No. Telepon')
                        ->state(fn (Booking $record) => $record->phone_number ?? $record->customer?->phone_number ?? '—'),
                ]),

            InfolistSection::make('Detail Booking')
                ->columns(2)
                ->schema([
                    TextEntry::make('service_type')->label('Jenis Layanan'),
                    // BUG (500 error): ->date('d M Y') dipakai BARENGAN
                    // dengan ->state() yang sudah mengembalikan string
                    // terformat sendiri — Filament coba Carbon::parse()
                    // ULANG string yang sudah diformat (mis. "27 Aug 2025
                    // (3 hari, s/d 30 Aug 2025)"), gagal parse, throw
                    // exception. Cukup salah satu: ->state() saja karena
                    // sudah memformat manual. Ditemukan & diperbaiki
                    // 2026-08-27 setelah laporan 500 di /admin/bookings/{id}.
                    TextEntry::make('preferred_date')
                        ->label('Tanggal Diinginkan')
                        ->state(fn (Booking $record) => $record->duration_days > 1
                            ? $record->preferred_date->format('d M Y') . " ({$record->duration_days} hari, s/d " . $record->end_date?->format('d M Y') . ')'
                            : $record->preferred_date?->format('d M Y')),
                    TextEntry::make('preferred_time')->label('Jam Diinginkan')->placeholder('—'),
                    TextEntry::make('notes')->label('Catatan')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('next_service_reminder_at')
                        ->label('Reminder Servis')
                        ->date('d M Y')
                        ->placeholder('—')
                        ->helperText(fn (Booking $record) => $record->next_service_reminder_at
                            ? ($record->service_reminder_sent_at ? 'Sudah terkirim' : 'Belum terkirim')
                            : null),
                ]),
        ]);
    }

    /**
     * Baris default Repeater 'capacities' — 1 baris per tanggal KERJA
     * (hari libur toko dilewati, lihat Booking::workingDatesInRange())
     * dalam rentang lama pengerjaan, kapasitas default diambil dari
     * setting toko. Dipanggil ulang tiap toko/tanggal/durasi berubah.
     */
    private static function defaultCapacityRows(Forms\Get $get): array
    {
        return static::computeCapacityRows($get('store_id'), $get('preferred_date'), $get('duration_days'));
    }

    /**
     * Diekstrak dari defaultCapacityRows() supaya bisa dipanggil dengan
     * nilai mentah (bukan cuma dari runtime form Forms\Get) — dipakai
     * dari EditBooking::mutateFormDataBeforeFill() untuk isi Repeater
     * SAAT HALAMAN DIBUKA, bukan cuma lewat ->default() (yang TERNYATA
     * cuma jalan di form Create, TIDAK PERNAH dipanggil Filament untuk
     * form Edit record yang sudah ada) atau nunggu staff sentuh field
     * lain dulu (afterStateUpdated). SEBELUMNYA staff buka booking untuk
     * di-approve, Repeater kapasitas kosong sama sekali sampai staff
     * tidak sengaja sentuh field Tanggal/Durasi — kalau staff langsung
     * ubah Status ke Confirmed & Simpan tanpa sadar itu, validasi
     * cross-check (lihat CreateBooking/EditBooking) menolak submit
     * (BENAR, itu memang harus ditolak kalau kosong) tapi staff jadi
     * bingung "isi di mana" karena kolomnya memang tidak pernah muncul.
     * Ditemukan & diperbaiki 2026-08-28.
     */
    public static function computeCapacityRows($storeId, $dateStr, $duration): array
    {
        $duration = max(1, (int) ($duration ?: 1));

        if (! $storeId || ! $dateStr) {
            return [];
        }

        $defaultCapacity = Store::find($storeId)?->install_capacity_per_day ?: 3;

        try {
            $dates = Booking::workingDatesInRange((int) $storeId, \Illuminate\Support\Carbon::parse($dateStr), $duration);
        } catch (\Throwable $e) {
            return [];
        }

        return collect($dates)
            ->map(fn (string $date) => ['date' => $date, 'capacity' => $defaultCapacity])
            ->all();
    }

    /**
     * Timpa baris 'capacities' HANYA kalau susunan tanggalnya benar-benar
     * berubah — dipanggil dari afterStateUpdated() field preferred_date &
     * duration_days. Tanpa pengecekan ini, form yang punya BANYAK field
     * live() lain bisa memicu re-render yang diam-diam mereset angka
     * kapasitas yang sudah staff edit padahal rentang tanggalnya sama
     * persis (mis. re-render dari field tidak terkait).
     */
    private static function regenerateCapacitiesIfDatesChanged(Forms\Get $get, Forms\Set $set): void
    {
        $newRows = static::defaultCapacityRows($get);
        $currentDates = collect($get('capacities') ?? [])->pluck('date')->all();
        $newDates = collect($newRows)->pluck('date')->all();

        if ($newDates !== $currentDates) {
            $set('capacities', $newRows);
        }
    }

    /**
     * Kirim email "Anda ditugaskan memantau booking ini" ke direksi yang
     * BARU ditambahkan sebagai watcher — dipanggil dari CreateBooking/
     * EditBooking supaya perilakunya SAMA PERSIS dengan assign watcher
     * lewat app staff (Staff\BookingController::assignWatchers()), yang
     * sebelumnya cuma bisa dari situ. $newWatcherIds HARUS sudah di-diff
     * oleh pemanggil (watcher yang benar-benar baru, bukan yang sudah ada
     * sebelumnya) — method ini tidak menghitung ulang diff-nya sendiri.
     */
    public static function notifyNewWatchers(Booking $booking, array $newWatcherIds, string $assignedByName): void
    {
        if (empty($newWatcherIds)) {
            return;
        }

        $newWatchers = User::whereIn('id', $newWatcherIds)->get(['id', 'name', 'email']);

        foreach ($newWatchers as $watcher) {
            if (! $watcher->email) continue;

            // Kegagalan kirim email (mis. domain email watcher tidak
            // valid) tidak boleh bikin seluruh proses save booking gagal
            // — sama seperti penanganan di assignWatchers().
            try {
                Mail::to($watcher->email)->send(new BookingWatcherAssignedMail($booking, $assignedByName));
            } catch (\Exception $e) {
                Log::error('Gagal mengirim email pemberitahuan watcher booking (Filament)', [
                    'booking_id' => $booking->id,
                    'watcher_id' => $watcher->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Label baris kapasitas — tanggal + berapa booking 'confirmed' lain
     * yang sudah menempati tanggal itu, supaya staff tahu konteksnya
     * tanpa perlu hitung manual dari Placeholder ringkasan di bawahnya.
     */
    private static function capacityRowLabel(?string $dateStr, mixed $storeId, ?int $excludeBookingId): string
    {
        if (! $dateStr) {
            return 'Kapasitas';
        }

        $day = \Illuminate\Support\Carbon::parse($dateStr);
        $label = $day->format('d M Y (l)');

        if (! $storeId) {
            return $label;
        }

        $used = Booking::confirmedOverlapCount((int) $storeId, $day, $excludeBookingId);

        return "{$label} — {$used} booking confirmed";
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

                // Sebelumnya tidak ada cara cepat menghubungi customer dari
                // Filament sama sekali — staff yang kerja dari desktop
                // harus copy-paste nomor manual ke WhatsApp Web. Lihat
                // audit UI/UX Filament Booking 2026-08-27.
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('whatsapp')
                        ->label('WhatsApp')
                        ->icon('heroicon-o-chat-bubble-left-right')
                        ->color('success')
                        ->visible(fn (Booking $record) => filled($record->phone_number ?? $record->customer?->phone_number))
                        ->url(fn (Booking $record) => 'https://wa.me/' . PhoneFormatter::toWhatsAppNumber($record->phone_number ?? $record->customer?->phone_number))
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('call')
                        ->label('Telepon')
                        ->icon('heroicon-o-phone')
                        ->color('info')
                        ->visible(fn (Booking $record) => filled($record->phone_number ?? $record->customer?->phone_number))
                        ->url(fn (Booking $record) => 'tel:' . ($record->phone_number ?? $record->customer?->phone_number)),
                ])
                    ->label('Hubungi')
                    ->icon('heroicon-o-phone')
                    ->color('gray'),

                // Pembatalan TIDAK butuh pengecekan kapasitas sama sekali
                // (beda dari approve/confirm yang wajib lewat
                // fullDatesInRange() di CreateBooking/EditBooking) —
                // aksi ringan ini sengaja TIDAK mengganti alur Edit untuk
                // approve, cuma untuk cancel. Sama pola dengan
                // Staff\BookingController::cancel() di mobile: row-locked,
                // menolak status yang sudah final. Lihat audit UI/UX
                // Filament Booking 2026-08-27.
                Tables\Actions\Action::make('quickCancel')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Booking $record) => in_array($record->status, ['pending', 'confirmed'], true))
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan Booking?')
                    ->modalDescription('Booking ini akan ditandai Dibatalkan. Tindakan ini tidak membatalkan otomatis assignment installer/direksi yang sudah tersimpan.')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Alasan (opsional)')
                            ->maxLength(500),
                    ])
                    ->action(function (Booking $record, array $data) {
                        $alreadyFinalStatus = DB::transaction(function () use ($record, $data) {
                            $locked = Booking::where('id', $record->id)->lockForUpdate()->first();

                            if (in_array($locked->status, ['completed', 'cancelled'], true)) {
                                return $locked->status;
                            }

                            $notes = $locked->notes;
                            if (filled($data['reason'] ?? null)) {
                                $notes = trim(($notes ? $notes . "\n\n" : '') . "Dibatalkan: {$data['reason']}");
                            }

                            $locked->update(['status' => 'cancelled', 'notes' => $notes]);

                            return null;
                        });

                        if ($alreadyFinalStatus) {
                            Notification::make()
                                ->title("Booking ini sudah berstatus \"{$alreadyFinalStatus}\", tidak bisa dibatalkan lagi.")
                                ->danger()
                                ->send();
                            return;
                        }

                        Notification::make()->title('Booking dibatalkan.')->success()->send();
                    }),

                Tables\Actions\ViewAction::make(),
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
            'view'   => Pages\ViewBooking::route('/{record}'),
            'edit'   => Pages\EditBooking::route('/{record}/edit'),
        ];
    }
}
