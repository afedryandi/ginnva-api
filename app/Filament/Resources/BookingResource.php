<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BookingResource\Pages;
use App\Mail\BookingWatcherAssignedMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingPostingService;
use App\Services\ReferralPointService;
use App\Services\ServiceReminderService;
use App\Support\PhoneFormatter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Grid as InfolistGrid;
use Filament\Infolists\Components\Group as InfolistGroup;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Barryvdh\DomPDF\Facade\Pdf;
use RuntimeException;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $cluster = \App\Filament\Clusters\BookingCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 0-99 "Order & Lead".
    protected static ?string $navigationGroup = 'Order & Lead';

    protected static ?int $navigationSort = 1;

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
        // customer & installers ikut di-eager-load (diperbaiki 2026-10-02) --
        // kolom display_name dan installers.name memicu query per baris.
        $query = parent::getEloquentQuery()->with(['store', 'customer', 'installers', 'pendingRescheduleRequest', 'pendingCancellationRequest']);
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

    /**
     * SEBELUMNYA tidak ada canView()/canEdit()/canDelete()/canDeleteAny()
     * sama sekali di sini, dan tidak ada BookingPolicy terdaftar —
     * ViewAction/EditAction bawaan Filament otomatis terikat ke
     * canView()/canEdit(), yang tanpa Policy & tanpa override SELALU
     * FALSE untuk siapa pun (default-deny Laravel Gate, lihat audit
     * bug class yang sama di resource-resource lain sebelumnya).
     * Akibatnya tombol Lihat & Edit di tabel Booking — Resource paling
     * inti di seluruh sistem — TIDAK PERNAH muncul sama sekali untuk
     * SIAPA PUN, termasuk super_admin. DeleteAction/DeleteBulkAction
     * SUDAH punya ->visible(isFullAccess()) eksplisit di table(), tapi
     * itu TIDAK CUKUP — Filament tetap AND-kan dengan canDelete()/
     * canDeleteAny() bawaan yang defaultnya false, jadi tombol hapus
     * pun ikut tidak pernah muncul walau ->visible()-nya true.
     * canView()/canEdit() dibiarkan seluas canViewAny() (tidak ada
     * pembatasan tambahan yang pernah diniatkan untuk edit secara
     * spesifik), canDelete()/canDeleteAny() disamakan dengan guard
     * ->visible() yang SUDAH ada eksplisit di DeleteAction/
     * DeleteBulkAction (isFullAccess() saja).
     */
    public static function canView($record): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        // hasModuleAction('update', default TRUE) — pengecekan granular
        // (audit Majoo f64, 2026-09-23) ditambahkan DI ATAS pengecekan
        // lama, bukan pengganti. Default TRUE supaya akun yang belum
        // pernah diatur di "Hak Akses Detail" TIDAK kehilangan kemampuan
        // Ubah yang sudah biasa mereka pakai — cuma jadi restriktif kalau
        // admin secara eksplisit meng-uncheck "Ubah" untuk user itu.
        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, 'update', true);
    }

    public static function canDelete($record): bool
    {
        $user = auth()->user();

        // hasModuleAction('delete', default FALSE) — SEBELUMNYA cuma
        // isFullAccess()-only, jadi default-nya HARUS tetap tidak boleh
        // (bukan default TRUE spt canEdit di atas) supaya migrasi fitur
        // ini tidak diam-diam memberi kemampuan Hapus baru ke siapa pun
        // sebelum admin mencentangnya eksplisit di "Hak Akses Detail".
        return $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));
    }

    public static function canDeleteAny(): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));
    }

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->isFullAccess();

        return $form->schema([
            Forms\Components\Section::make('Data Dikunci')
                ->icon('heroicon-o-lock-closed')
                ->columns(1)
                ->visible(fn (?Booking $record) => $record?->status === 'confirmed')
                ->schema([
                    Forms\Components\Placeholder::make('lock_info')
                        ->hiddenLabel()
                        ->content('Booking sudah dikonfirmasi, jadi data inti (pelanggan, kendaraan, jenis layanan, produk, tanggal dan jam, toko) tidak bisa diubah di sini. Ganti jadwal lewat tombol Pindah Jadwal. Installer, catatan, dan status tetap bisa diubah.'),
                    Forms\Components\Toggle::make('core_unlocked')
                        ->label('Buka kunci data (akses penuh)')
                        ->helperText('Hanya untuk koreksi salah input. Alasan wajib dan tercatat di Riwayat Aktivitas.')
                        ->visible(fn () => (bool) auth()->user()?->isFullAccess())
                        ->live()
                        ->dehydrated(false),
                    Forms\Components\Textarea::make('unlock_reason')
                        ->label('Alasan membuka kunci')
                        ->visible(fn (Forms\Get $get) => (bool) $get('core_unlocked'))
                        ->required(fn (Forms\Get $get) => (bool) $get('core_unlocked'))
                        ->maxLength(500)
                        ->dehydrated(false),
                ]),

            // Dua kolom di layar lebar: kiri isi booking (pelanggan, kendaraan, detail), kanan sumber booking.
            Forms\Components\Grid::make(['default' => 1, 'lg' => 3])->schema([
                Forms\Components\Group::make()->columnSpan(['lg' => 2])->schema([
            Forms\Components\Section::make('Pelanggan')
                ->icon('heroicon-o-user')
                ->columns(['default' => 1, 'sm' => 2])
                ->schema([
                    // Booking dari app — pilih dari akun terdaftar
                    Forms\Components\Select::make('customer_id')->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()
                        ->label('Akun Pelanggan (App)')
                        ->columnSpanFull()
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
                    Forms\Components\TextInput::make('customer_name')->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()
                        ->label('Nama Pelanggan')
                        ->required(fn (Forms\Get $get) => in_array($get('source'), ['whatsapp', 'walk_in']))
                        ->visible(fn (Forms\Get $get) => in_array($get('source'), ['whatsapp', 'walk_in']))
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone_number')->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()
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

            Forms\Components\Section::make('Kendaraan')
                ->icon('heroicon-o-truck')
                ->description('Opsional. Booking dari aplikasi sudah terisi otomatis dari "Kendaraan Saya" milik pelanggan.')
                ->columns(['default' => 1, 'sm' => 2])
                ->schema([
                    Forms\Components\TextInput::make('vehicle_plate')->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()
                        ->label('Plat Nomor')
                        ->placeholder('B 1234 ABC')
                        ->maxLength(20)
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                            if (filled($value) && ! \App\Models\CustomerVehicle::isValidPlate(\App\Models\CustomerVehicle::normalizePlate((string) $value))) {
                                $fail('Format plat nomor tidak valid. Contoh: B 1234 ABC.');
                            }
                        })
                        ->dehydrateStateUsing(fn ($state) => filled($state)
                            ? \App\Models\CustomerVehicle::formatPlate(\App\Models\CustomerVehicle::normalizePlate((string) $state))
                            : null),
                    Forms\Components\TextInput::make('vehicle_brand')->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()->label('Merek')->maxLength(80),
                    Forms\Components\TextInput::make('vehicle_model')->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()->label('Model')->maxLength(120),
                    Forms\Components\TextInput::make('vehicle_variant')->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()->label('Varian')->maxLength(120),
                    Forms\Components\TextInput::make('vehicle_color')->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()->label('Warna')->maxLength(40),
                    Forms\Components\TextInput::make('vehicle_year')->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()->label('Tahun')->numeric()->minValue(1980)->maxValue((int) now()->year + 1),
                    Forms\Components\Select::make('vehicle_size')->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()
                        ->label('Ukuran Mobil')
                        ->options(array_combine(\App\Models\CustomerVehicle::SIZES, \App\Models\CustomerVehicle::SIZES))
                        ->placeholder('Belum ditentukan')
                        ->helperText('Menentukan harga acuan PPF dan Kaca Film. Kosongkan kalau belum tahu.'),
                    Forms\Components\Select::make('vehicle_power')->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()
                        ->label('Jenis Tenaga')
                        ->options(\App\Support\VehicleFormat::POWERS)
                        ->placeholder('Belum ditentukan')
                        ->helperText('Menentukan kolom SPK: BBM untuk bensin, Battery untuk listrik, keduanya untuk hybrid.'),
                ]),

            Forms\Components\Section::make('Toko & Penugasan')
                ->icon('heroicon-o-building-storefront')
                ->columns(1)
                ->schema([
                    Forms\Components\Select::make('store_id')
                        ->label('Toko / Workshop')
                        ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->live()
                        ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id)
                        ->disabled(fn (?Booking $record, Forms\Get $get) => ! $isSuperAdmin || self::coreLocked($record, $get))
                        ->dehydrated(fn () => (bool) $isSuperAdmin)
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('installers', [])),

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

                            $label = $record->technician?->level
                                ? "{$record->name} ({$levelLabels[$record->technician->level]})"
                                : $record->name;

                            // GAP DIPERBAIKI 2026-09-25 (audit Teknisi,
                            // "assignment tetap blind terhadap beban
                            // kerja") -- SEBELUMNYA staff cuma lihat level
                            // sertifikasi saat memilih installer, tidak
                            // tahu berapa banyak booking confirmed yang
                            // sudah dipegang installer itu SAAT INI (data
                            // ini sudah ada di Laporan Utilisasi Teknisi
                            // terpisah, tapi tidak pernah tampil di titik
                            // assignment itu sendiri). Dihitung ringan
                            // (COUNT, bukan tarik semua baris) -- daftar
                            // opsi ini sudah dibatasi installer 1 toko
                            // (biasanya belasan), bukan skala listing.
                            $activeJobs = Booking::whereHas('installers', fn ($q) => $q->where('users.id', $record->id))
                                ->where('status', 'confirmed')
                                ->count();

                            return $activeJobs > 0 ? "{$label} — {$activeJobs} job aktif" : $label;
                        })
                        ->helperText('Bisa pilih lebih dari satu installer. "X job aktif" = booking confirmed yang sedang dipegang installer itu. Installer toko lain dan yang nonaktif tidak muncul.')
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
                    //
                    // SEBELUMNYA ->relationship('watchers','name') digabung
                    // ->options() custom terpisah — CACAT YANG SAMA PERSIS
                    // dengan installers di atas (lihat catatan panjangnya):
                    // ->options() cuma dipakai untuk pilihan AWAL, pencarian-
                    // sambil-ketik & resolusi label watcher yang sudah
                    // tertaut balik pakai jalur ->relationship() MENTAH
                    // (query ke `users` TANPA filter role 'direksi' sama
                    // sekali) — staff bisa cari & pilih installer/partner
                    // sebagai "Direksi Pemantau". Fix sama: modifyQueryUsing()
                    // di dalam relationship(), bukan ->options() terpisah.
                    // Ditemukan saat audit susulan modul Booking 2026-09-07.
                    Forms\Components\Select::make('watchers')
                        ->label('Direksi Pemantau')
                        ->relationship(
                            name: 'watchers',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn (Builder $query) => $query
                                ->whereHas('roles', fn ($q) => $q->where('name', 'direksi')),
                        )
                        ->helperText('Direksi yang memantau booking ini. Notifikasi chat (push dan email) hanya dikirim ke direksi yang dipilih.')
                        ->multiple()
                        ->searchable()
                        ->preload(),

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
                    // "Konsultasi Produk" & "Klaim Garansi" dihapus dari opsi 2026-10-01
                    // (rencana user) -- Klaim Garansi pindah ke alur Garansi customer sendiri
                    // (lihat Bagian B rancangan "Klaim Garansi & Maintenance PPF"). Booking LAMA
                    // yang masih punya service_type salah satu dari 2 nilai ini akan tampil
                    // KOSONG di Select ini saat diedit (lihat catatan "select tampil kosong" di
                    // atas) -- data aslinya tetap utuh, cuma preselect-nya yang hilang, sengaja
                    // diterima sebagai trade-off minor untuk legacy record.
                ]),

            Forms\Components\Section::make('Layanan & Produk')
                ->icon('heroicon-o-sparkles')
                ->columns(1)
                ->schema([
                    // Jenis Layanan: pilihan ganda 4 jenis (sama seperti aplikasi pelanggan). `services` hanya penampung form; yang
                    // tersimpan adalah kolom penanda produk + teks service_type (label dipisah koma, urutan tetap).
                    Forms\Components\Hidden::make('service_type'),

                    Forms\Components\CheckboxList::make('services')
                        ->label('Jenis Layanan')
                        // Mengikuti menu Layanan Booking: hanya yang Tampil, urut sesuai Urutan; yang sudah terpilih tetap terlihat.
                        ->options(fn (?Booking $record, Forms\Get $get) => \App\Models\BookingServiceType::formOptions(array_values(array_unique([
                            ...(array) $get('services'),
                            ...collect(\App\Models\BookingServiceType::FLAGS)
                                ->filter(fn ($column) => (bool) $record?->{$column})
                                ->keys()
                                ->all(),
                        ]))))
                        ->columns(2)
                        ->afterStateHydrated(function (Forms\Components\CheckboxList $component, ?Booking $record) {
                            if ($record === null) {
                                return;
                            }

                            $component->state(collect(\App\Models\BookingServiceType::FLAGS)
                                ->filter(fn ($column) => (bool) $record->{$column})
                                ->keys()
                                ->values()
                                ->all());
                        })
                        ->disableOptionWhen(fn (string $value, Forms\Get $get) => in_array($value, ['detailing', 'premium_wash'], true)
                            && $get('ppf_scope') === Booking::PPF_SCOPE_FULL_BODY
                            && in_array('ppf', (array) $get('services'), true)
                            && ((bool) $get('ppf_exterior') || ! $get('ppf_interior')))
                        ->dehydrated(false)
                        ->required()
                        ->minItems(1)
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                            $keys = (array) $state;
                            $isPpf = in_array('ppf', $keys, true);

                            foreach (\App\Models\BookingServiceType::FLAGS as $key => $column) {
                                $set($column, in_array($key, $keys, true));
                            }

                            $set('service_type', self::serviceTypeLabel($keys));

                            if (! $isPpf) {
                                $set('ppf_exterior', false);
                                $set('ppf_interior', false);
                                $set('ppf_scope', null);
                                $set('filmProducts', self::withoutInteriorPart(self::withoutPpfParts((array) $get('filmProducts'))));
                            } elseif (! $get('ppf_exterior') && ! $get('ppf_interior')) {
                                // PPF baru dicentang: Eksterior terpilih sebagai awal (staf bisa menambah atau menggantinya dengan Interior).
                                $set('ppf_exterior', true);
                            }

                            // Cuma auto-isi default kalau staff belum
                            // sentuh field durasi manual (kosong ATAU masih
                            // salah satu nilai default) — supaya ganti
                            // Jenis Layanan tidak diam-diam menimpa durasi
                            // yang sudah sengaja diedit staff.
                            self::syncDuration($set, $get, $isPpf);
                        })
                        // Booking dari mobile app (source=app) sudah dipilih
                        // sendiri oleh customer — tampilkan apa adanya, jangan
                        // bisa diubah staff. Booking walk-in/WhatsApp (staff
                        // yang input manual) tetap bisa dipilih seperti biasa.
                        ->disabled(fn (?Booking $record, Forms\Get $get) => $get('source') === 'app' || self::coreLocked($record, $get))
                        ->helperText(fn (Forms\Get $get) => $get('source') === 'app'
                            ? 'Dipilih oleh pelanggan lewat mobile app — tidak bisa diubah di sini.'
                            : 'Centang semua yang dikerjakan. PPF Full Body sudah termasuk Detailing dan Premium Wash.'),

                    // Eksterior dan Interior boleh dipilih bersamaan (permintaan pemilik 2026-12-19). Eksterior: cakupan Full Body/Part dan bonus; Interior:
                    // satu paket 1 hari. ppf_area diturunkan otomatis oleh model dari dua penanda ini.
                    Forms\Components\Fieldset::make('PPF Eksterior / Interior')
                        ->columns(2)
                        ->visible(fn (Forms\Get $get) => (bool) $get('product_ppf'))
                        ->schema([
                            Forms\Components\Checkbox::make('ppf_exterior')->label('Eksterior')
                                ->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()
                                ->live()
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::onPpfAreaChanged($set, $get)),
                            Forms\Components\Checkbox::make('ppf_interior')->label('Interior')
                                ->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()
                                ->live()
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::onPpfAreaChanged($set, $get)),
                        ]),

                    Forms\Components\Select::make('ppf_scope')->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()
                        ->label('Bagian Panel Mobil (Eksterior)')
                        ->options([
                            Booking::PPF_SCOPE_FULL_BODY => 'Full Body (sudah termasuk Detailing + Premium Wash)',
                            Booking::PPF_SCOPE_PARTIAL   => 'Part (pilih panel)',
                        ])
                        ->placeholder('Pilih')
                        ->helperText('Full Body: Detailing dan Premium Wash ikut otomatis. Harga diisi manual saat membuat invoice.')
                        ->visible(fn (Forms\Get $get) => (bool) $get('product_ppf') && ((bool) $get('ppf_exterior') || ! $get('ppf_interior')))
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                            if ($state === Booking::PPF_SCOPE_FULL_BODY) {
                                $set('product_detailing', true);
                                $set('product_premium_wash', true);
                                $set('services', array_values(array_unique([...(array) $get('services'), 'detailing', 'premium_wash'])));
                                $set('service_type', self::serviceTypeLabel($get('services')));
                            }

                            if ($state !== Booking::PPF_SCOPE_PARTIAL) {
                                $set('filmProducts', self::withoutPpfParts((array) $get('filmProducts')));
                            }
                        }),

                    // Produk dipilih langsung per bagian (tanpa "model produk utama" terpisah). Kolom bookings.film_product_id (dipakai laporan,
                    // Memo Barang, dan Catat Penjualan) diisi otomatis dari produk pertama di daftar ini: BookingResource::syncMainProduct().
                    // Produk TAMBAHAN per bagian kendaraan -- keputusan
                    // atasan 2026-09-19 (Topik 3): 1 booking BISA pakai
                    // lebih dari 1 produk, dicatat staff toko saat booking
                    // dikonfirmasi. SENGAJA opsional & terpisah dari
                    // film_product_id (produk utama) di atas -- lihat
                    // catatan lengkap di Booking::filmProducts().
                    Forms\Components\Repeater::make('filmProducts')
                        ->relationship()
                        ->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))
                        ->label('Produk per Bagian')
                        ->addActionLabel('+ Tambah produk')
                        // Booking lama yang baru punya satu produk (kolom film_product_id) tampil sebagai satu baris, supaya tetap terlihat dan bisa diubah.
                        ->afterStateHydrated(function (Forms\Components\Repeater $component, ?Booking $record, $state) {
                            if ($record === null || filled($state) || blank($record->film_product_id)) {
                                return;
                            }

                            $type = $record->filmProduct?->product_type;
                            $position = match ($type) { 'ppf' => Booking::PPF_ALL, 'window_film' => Booking::WF_ALL, default => null };

                            if ($position !== null) {
                                $component->state([['position' => $position, 'film_product_id' => $record->film_product_id]]);
                            }
                        })
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->columns(2)
                        ->columnSpanFull()
                        ->visible(fn (Forms\Get $get) => self::partOptions((array) $get('services'), $get('ppf_scope'), (bool) ($get('ppf_exterior') || ! $get('ppf_interior')), (bool) $get('ppf_interior')) !== [])
                        ->itemLabel(fn (array $state): ?string => filled($state['position'] ?? null) ? $state['position'] : 'Bagian baru')
                        ->schema([
                            Forms\Components\Select::make('position')
                                ->label('Bagian')
                                ->options(fn (Forms\Get $get) => self::partOptions((array) $get('../../services'), $get('../../ppf_scope'), (bool) ($get('../../ppf_exterior') || ! $get('../../ppf_interior')), (bool) $get('../../ppf_interior')))
                                ->placeholder('Pilih bagian')
                                ->native(false)
                                ->required()
                                ->live(),
                            Forms\Components\Select::make('film_product_id')
                                ->label('Produk')
                                ->options(fn (Forms\Get $get) => \App\Models\FilmProduct::query()
                                    ->where('is_active', true)
                                    ->where('product_type', self::productTypeForPart((string) $get('position')))
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn ($product) => [$product->id => "{$product->sku} — {$product->name}"]))
                                ->searchable()
                                ->preload()
                                ->required(),
                        ])
                        ->helperText('Pilih bagian, lalu produknya. "Seluruh Bagian PPF" atau "Seluruh Kaca" kalau satu produk dipakai di semua bagian; boleh lebih dari satu produk. PPF Part: pilih tiap panel.'),

                    // Detailing dijual dua-duanya (sendiri / tambahan pada
                    // booking film) — diminta 2026-09-10. Toggle terpisah,
                    // TIDAK lewat service_type, supaya tidak meledakkan
                    // kombinasi opsi & tidak mengganggu mesin tahap film.
                    // Dipakai auto-isi Memo Barang dari Master Resep detailing.
                    // Detailing & Premium Wash kini dipilih lewat pilihan "Jenis Layanan" di atas; kolom penandanya diisi otomatis.
                    Forms\Components\Hidden::make('product_kaca_film')->default(false),
                    Forms\Components\Hidden::make('product_ppf')->default(false),
                    Forms\Components\Hidden::make('product_detailing')->default(false),
                    Forms\Components\Hidden::make('product_premium_wash')->default(false),

                ]),

            Forms\Components\Section::make('Jadwal Pengerjaan')
                ->icon('heroicon-o-calendar-days')
                ->columns(['default' => 1, 'sm' => 3])
                ->schema([
                    Forms\Components\DatePicker::make('preferred_date')->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()
                        ->label('Tanggal Diinginkan')
                        ->required()
                        ->live()
                        // startOfDay(), BUKAN now() polos -- now() ikut
                        // bawa jam-menit-detik saat itu, jadi begitu lewat
                        // tengah malam, pilih tanggal HARI INI pun gagal
                        // validasi (nilai date-picker dianggap jam 00:00,
                        // selalu "sebelum" now() yang sudah lewat jam 00:00).
                        // Ditemukan lewat laporan user 2026-09-15.
                        ->minDate(fn () => $form->getOperation() === 'create' ? now()->startOfDay() : null),

                    // Alasan pindah jadwal booking confirmed -- wajib kalau
                    // tanggalnya diubah (dicek di EditBooking), dikirim ke
                    // customer (2026-10-03). Tidak disimpan di kolom booking.
                    // Token versi form: updated_at saat form dibuka, dipakai
                    // EditBooking untuk menolak simpan kalau booking sudah
                    // berubah dari tempat lain (2026-10-03).
                    Forms\Components\Hidden::make('form_version')
                        ->afterStateHydrated(fn (Forms\Components\Hidden $component, ?Booking $record) => $component->state($record?->updated_at?->timestamp)),

                    Forms\Components\TextInput::make('preferred_time')->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()
                        ->label('Jam Diinginkan')
                        ->placeholder('Contoh: 09:00')
                        ->maxLength(50)
                        // Layanan ringan (detailing/premium wash) memakai slot jam; layanan berat tetap teks bebas (Fase 2).
                        ->datalist(fn () => Booking::lightSlots())
                        ->helperText(function (Forms\Get $get, ?Booking $record) {
                            $flags = collect(\App\Models\BookingServiceType::FLAGS)->mapWithKeys(fn (string $column) => [$column => (bool) $get($column)])->all();
                            if (! Booking::flagsAreLightOnly($flags)) {
                                return null;
                            }

                            $storeId = $get('store_id');
                            $date = $get('preferred_date');
                            if (! $storeId || ! $date) {
                                return 'Layanan ringan memakai slot jam. Pilih toko dan tanggal untuk melihat jam yang tersedia.';
                            }

                            $slots = collect(Booking::lightSlotAvailability((int) $storeId, \Illuminate\Support\Carbon::parse($date), $record?->id));

                            return 'Layanan ringan memakai slot jam. Tersedia: '
                                . ($slots->where('available', true)->pluck('slot')->implode(', ') ?: 'tidak ada')
                                . ($slots->where('available', false)->isNotEmpty() ? '. Penuh: ' . $slots->where('available', false)->pluck('slot')->implode(', ') : '') . '.';
                        })
                        ->rules([
                            fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                if (blank($value)) {
                                    return;
                                }

                                $flags = collect(\App\Models\BookingServiceType::FLAGS)->mapWithKeys(fn (string $column) => [$column => (bool) $get($column)])->all();

                                if (Booking::flagsAreLightOnly($flags) && ! in_array($value, Booking::lightSlots(), true)) {
                                    $fail('Untuk layanan ringan pilih salah satu jam: ' . implode(', ', Booking::lightSlots()) . '.');
                                }
                            },
                        ]),

                    Forms\Components\TextInput::make('duration_days')->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()
                        ->label('Lama Pengerjaan (hari)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(14)
                        ->default(Booking::DEFAULT_DURATION_DAYS_DEFAULT)
                        ->live()
                        ->required()
                        ->helperText('Berapa hari mobil ini memakai slot instalasi (untuk cek kapasitas). Perkiraan awal PPF 4 hari, lainnya 1 hari. Setelah SPK, lama sebenarnya bisa diatur dari SPK ("Atur Lama Pengerjaan").'),

                    Forms\Components\Textarea::make('reschedule_reason')
->label(fn (?Booking $record) => $record?->status === 'confirmed' ? 'Alasan Pindah Jadwal' : 'Alasan Pindah Jadwal (opsional)')
                        ->helperText('Wajib kalau tanggal booking yang sudah dikonfirmasi diubah; opsional untuk booking yang masih menunggu. Dikirim ke pelanggan.')
                        ->maxLength(500)
                        ->visible(fn (?Booking $record, Forms\Get $get) => $record?->status === 'pending' || ($record?->status === 'confirmed' && ! self::coreLocked($record, $get)))
                        ->columnSpanFull(),

                    // Redesain 2026-09-25 (diminta user langsung, audit
                    // Booking Instalasi) — SEBELUMNYA ada Repeater
                    // 'capacities' di sini yang mewajibkan staff MENGETIK
                    // ULANG angka kapasitas dari nol setiap kali approve
                    // booking yang menyentuh suatu tanggal (default toko,
                    // tapi bisa diedit bebas tanpa disimpan ke mana pun —
                    // staff lain bisa isi angka berbeda untuk tanggal yang
                    // sama tanpa sistem menegur). Sekarang kapasitas
                    // adalah SATU sumber kebenaran (Store::install_capacity_
                    // per_day, atau override lewat StoreCapacityOverride —
                    // dikelola di kalender kapasitas, lihat
                    // App\Filament\Pages\CapacityCalendar), placeholder ini
                    // MURNI baca (tidak ada input sama sekali) — validasi
                    // penuh tetap terjadi otomatis saat submit lewat
                    // Booking::fullDatesInRange() (CreateBooking/EditBooking),
                    // staff tidak perlu isi apa pun secara manual lagi.
                    Forms\Components\Placeholder::make('date_range_preview')
                        ->label('Waktu dan Slot Tersedia')
                        ->columnSpanFull()
                        ->content(function (Forms\Get $get, ?Booking $record) {
                            $storeId = $get('store_id');
                            $dateStr = $get('preferred_date');

                            if (! $storeId || ! $dateStr) {
                                return 'Pilih toko & tanggal dulu.';
                            }

                            $duration = max(1, (int) ($get('duration_days') ?: 1));
                            $walk = Booking::calendarWalkWithClosedDays((int) $storeId, \Illuminate\Support\Carbon::parse($dateStr), $duration);

                            $lines = collect($walk['dates'])->map(function (array $row) use ($storeId, $record) {
                                $day = \Illuminate\Support\Carbon::parse($row['date']);
                                $label = $day->format('d M Y (l)');

                                if ($row['closed']) {
                                    return "{$label}: Toko libur";
                                }

                                $capacity = Booking::capacityForDate((int) $storeId, $day);
                                $used = Booking::confirmedOverlapCount((int) $storeId, $day, $record?->id);
                                $penuh = $used >= $capacity ? ' — <strong style="color:#dc2626">PENUH</strong>' : '';

                                return "{$label}: {$used}/{$capacity} slot terpakai{$penuh}";
                            })->all();

                            if (! $walk['complete']) {
                                $lines[] = 'Toko ini sepertinya tutup terus-menerus (>90 hari) — cek lagi Jam Operasional toko di menu Toko.';
                            }

                            // TIDAK di-escape (beda dari versi lama) —
                            // SEMUA isi $lines di sini murni dari
                            // Carbon::format()/angka integer (label toko
                            // libur/kapasitas/terpakai), tidak ada input
                            // bebas pengguna yang ikut masuk ke string ini,
                            // jadi aman menyisipkan <strong> untuk highlight
                            // "PENUH" tanpa risiko XSS.
                            return new \Illuminate\Support\HtmlString(implode('<br>', $lines));
                        })
                        ->helperText('Kapasitas diatur di Kalender Kapasitas (menu Booking). Approve ditolak otomatis kalau salah satu tanggal di atas sudah PENUH.'),

                ]),

            Forms\Components\Section::make('Catatan')
                ->icon('heroicon-o-pencil-square')
                ->columns(1)
                ->schema([

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->placeholder('Model mobil, warna, permintaan khusus, dll.')
                        ->columnSpanFull(),

                ]),
                ]),
                Forms\Components\Group::make()->columnSpan(['lg' => 1])->schema([
            Forms\Components\Section::make('Sumber & Status')
                ->icon('heroicon-o-inbox-arrow-down')
                ->columns(1)
                ->schema([
                    Forms\Components\Select::make('source')->disabled(fn (?Booking $record, Forms\Get $get) => self::coreLocked($record, $get))->dehydrated()
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

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->helperText(fn (?Booking $record) => $record?->status === 'confirmed'
                            ? '"Selesai" baru bisa dipilih setelah tahap Quality Check ditandai lewat aplikasi karyawan.'
                            : null)
                        ->disableOptionWhen(fn (string $value, ?Booking $record) => $value === 'completed'
                            && $record?->status === 'confirmed'
                            && $record->completionBlocker() !== null)
                        // Opsi dibatasi (diperbaiki 2026-10-02, audit alur Booking):
                        // - 'cancelled' TIDAK bisa dipilih di form -- jalur ini
                        //   melewati gate Void, alasan pembatalan, dan refund DP
                        //   otomatis. Pembatalan lewat aksi "Batalkan" (quickCancel).
                        //   Tetap tampil kalau booking-nya MEMANG sudah cancelled
                        //   (record lama), supaya tidak jadi kosong.
                        // - 'pending' tidak bisa dipilih untuk booking yang sudah
                        //   confirmed/completed -- menurunkan status diam-diam
                        //   melepas slot tanpa notifikasi atau jejak alasan.
                        ->options(function (?Booking $record): array {
                            $labels = [
                                'pending'   => 'Menunggu Konfirmasi',
                                'confirmed' => 'Dikonfirmasi',
                                'completed' => 'Selesai',
                                'cancelled' => 'Dibatalkan',
                            ];

                            // Booking baru: hanya pending/confirmed. Booking
                            // yang ada: status sekarang + tujuan sah menurut
                            // Booking::STATUS_TRANSITIONS, TANPA 'cancelled'
                            // (lewat aksi Batalkan).
                            $keys = $record
                                ? array_merge([$record->status], array_diff(Booking::STATUS_TRANSITIONS[$record->status] ?? [], ['cancelled']))
                                : ['pending', 'confirmed'];

                            return array_intersect_key($labels, array_flip($keys));
                        })
                        ->helperText('Untuk membatalkan booking, pakai aksi "Batalkan" supaya DP dikembalikan dan alasan tercatat.')
                        // Default 'confirmed' DI SINI SENGAJA beda dari booking
                        // yang masuk lewat mobile app (selalu mulai 'pending',
                        // lihat Api/Customer/BookingController.php) — booking
                        // yang staff input manual di Filament (WA/walk-in)
                        // asumsinya sudah deal PASTI (staff yang mengetik ini
                        // sudah bicara langsung dengan customer), tidak perlu
                        // tahap triase "Menunggu" lagi seperti submission
                        // online yang belum pernah dikontak sama sekali. Staff
                        // tetap bisa turunkan manual ke 'pending' kalau memang
                        // booking-nya masih tentatif. Didokumentasikan eksplisit
                        // 2026-09-25 (audit Booking Instalasi) — sebelumnya
                        // perbedaan ini tidak dijelaskan di mana pun.
                        ->default('confirmed')
                        ->required(),
                ]),

                ]),
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
            // Dua kolom: kiri pelanggan, kendaraan, dan detail; kanan ringkasan status dan penugasan.
            InfolistGrid::make(['default' => 1, 'lg' => 3])->schema([
                InfolistGroup::make()->columnSpan(['lg' => 2])->schema([
            InfolistSection::make('Pelanggan')
                ->icon('heroicon-o-user')
                ->columns(2)
                ->schema([
                    TextEntry::make('display_name')
                        ->label('Nama Pelanggan')
                        ->state(fn (Booking $record) => $record->display_customer_name),
                    TextEntry::make('phone_number')
                        ->label('No. Telepon')
                        ->state(fn (Booking $record) => $record->display_phone_number),
                ]),

            InfolistSection::make('Kendaraan')
                ->icon('heroicon-o-truck')
                ->columns(2)
                ->schema([
                    TextEntry::make('vehicle_plate')->label('Plat Nomor')->placeholder('—'),
                    TextEntry::make('vehicle_label')
                        ->label('Kendaraan')
                        ->placeholder('—')
                        ->state(fn (Booking $record) => trim(implode(' ', array_filter([$record->vehicle_brand, $record->vehicle_model, $record->vehicle_variant]))) ?: null),
                    TextEntry::make('vehicle_color')->label('Warna')->placeholder('—'),
                    TextEntry::make('vehicle_year')->label('Tahun')->placeholder('—'),
                    TextEntry::make('vehicle_size')->label('Ukuran Mobil')->badge()->placeholder('Belum ditentukan'),
                    TextEntry::make('vehicle_power')->label('Jenis Tenaga')->formatStateUsing(fn ($state) => \App\Support\VehicleFormat::POWERS[$state] ?? $state)->placeholder('Belum ditentukan'),
                ]),

            InfolistSection::make('Layanan & Produk')
                ->icon('heroicon-o-sparkles')
                ->columns(2)
                ->schema([
                    TextEntry::make('service_type')->label('Jenis Layanan'),
                    // Produk per bagian; booking lama yang baru punya satu produk (kolom film_product_id) menampilkan produk itu.
                    TextEntry::make('filmProducts')
                        ->label('Produk per Bagian')
                        ->placeholder('Belum diisi')
                        ->state(function (Booking $record) {
                            if ($record->filmProducts->isNotEmpty()) {
                                return $record->filmProducts
                                    ->map(fn (\App\Models\BookingFilmProduct $item) => trim(($item->position ? "{$item->position}: " : '') . "{$item->filmProduct->sku} — {$item->filmProduct->name}"))
                                    ->implode(', ');
                            }

                            return $record->filmProduct ? "{$record->filmProduct->sku} — {$record->filmProduct->name}" : null;
                        }),
                    TextEntry::make('ppf_scope')
                        ->label('Cakupan PPF')
                        ->badge()
                        ->state(fn (Booking $record) => match ($record->ppf_scope) {
                            Booking::PPF_SCOPE_FULL_BODY => 'Full Body',
                            Booking::PPF_SCOPE_PARTIAL => 'Part',
                            default => null,
                        })
                        ->color(fn (Booking $record) => $record->includesPpfBonus() ? 'success' : 'gray')
                        ->visible(fn (Booking $record) => filled($record->ppf_scope)),

                    TextEntry::make('ppf_area')
                        ->label('PPF Eksterior / Interior')
                        ->state(fn (Booking $record) => $record->ppfAreaLabel())
                        ->visible(fn (Booking $record) => (bool) $record->product_ppf),

                    TextEntry::make('product_detailing')
                        ->label('Jasa Detailing')
                        ->badge()
                        ->state(fn (Booking $record) => $record->product_detailing ? ($record->includesPpfBonus() ? 'Termasuk (paket Full Body)' : 'Termasuk') : 'Tidak')
                        ->color(fn (Booking $record) => $record->product_detailing ? 'success' : 'gray'),

                    TextEntry::make('product_premium_wash')
                        ->label('Jasa Premium Wash')
                        ->badge()
                        ->state(fn (Booking $record) => $record->product_premium_wash ? ($record->includesPpfBonus() ? 'Termasuk (paket Full Body)' : 'Termasuk') : 'Tidak')
                        ->color(fn (Booking $record) => $record->product_premium_wash ? 'success' : 'gray'),

                    TextEntry::make('spendPromo.name')
                        ->label('Promo Total Pembelian')
                        ->placeholder('Tidak ada')
                        ->state(fn (Booking $record) => $record->spend_promo_id
                            ? ($record->spendPromo?->name ?? '(promo dihapus)') . ' — potong Rp' . number_format((float) $record->spend_promo_discount, 0, ',', '.')
                            : null),

                    // Gap ditutup 2026-09-26 (audit Voucher Promo).
                    TextEntry::make('voucherClaim.code')
                        ->label('Kode Voucher')
                        ->placeholder('Tidak ada')
                        ->state(fn (Booking $record) => $record->voucher_claim_id
                            ? ($record->voucherClaim?->code ?? '(klaim dihapus)') . ' — potong Rp' . number_format((float) $record->voucher_discount, 0, ',', '.')
                            : null),

                    // Gap ditutup 2026-10-01 (audit Bagian B, "Klaim Garansi &
                    // Maintenance PPF") -- SEBELUMNYA warranty_claim_id sama
                    // sekali tidak tampil di sini (beda dari voucherClaim di
                    // atas yang sudah dapat perlakuan ini), staff yang buka
                    // booking "Klaim Garansi" tidak bisa lihat kategori/deskripsi/
                    // status klaimnya tanpa cari manual ke WarrantyResource.
                    TextEntry::make('warrantyClaim.claim_number')
                        ->label('Klaim Garansi')
                        ->placeholder('Tidak ada')
                        ->visible(fn (Booking $record) => $record->warranty_claim_id !== null)
                        ->state(function (Booking $record) {
                            $claim = $record->warrantyClaim;
                            if (! $claim) {
                                return '(klaim dihapus)';
                            }

                            $categoryLabel = match ($claim->category) {
                                'worry_free_wrap' => 'Worry Free Wrap',
                                'product_warranty' => 'Product Warranty',
                                default => 'Lainnya',
                            };
                            $statusLabel = match ($claim->status) {
                                'pass' => 'Disetujui',
                                'reject' => 'Ditolak',
                                default => 'Menunggu Review',
                            };

                            return "{$claim->claim_number} — {$categoryLabel} ({$statusLabel})"
                                . ($claim->description ? "\n{$claim->description}" : '');
                        })
                        ->url(fn (Booking $record) => $record->warrantyClaim?->warranty_id
                            ? \App\Filament\Resources\WarrantyResource::getUrl('view', ['record' => $record->warrantyClaim->warranty_id])
                            : null),
                ]),

            InfolistSection::make('Jadwal & Catatan')
                ->icon('heroicon-o-calendar-days')
                ->columns(2)
                ->schema([
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
                    TextEntry::make('cancel_reason')
                        ->label('Alasan Pembatalan')
                        ->placeholder('—')
                        ->visible(fn (Booking $record) => $record->status === 'cancelled')
                        ->formatStateUsing(fn (?string $state, Booking $record) => trim(
                            ($state ?: 'Tanpa alasan')
                            . ($record->cancelled_by_type ? ' — oleh ' . ['customer' => 'customer', 'staff' => 'karyawan', 'system' => 'sistem'][$record->cancelled_by_type] : '')
                            . ($record->cancelled_at ? ', ' . $record->cancelled_at->format('d M Y H:i') : '')
                        ))
                        ->columnSpanFull(),
                ]),

            // Penerimaan, QC, dan serah terima (koreksi celah Fase 3, 2026-10-22): bukti yang sebelumnya hanya terlihat di
            // aplikasi staf. Hanya muncul bila booking sudah dikonfirmasi/selesai atau sudah punya salah satu catatannya.
                ]),
                InfolistGroup::make()->columnSpan(['lg' => 1])->schema([
            InfolistSection::make('Ringkasan')
                ->icon('heroicon-o-clipboard-document-check')
                ->columns(1)
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
                ]),

            InfolistSection::make('Penugasan')
                ->icon('heroicon-o-user-group')
                ->columns(1)
                ->schema([
                    TextEntry::make('store.name')->label('Toko/Workshop')->placeholder('—'),
                    TextEntry::make('installers.name')->label('Installer Bertugas')->badge()->placeholder('Belum ditugaskan'),
                    TextEntry::make('watchers.name')->label('Direksi Pemantau')->badge()->placeholder('—'),
                ]),

                ]),
            ]),

            InfolistSection::make('Penerimaan, QC, dan Serah Terima')
                ->visible(fn (Booking $record) => in_array($record->status, ['confirmed', 'completed'], true)
                    || $record->intakePhotos()->exists() || $record->qcResults()->exists() || $record->handover()->exists())
                ->collapsible()
                ->schema([
                    ViewEntry::make('handling')->hiddenLabel()->view('filament.infolists.booking-handling')->columnSpanFull(),
                ]),

            // "Inventori Terpakai" (diminta 2026-09-09) -- data bahan
            // baku/barang habis pakai/produk+seri roll yang dipakai
            // untuk booking ini, diambil dari Memo Barang yang terhubung
            // (Booking::materialMemo(), opsional -- lihat migrasi
            // 2026_09_09_000001). Section ini sengaja TIDAK MUNCUL kalau
            // belum ada memo terhubung (bukan tampil kosong membingungkan).
            InfolistSection::make('Inventori Terpakai')
                ->description(config('features.consumables') ? 'Bahan baku, barang habis pakai, dan produk+seri roll yang dipakai untuk booking ini, dicatat lewat Memo Barang.' : 'Bahan baku dan produk+seri roll yang dipakai untuk booking ini, dicatat lewat Memo Barang.')
                ->visible(fn (Booking $record) => $record->materialMemo !== null)
                ->schema([
                    TextEntry::make('materialMemo.memo_number')
                        ->label('No. Memo Barang')
                        ->url(fn (Booking $record) => $record->materialMemo
                            ? \App\Filament\Resources\MaterialMemoResource::getUrl('edit', ['record' => $record->materialMemo])
                            : null),

                    RepeatableEntry::make('materialMemo.items')
                        ->label('Item Dipakai')
                        ->schema([
                            TextEntry::make('item_name')->label('Nama Barang'),
                            TextEntry::make('item_type')
                                ->label('Jenis')
                                ->badge()
                                ->formatStateUsing(fn (string $state) => match ($state) {
                                    'raw_material' => 'Bahan Baku',
                                    'consumable_item' => 'Barang Habis Pakai',
                                    'inventory_item' => 'Inventori',
                                    default => $state,
                                }),
                            TextEntry::make('scrollCodeUsage.scrollCode.code')
                                ->label('Seri Produk (Roll)')
                                ->placeholder('—'),
                            TextEntry::make('meters_used')
                                ->label('Meter Dipakai')
                                ->placeholder('—')
                                ->formatStateUsing(fn (?string $state) => $state ? number_format((float) $state, 2) . ' m' : null),
                            TextEntry::make('qty_used')
                                ->label('Qty Dipakai')
                                ->formatStateUsing(fn ($state, $record) => $state !== null ? number_format((float) $state, 2) . ' ' . $record->unit : '—'),
                        ])
                        ->columns(5)
                        ->columnSpanFull(),
                ]),
        ]);
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

    /** Baris rincian harga jenis ini wajib punya produk (SKU)? Lihat config('booking.sale_line_sku_required'). */
    public static function saleLineSkuRequired(?string $serviceType): bool
    {
        return (bool) config('booking.sale_line_sku_required') && in_array($serviceType, \App\Services\BookingSaleLineService::SKU_REQUIRED_TYPES, true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking_number')
                    ->label('No. Booking')
                    ->searchable()
                    ->sortable(),

                // Pelanggan sebagai kolom utama: nama tebal, nomor telepon di bawahnya.
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Pelanggan')
                    ->weight('medium')
                    ->description(fn (Booking $record) => $record->display_phone_number)
                    ->getStateUsing(fn (Booking $record): string => $record->display_customer_name)
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('customer_name', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($q) => $q
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                        )
                    ),

                Tables\Columns\TextColumn::make('vehicle_plate')
                    ->label('Plat')
                    ->placeholder('—')
                    ->searchable()
                    ->description(fn (Booking $record) => trim(implode(' ', array_filter([$record->vehicle_brand, $record->vehicle_model]))) ?: null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('phone_number')
                    ->label('No. Telepon')
                    ->getStateUsing(fn (Booking $record): string => $record->display_phone_number)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('source')
                    ->label('Asal')
                    ->badge()
                    ->tooltip(fn (?string $state): ?string => \App\Support\Tooltips::bookingSource($state))
                    ->color(fn (?string $state) => match ($state) { 'app' => 'primary', 'whatsapp' => 'success', default => 'gray' })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'app'      => 'App',
                        'whatsapp' => 'WA',
                        'walk_in'  => 'Walk-in',
                        default    => $state,
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                // Toko: tampil bawaan hanya untuk akses penuh (staf toko selalu melihat tokonya sendiri).
                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(isToggledHiddenByDefault: ! (auth()->user()?->isFullAccess() ?? false)),

                Tables\Columns\TextColumn::make('installers.name')
                    ->label('Installer')
                    ->badge()
                    ->placeholder('Belum ditugaskan')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('service_type')
                    ->label('Layanan')
                    ->wrap()
                    ->limit(40),

                // Kolom baru (diminta 2026-09-08) -- ditaruh
                // toggleable+default hidden, tabel booking sudah cukup
                // padat, ini info tambahan bukan kolom inti.
                Tables\Columns\TextColumn::make('filmProduct.sku')
                    ->label('Varian Produk')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                // Progres pengerjaan (read-only; ditandai lewat mobile staff).
                Tables\Columns\TextColumn::make('current_stage')
                    ->label('Tahap')
                    ->formatStateUsing(fn (?string $state) => $state ? (\App\Models\BookingMessage::allStages()[$state] ?? $state) : '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('secondary_stage')
                    ->label('Tahap PPF')
                    ->formatStateUsing(fn (?string $state) => $state ? (\App\Models\BookingMessage::allStages()[$state] ?? $state) : '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('preferred_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable()
                    // Jam dan durasi di bawah tanggal (kolom Jam terpisah disembunyikan bawaan).
                    ->description(fn (Booking $record) => trim(implode(' · ', array_filter([
                        $record->preferred_time,
                        $record->duration_days > 1 ? "{$record->duration_days} hari (s/d {$record->end_date?->format('d M')})" : null,
                    ]))) ?: null),

                Tables\Columns\TextColumn::make('preferred_time')
                    ->label('Jam')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->icon(fn (?string $state) => match ($state) {
                        'pending'   => 'heroicon-m-clock',
                        'confirmed' => 'heroicon-m-check-badge',
                        'completed' => 'heroicon-m-check-circle',
                        'cancelled' => 'heroicon-m-x-circle',
                        default     => null,
                    })
                    ->tooltip(fn (?string $state): ?string => \App\Support\Tooltips::bookingStatus($state))
                    ->color(fn (?string $state) => match ($state) { 'pending' => 'warning', 'confirmed' => 'info', 'completed' => 'success', 'cancelled' => 'danger', default => 'gray' })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'   => 'Menunggu',
                        'confirmed' => 'Dikonfirmasi',
                        'completed' => 'Selesai',
                        'cancelled' => 'Dibatalkan',
                        default     => $state,
                    })
                    // Umur booking pending (SLA 4 jam) & pengajuan jadwal ulang
                    // customer langsung terlihat di tabel (2026-10-02).
                    ->description(function (Booking $record): ?string {
                        $parts = [];
                        // Tahap pengerjaan saat ini tampil di bawah status, jadi tabel tidak perlu kolom Tahap sendiri (kolomnya tetap ada lewat tombol kolom).
                        if ($record->status === 'confirmed' && $record->current_stage) {
                            $parts[] = \App\Models\BookingMessage::allStages()[$record->current_stage] ?? $record->current_stage;
                        }
                        if ($record->status === 'pending') {
                            $hours = (int) $record->created_at?->diffInHours(now());
                            $parts[] = $hours >= 4 ? "Lewat SLA ({$hours} jam)" : "Menunggu {$hours} jam";
                        }
                        if ($record->pendingRescheduleRequest) {
                            $parts[] = 'Jadwal ulang diajukan: ' . $record->pendingRescheduleRequest->requested_date->format('d M');
                        }
                        if ($record->status === 'confirmed' && ! $record->hasWorkStarted() && $record->preferred_date?->lt(today())) {
                            $parts[] = 'Lewat tanggal, belum dikerjakan';
                        }
                        if ($record->pendingCancellationRequest) {
                            $parts[] = 'Pembatalan diajukan pelanggan';
                        }

                        return $parts ? implode(' · ', $parts) : null;
                    }),

                Tables\Columns\TextColumn::make('cancel_reason')
                    ->label('Alasan Batal')
                    ->placeholder('—')
                    ->limit(40)
                    ->tooltip(fn (Booking $record) => $record->cancel_reason)
                    ->description(fn (Booking $record) => $record->cancelled_by_type
                        ? 'oleh ' . (['customer' => 'customer', 'staff' => 'karyawan', 'system' => 'sistem'][$record->cancelled_by_type] ?? $record->cancelled_by_type)
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
                // Pilihan (kotak pilih) di baris pertama, lalu kotak centang: baris dengan isi sejenis rapi dan tidak berselang-seling tinggi.
                Tables\Filters\SelectFilter::make('source')
                    ->label('Asal Booking')
                    ->options([
                        'app'      => 'Mobile App',
                        'whatsapp' => 'WhatsApp',
                        'walk_in'  => 'Walk-in',
                    ]),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->searchable()
                    ->preload()
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),

                // Bawaan "Aktif": pending/confirmed APAPUN tanggalnya (pending
                // lewat tanggal & job multi-hari yang mulai kemarin tidak boleh
                // hilang dari daftar), ditambah yang selesai/batal yang
                // tanggalnya hari ini atau mendatang.
                Tables\Filters\SelectFilter::make('cancelled_by_type')
                    ->label('Dibatalkan oleh')
                    ->options([
                        'customer' => 'Pelanggan',
                        'staff'    => 'Karyawan',
                        'system'   => 'Sistem',
                    ]),

                Tables\Filters\Filter::make('pending_sla')
                    ->label('Menunggu > 4 jam (lewat SLA)')
                    ->query(fn (Builder $query) => $query->where('status', 'pending')->where('created_at', '<=', now()->subHours(4))),

                Tables\Filters\Filter::make('reschedule_requested')
                    ->label('Ada pengajuan jadwal ulang')
                    ->query(fn (Builder $query) => $query->whereHas('rescheduleRequests', fn ($q) => $q->where('status', 'pending'))),

                // Semua produk sudah di tahap terakhirnya, tinggal tandai Quality Check
                // (lewat mobile) lalu Selesaikan.
                Tables\Filters\Filter::make('siap_qc')
                    ->label('Siap Quality Check')
                    ->query(fn (Builder $query) => $query->where('status', 'confirmed')
                        ->where(fn (Builder $q) => $q
                            ->where(fn (Builder $w) => $w->where('product_kaca_film', true)->where('product_ppf', true)
                                ->where('current_stage', 'kf_installation')->where('secondary_stage', 'ppf_installation'))
                            ->orWhere(fn (Builder $w) => $w->where('product_kaca_film', true)->where('product_ppf', false)
                                ->where('current_stage', 'kf_installation'))
                            ->orWhere(fn (Builder $w) => $w->where('product_kaca_film', false)->where('product_ppf', true)
                                ->where('current_stage', 'ppf_installation')))),

                Tables\Filters\Filter::make('overdue_confirmed')
                    ->label('Lewat tanggal, belum dikerjakan')
                    ->query(fn (Builder $query) => $query->where('status', 'confirmed')
                        ->whereDate('preferred_date', '<', today())
                        ->whereNull('current_stage')
                        ->whereNull('secondary_stage')),

                Tables\Filters\Filter::make('cancellation_requested')
                    ->label('Ada pengajuan pembatalan')
                    ->query(fn (Builder $query) => $query->whereHas('cancellationRequests', fn ($q) => $q->where('status', 'pending'))),

                Tables\Filters\Filter::make('upcoming')
                    ->label('Aktif & akan datang')
                    ->query(fn (Builder $query) => $query->where(fn (Builder $q) => $q
                        ->whereIn('status', ['pending', 'confirmed'])
                        ->orWhereDate('preferred_date', '>=', today()))),

                // Booking sudah SELESAI tapi nominal/pendapatannya belum
                // dicatat (belum "Proses Referral") — dipakai juga sebagai
                // target link dari banner "Belum Diproses" di Dashboard.
                // Kendaraan sudah keluar (SPK terisi Jam Keluar) tetapi booking masih Dikonfirmasi: SPK dan status booking sengaja berdiri sendiri,
                // jadi booking yang terlewat diselesaikan dan diproses ditemukan lewat filter ini.
                Tables\Filters\Filter::make('spk_keluar_belum_selesai')
                    ->label('Kendaraan keluar, booking belum selesai')
                    ->query(fn (Builder $query) => $query->where('status', 'confirmed')->whereHas('spk', fn (Builder $q) => $q->whereNotNull('checked_out_at'))),

                Tables\Filters\Filter::make('selesai_belum_diproses')
                    ->label('Selesai, belum diproses')
                    ->query(fn (Builder $query) => $query->where('status', 'completed')->unsold()),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(3)
            // Aksi utama (Lihat, Ubah) sebagai ikon; sisanya dikelompokkan di menu "...", supaya baris tidak penuh tombol.
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton()->tooltip('Lihat detail'),
                Tables\Actions\EditAction::make()->iconButton()->tooltip('Ubah'),

                Tables\Actions\ActionGroup::make([
                // "Catat Uang Muka (DP)" -- keputusan atasan 2026-09-19
                // (Topik 2, "Keputusan-PPN-DP-Produk-Stok-Ginnva.docx"): DP
                // BUKAN syarat wajib, diterima fleksibel kapan saja SELAMA
                // proses instalasi berjalan (belum completed/cancelled).
                // Nominal bebas diisi staff (tidak ada aturan tetap/%).
                // TERPISAH dari "Proses Referral" (transaction_amount) --
                // DP dicatat sbg Pendapatan Diterima Dimuka (liabilitas,
                // akun 2140), bukan pendapatan jasa. Lihat DownPaymentService.
                Tables\Actions\Action::make('receive_down_payment')
                    ->label('Catat Uang Muka (DP)')
                    ->icon('heroicon-o-banknotes')
                    ->color('info')
                    // Aksi uang disembunyikan (keputusan pemilik 2026-12-16); nyalakan lagi lewat FEATURE_MONEY_MENUS=true.
                    ->visible(fn (Booking $record) => (bool) config('features.money_menus') && ! in_array($record->status, ['completed', 'cancelled'], true))
                    ->form([
                        Forms\Components\Placeholder::make('dp_sudah_diterima')
                            ->label('DP Sudah Diterima (belum dikembalikan)')
                            ->content(fn (Booking $record) => 'Rp' . number_format($record->outstanding_down_payment, 0, ',', '.'))
                            ->visible(fn (Booking $record) => $record->outstanding_down_payment > 0),
                        Forms\Components\TextInput::make('amount')
                            ->label('Nominal DP Diterima')
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->helperText(config('features.finance')
                                ? 'Bebas diisi sesuai kesepakatan dengan pelanggan -- dicatat sebagai Pendapatan Diterima Dimuka, BUKAN pendapatan jasa.'
                                : 'Bebas diisi sesuai kesepakatan dengan pelanggan. DP bukan pendapatan jasa dan dipakai otomatis melunasi pembayaran saat Catat Penjualan.'),
                        Forms\Components\Select::make('method')
                            ->label('Metode Pembayaran')
                            ->options(\App\Models\Booking::PAYMENT_METHOD_LABELS)
                            ->required()
                            ->helperText('Cara pelanggan membayar DP; dipakai Buku Pembayaran untuk rekap per metode.'),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan (opsional)')
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (Booking $record, array $data) {
                        // Segregation of duties (audit framework 2026-09-14)
                        // -- konsisten dengan process_referral/process_refund
                        // di bawah, DP tetap uang sungguhan yang berpindah.
                        if (! (auth()->user()?->isFullAccess() ?? false)) {
                            try {
                                app(\App\Services\TransactionApprovalService::class)->submitDownPayment(
                                    $record,
                                    (float) $data['amount'],
                                    $data['notes'] ?: null,
                                    auth()->id(),
                                    $data['method'] ?? null
                                );
                            } catch (RuntimeException $e) {
                                Notification::make()
                                    ->title('Pengajuan tidak bisa dibuat')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title('Menunggu persetujuan')
                                ->body('Permintaan Catat Uang Muka (DP) untuk booking ini sudah dikirim ke admin/direksi untuk disetujui.')
                                ->warning()
                                ->send();

                            return;
                        }

                        try {
                            app(\App\Services\DownPaymentService::class)->receive(
                                $record,
                                (float) $data['amount'],
                                $data['notes'] ?: null,
                                auth()->id(),
                                null,
                                $data['method'] ?? null
                            );
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('DP tidak bisa dicatat')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(config('features.finance') ? 'DP berhasil dicatat & jurnal Pendapatan Diterima Dimuka dibuat.' : 'DP berhasil dicatat.')
                            ->success()
                            ->send();
                    }),

                // Kembalikan SISA DP yang tidak terpakai setelah booking selesai
                // (keputusan 2026-10-06). Uang keluar -> hanya akses penuh.
                Tables\Actions\Action::make('refund_unused_dp')
                    ->label('Kembalikan Sisa DP')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (Booking $record) => (bool) config('features.money_menus') && $record->status === 'completed'
                        && $record->outstanding_down_payment > 0.009
                        && (auth()->user()?->isFullAccess() ?? false))
                    ->requiresConfirmation()
                    ->modalHeading('Kembalikan Sisa DP?')
                    ->modalDescription(fn (Booking $record) => 'Rp' . number_format($record->outstanding_down_payment, 0, ',', '.')
                        . ' dari DP tidak terpakai melunasi pembayaran.' . (config('features.finance') ? ' Jurnal: Dr Pendapatan Diterima Dimuka (2140) / Kr Kas.' : ''))
                    ->action(function (Booking $record) {
                        try {
                            $total = app(\App\Services\DownPaymentService::class)->refundUnusedRemainder($record, auth()->id());
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Sisa DP tidak bisa dikembalikan')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Sisa DP Rp' . number_format($total, 0, ',', '.') . ' dikembalikan' . (config('features.finance') ? ' & jurnal dibuat.' : '.'))->success()->send();
                    }),

                // Nominal transaksi & kode referral partner SENGAJA diproses
                // bareng di sini (Filament), bukan saat "Selesaikan Booking"
                // di mobile app lagi — dipisah supaya staff toko fokus ke
                // operasional instalasi, sementara nominal & poin referral
                // dicek/diinput belakangan (mis. oleh kasir).
                Tables\Actions\Action::make('process_referral')
                    ->label('Catat Penjualan')
                    ->icon('heroicon-o-gift')
                    ->color('warning')
                    ->visible(fn (Booking $record) => (bool) config('features.money_menus') && $record->status === 'completed')
                    ->form([
                        // Rincian harga per layanan (Penjualan Fase 1, 2026-11-10): nilai SEBENARNYA tiap layanan/produk. Pendapatan
                        // per jenis layanan di Jurnal Umum dan laporan dibagi menurut nilai ini (booking tanpa rincian tetap dibagi rata).
                        // "Nominal Transaksi" di bawah tetap angka bersih setelah voucher/promo/nego; selisihnya dengan jumlah rincian
                        // ditampilkan sebagai potongan.
                        Forms\Components\Repeater::make('sale_lines')
                            ->label('Rincian Harga per Layanan')
                            ->schema([
                                Forms\Components\Select::make('service_type')
                                    ->label('Jenis layanan')
                                    ->options(\App\Models\BookingSaleLine::SERVICE_TYPES)
                                    ->native(false)
                                    ->live()
                                    ->required(),
                                Forms\Components\Select::make('film_product_id')
                                    ->label(fn (Forms\Get $get) => self::saleLineSkuRequired($get('service_type')) ? 'Produk (SKU)' : 'Produk (opsional)')
                                    ->options(fn () => \App\Models\FilmProduct::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                                    ->searchable()
                                    ->native(false)
                                    ->required(fn (Forms\Get $get) => self::saleLineSkuRequired($get('service_type')))
                                    ->helperText(fn (Forms\Get $get) => self::saleLineSkuRequired($get('service_type')) ? 'Wajib untuk PPF dan Kaca Film: dipakai laporan Penjualan Produk.' : null),
                                Forms\Components\TextInput::make('position')->label('Bagian / keterangan')->maxLength(80),
                                Forms\Components\TextInput::make('quantity')->label('Jumlah')->numeric()->minValue(0.01)->default(1)->live(onBlur: true)->required(),
                                Forms\Components\TextInput::make('unit_price')->label('Harga satuan')->numeric()->minValue(0)->prefix('Rp')->live(onBlur: true)->required(),
                                Forms\Components\TextInput::make('discount_amount')->label('Potongan baris')->numeric()->minValue(0)->default(0)->prefix('Rp')->live(onBlur: true),
                            ])
                            ->columns(3)
                            ->default(function (Booking $record) {
                                $existing = $record->saleLines->map(fn ($l) => [
                                    'service_type' => $l->service_type,
                                    'film_product_id' => $l->film_product_id,
                                    'position' => $l->position,
                                    'quantity' => (float) $l->quantity,
                                    'unit_price' => (float) $l->unit_price,
                                    'discount_amount' => (float) $l->discount_amount,
                                ])->all();

                                if ($existing !== [] || ! \App\Services\BookingSaleLineService::isRequiredFor($record)) {
                                    return $existing;
                                }

                                // Wajib tapi belum ada: satu baris per jenis layanan pada booking, tinggal isi harganya.
                                return collect(\App\Services\BookingRevenueSplitter::productKeys($record))
                                    ->map(fn ($key) => ['service_type' => $key, 'quantity' => 1, 'discount_amount' => 0])
                                    ->all();
                            })
                            ->addActionLabel('Tambah baris')
                            ->reorderable(false)
                            ->helperText(fn (Booking $record) => \App\Services\BookingSaleLineService::isRequiredFor($record)
                                ? 'Wajib diisi untuk booking ini: minimal satu baris dengan harga.'
                                : 'Disarankan: isi nilai tiap layanan supaya laporan per jenis layanan akurat. Tanpa rincian, nilai dibagi rata antar jenis.'),

                        Forms\Components\Placeholder::make('sale_lines_total')
                            ->label('Jumlah rincian')
                            ->visible(fn (Forms\Get $get) => collect($get('sale_lines') ?? [])->contains(fn ($r) => is_numeric($r['unit_price'] ?? null)))
                            ->content(function (Forms\Get $get) {
                                $rows = collect($get('sale_lines') ?? [])->filter(fn ($r) => is_numeric($r['unit_price'] ?? null));
                                $total = $rows->sum(fn ($r) => (float) ($r['quantity'] ?? 1) * (float) $r['unit_price'] - (float) (is_numeric($r['discount_amount'] ?? null) ? $r['discount_amount'] : 0));
                                $nominal = $get('transaction_amount');
                                $text = 'Rp ' . number_format($total, 0, ',', '.');

                                if (is_numeric($nominal) && (float) $nominal > 0) {
                                    $gap = round($total - (float) $nominal, 0);
                                    $text .= $gap > 0
                                        ? ' — selisih Rp ' . number_format($gap, 0, ',', '.') . ' dengan Nominal Transaksi dianggap potongan (voucher/promo/nego).'
                                        : ($gap < 0 ? ' — LEBIH KECIL dari Nominal Transaksi; periksa harga satuan.' : ' — sama dengan Nominal Transaksi.');
                                }

                                return $text;
                            }),

                        Forms\Components\TextInput::make('transaction_amount')
                            ->label('Nominal Transaksi')
                            ->numeric()
                            ->minValue(0)
                            ->live()
                            ->default(fn (Booking $record) => $record->transaction_amount)
                            ->helperText('Angka bersih setelah potongan. Dipakai untuk menghitung poin referral (1 poin / Rp10.000).'),

                        // Dipisah dari transaction_amount supaya penjualan
                        // dengan termin (belum lunas di tempat) bisa
                        // tercatat sebagai Piutang Usaha — lihat
                        // BookingPostingService. Default SAMA dengan
                        // transaction_amount (lunas penuh) kalau kasir
                        // tidak sengaja mengubahnya.
                        // Pembayaran yang diterima (Penjualan Fase 2, 2026-11-11): menggantikan isian tunggal "Total Diterima" dan "Metode
                        // Pembayaran". Satu baris per pembayaran (metode, rekening, nominal); jurnal kasnya terpisah per rekening. Sisa
                        // yang belum terbayar (setelah DP) menjadi Piutang Usaha. Daftar kosong = belum ada uang masuk selain DP.
                        Forms\Components\Repeater::make('settlement_payments')
                            ->label('Pembayaran diterima')
                            ->schema([
                                Forms\Components\Select::make('method')
                                    ->label('Metode')
                                    ->options(Booking::PAYMENT_METHOD_LABELS)
                                    ->native(false)
                                    ->required(),
                                Forms\Components\Select::make('payment_account_id')
                                    ->label('Rekening (otomatis kalau kosong)')
                                    ->options(fn (Booking $record) => \App\Models\PaymentAccount::query()
                                        ->with('chartAccount')
                                        ->where('is_active', true)
                                        ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $record->store_id))
                                        ->orderBy('name')->get()
                                        ->filter(fn ($a) => $a->isUsable())
                                        ->pluck('name', 'id'))
                                    ->visible(fn () => (bool) config('features.finance'))
                                    ->native(false),
                                Forms\Components\TextInput::make('amount')->label('Nominal')->numeric()->minValue(1)->prefix('Rp')->live(onBlur: true)->required(),
                            ])
                            ->columns(3)
                            ->default(function (Booking $record) {
                                $existing = $record->settlementPayments->map(fn ($p) => [
                                    'method' => $p->method,
                                    'payment_account_id' => $p->payment_account_id,
                                    'amount' => (float) $p->amount,
                                ])->all();

                                if ($existing !== []) {
                                    return $existing;
                                }

                                // Belum ada daftar: booking yang sudah pernah diproses mewarisi angka lamanya; yang pertama kali diisi satu baris tunai.
                                $paidBefore = max(0.0, (float) ($record->amount_received ?? $record->transaction_amount ?? 0) - (float) $record->downPayments()->sum('applied_amount'));

                                return [[
                                    'method' => $record->payment_method ?: 'tunai',
                                    'payment_account_id' => null,
                                    'amount' => $paidBefore > 0 ? $paidBefore : null,
                                ]];
                            })
                            ->addActionLabel('Tambah pembayaran')
                            ->reorderable(false)
                            ->helperText('Boleh lebih dari satu (mis. sebagian tunai, sebagian transfer). Hapus semua baris kalau belum ada pembayaran; sisanya ' . (config('features.finance') ? 'jadi Piutang Usaha.' : 'belum terbayar.')),

                        Forms\Components\Placeholder::make('settlement_summary')
                            ->label('Ringkasan pembayaran')
                            ->content(function (Forms\Get $get, Booking $record) {
                                $nominal = (float) ($get('transaction_amount') ?: 0);
                                $paid = collect($get('settlement_payments') ?? [])->sum(fn ($r) => is_numeric($r['amount'] ?? null) ? (float) $r['amount'] : 0);
                                $dp = \App\Services\BookingSettlementPaymentService::dpApplicable($record, $nominal);
                                $rp = fn (float $n) => 'Rp ' . number_format($n, 0, ',', '.');
                                $remaining = round($nominal - $paid - $dp, 2);

                                $text = 'Diterima ' . $rp($paid) . ($dp > 0 ? ' + DP ' . $rp($dp) : '');

                                if ($nominal <= 0) {
                                    return $text;
                                }

                                return $remaining > 0.009
                                    ? $text . ' — sisa ' . $rp($remaining) . (config('features.finance') ? ' akan dicatat sebagai Piutang Usaha.' : ' belum terbayar.')
                                    : ($remaining < -0.009 ? $text . ' — MELEBIHI nominal transaksi, tidak bisa disimpan.' : $text . ' — lunas.');
                            }),

                        // Promo Per Total Pembelian (opsional) — potongan flat
                        // untuk booking yang nominal KOTOR-nya >= ambang.
                        // "Nominal Transaksi" di atas diisi angka NET (sudah
                        // dipotong); sistem cek net + potongan >= minimal.
                        // Nego harga kasir dengan cap % (audit Majoo f31,
                        // keputusan user 2026-09-22) — dipakai hitung
                        // harga acuan matriks (PriceCalculator::priceFor())
                        // supaya sistem tahu berapa % Nominal Transaksi di
                        // atas terdiskon dari harga normal. Kalau produk
                        // (film_product_id) atau ukuran ini kosong, staff
                        // non-full-access SELALU lewat approval biasa
                        // (tidak bisa diverifikasi = tidak boleh lolos
                        // sendiri).
                        Forms\Components\Select::make('vehicle_size')
                            ->label('Ukuran Kendaraan')
                            ->options(array_combine(\App\Services\PriceCalculator::VEHICLE_SIZES, \App\Services\PriceCalculator::VEHICLE_SIZES))
                            ->default(fn (Booking $record) => $record->vehicle_size)
                            ->visible(fn (Booking $record) => $record->film_product_id !== null)
                            ->helperText('Dipakai hitung harga acuan matriks untuk cap nego harga (maks ' . Booking::NEGOTIATION_DISCOUNT_MAX_PCT . '% tanpa approval).')
                            ->native(false),

                        Forms\Components\Select::make('spend_promo_id')
                            ->label('Promo Total Pembelian (opsional)')
                            ->options(fn () => \App\Models\SpendPromo::running()->orderBy('name')->get()
                                ->mapWithKeys(fn (\App\Models\SpendPromo $p) => [
                                    $p->id => "{$p->name} — min Rp" . number_format((float) $p->min_purchase_amount, 0, ',', '.') . ' → potong Rp' . number_format((float) $p->discount_amount, 0, ',', '.'),
                                ]))
                            ->default(fn (Booking $record) => $record->spend_promo_id)
                            ->helperText('Isi angka SETELAH potongan. Nominal ditambah potongan promo harus mencapai minimal pembelian promo. Bisa dipakai bersama Kode Voucher.'),

                        // Gap ditutup 2026-09-26 (audit Voucher Promo) --
                        // SEBELUMNYA voucher fisik cuma status tracking
                        // administratif terpisah (menu Voucher > "Tandai
                        // Terpakai"), tidak pernah benar-benar memotong
                        // transaction_amount. Sekarang dipilih di sini
                        // (mirip Promo Total Pembelian di atas) -- begitu
                        // disimpan, klaim voucher OTOMATIS ditandai
                        // "Terpakai" & ditautkan ke booking ini, staff
                        // tidak perlu lagi bolak-balik ke menu Voucher.
                        // Opsi HANYA klaim yang masih 'active' & belum
                        // tertaut booking lain, ATAU klaim yang SUDAH
                        // tertaut booking ini sendiri (supaya form Edit
                        // tetap menampilkan pilihan semula).
                        Forms\Components\Select::make('voucher_claim_id')
                            ->label('Kode Voucher (opsional)')
                            ->options(function (Booking $record) {
                                return \App\Models\VoucherClaim::query()
                                    ->where(function ($q) use ($record) {
                                        // Voucher aktif, belum tertaut booking lain, belum kedaluwarsa. Voucher hasil tukar poin
                                        // melekat ke akun penukarnya: hanya muncul untuk booking customer yang sama.
                                        $q->where('status', 'active')
                                            ->whereNull('booking_id')
                                            ->where(fn ($e) => $e->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()))
                                            ->where(fn ($o) => $o->whereNull('reward_id')->orWhere('customer_id', $record->customer_id));
                                    })
                                    ->orWhere('id', $record->voucher_claim_id)
                                    ->with(['voucher:id,name,discount_amount', 'reward:id,name'])
                                    ->orderByDesc('created_at')
                                    ->get()
                                    ->mapWithKeys(fn (\App\Models\VoucherClaim $c) => [
                                        $c->id => "{$c->code} — {$c->displayName()} (Rp" . number_format($c->faceValue(), 0, ',', '.') . ')' . ($c->holder_name ? " — {$c->holder_name}" : ''),
                                    ]);
                            })
                            ->default(fn (Booking $record) => $record->voucher_claim_id)
                            ->searchable()
                            ->helperText('Kode voucher otomatis ditandai Terpakai dan memotong Nominal Transaksi (isi angka SETELAH potongan).'),

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
                        // Seluruh aturan (rincian harga, pembayaran, promo/voucher, persetujuan, jurnal, poin referral) ada di
                        // BookingSaleProcessor, dipakai bersama aplikasi staf (Penjualan Fase 5).
                        try {
                            $result = app(\App\Services\BookingSaleProcessor::class)->process($record, $data, auth()->user());
                        } catch (\App\Services\SaleProcessingException $e) {
                            Notification::make()->title($e->title)->body($e->getMessage())->danger()->send();

                            return;
                        }

                        if ($result['status'] === 'pending') {
                            Notification::make()->title('Menunggu persetujuan')->body($result['message'])->warning()->send();

                            return;
                        }

                        Notification::make()->title($result['message'])->success()->send();
                    }),

                // "Proses Refund" (diminta 2026-09-09, analog Laporan
                // Refund Majoo) -- BISA PARSIAL & berkali-kali sampai
                // habis, TIAP refund otomatis bikin jurnal kontra baru
                // di Jurnal Umum (lihat RefundService, TIDAK mengedit
                // jurnal pendapatan asli). Cuma muncul kalau booking
                // SUDAH punya jurnal pendapatan (sudah "Proses Referral")
                // -- tidak ada yang bisa di-refund kalau belum pernah
                // tercatat sebagai pendapatan.
                Tables\Actions\Action::make('process_refund')
                    ->label('Proses Refund')
                    ->icon('heroicon-o-receipt-refund')
                    ->color('danger')
                    ->visible(fn (Booking $record) => (bool) config('features.money_menus') && $record->isSaleProcessed()
                        && (float) $record->refunds()->sum('amount') < (float) $record->transaction_amount)
                    ->form(fn (Booking $record) => [
                        Forms\Components\Placeholder::make('sisa_refund')
                            ->label('Sisa yang Bisa Di-refund')
                            ->content(function () use ($record) {
                                $remaining = (float) $record->transaction_amount - (float) $record->refunds()->sum('amount');

                                return 'Rp' . number_format($remaining, 0, ',', '.');
                            }),
                        Forms\Components\TextInput::make('amount')
                            ->label('Nominal Refund')
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->helperText('Bisa parsial (sebagian dari nilai transaksi)' . (config('features.finance') ? ' — otomatis bikin jurnal balik pendapatan + kas di Jurnal Umum.' : '.')),
                        Forms\Components\Select::make('method')
                            ->label('Metode Pengembalian')
                            ->options(\App\Models\Booking::PAYMENT_METHOD_LABELS)
                            ->required()
                            ->helperText('Cara uang dikembalikan ke pelanggan; tampil di Laporan Refund.'),
                        Forms\Components\Textarea::make('reason')
                            ->label('Alasan Refund')
                            ->required()
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (Booking $record, array $data) {
                        // Segregation of duties (audit framework 2026-09-14)
                        // -- lihat catatan sama di action process_referral
                        // di atas.
                        if (! (auth()->user()?->isFullAccess() ?? false)) {
                            try {
                                app(\App\Services\TransactionApprovalService::class)->submitRefund(
                                    $record,
                                    (float) $data['amount'],
                                    $data['reason'] ?: null,
                                    auth()->id(),
                                    $data['method'] ?? null
                                );
                            } catch (RuntimeException $e) {
                                Notification::make()
                                    ->title('Pengajuan tidak bisa dibuat')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title('Menunggu persetujuan')
                                ->body('Permintaan Proses Refund untuk booking ini sudah dikirim ke admin/direksi untuk disetujui.')
                                ->warning()
                                ->send();

                            return;
                        }

                        try {
                            app(\App\Services\RefundService::class)->process(
                                $record,
                                (float) $data['amount'],
                                $data['reason'] ?: null,
                                auth()->id(),
                                $data['method'] ?? null
                            );
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('Refund tidak bisa diproses')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(config('features.finance') ? 'Refund berhasil diproses & jurnal kontra dibuat.' : 'Refund berhasil diproses.')
                            ->success()
                            ->send();
                    }),

                // Invoice / bukti transaksi PDF untuk customer (diminta
                // 2026-09-10, analog "Daftar Invoice" Majoo). Bukan Faktur
                // Pajak — PPN belum diputuskan. Cuma untuk booking yang
                // sudah selesai & punya nominal transaksi.
                // Koreksi tanggal selesai (Penjualan Fase 3, 2026-11-12): hanya akses penuh, hanya selama penjualannya BELUM diproses
                // (jurnal pendapatan belum ada), tidak boleh ke masa depan atau ke periode yang sudah ditutup. Tercatat di log aktivitas.
                Tables\Actions\Action::make('correct_completed_at')
                    ->label('Koreksi Tanggal Selesai')
                    ->icon('heroicon-o-calendar-days')
                    ->color('gray')
                    ->visible(fn (Booking $record) => $record->status === 'completed'
                        && ! $record->isSaleProcessed()
                        && (auth()->user()?->isFullAccess() ?? false))
                    ->form([
                        Forms\Components\DateTimePicker::make('completed_at')
                            ->label('Tanggal & jam selesai')
                            ->seconds(false)
                            ->native(false)
                            ->required()
                            ->maxDate(now())
                            ->default(fn (Booking $record) => $record->completed_at ?? $record->updated_at)
                            ->helperText('Tanggal pengakuan pendapatan saat penjualan diproses. Tidak boleh di masa depan atau periode yang sudah ditutup.'),
                    ])
                    ->action(function (Booking $record, array $data) {
                        $at = \Illuminate\Support\Carbon::parse($data['completed_at']);

                        if ($at->isFuture()) {
                            Notification::make()->title('Tanggal tidak valid')->body('Tanggal selesai tidak boleh di masa depan.')->danger()->send();

                            return;
                        }

                        if (\App\Models\AccountingPeriod::isClosedFor($at)) {
                            Notification::make()->title('Periode sudah ditutup')->body('Periode ' . $at->translatedFormat('F Y') . ' sudah ditutup; pilih tanggal di periode yang masih terbuka.')->danger()->send();

                            return;
                        }

                        $record->update(['completed_at' => $at]);

                        Notification::make()->title('Tanggal selesai diperbarui')->success()->send();
                    }),

                Tables\Actions\Action::make('print_invoice')
                    ->label('Cetak Invoice')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->visible(fn (Booking $record) => $record->status === 'completed' && $record->transaction_amount !== null && (float) $record->transaction_amount > 0)
                    ->action(fn (Booking $record) => static::downloadInvoicePdf($record)),

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
                        : 'Kirim pengingat maintenance berkala ke pelanggan lewat WhatsApp, Push, dan Email?')
                    ->action(function (Booking $record) {
                        $results = app(ServiceReminderService::class)->sendFor($record, force: true);
                        $sent = array_keys(array_filter($results));

                        $notification = Notification::make()->title($sent
                            ? 'Pengingat terkirim lewat: ' . implode(', ', $sent)
                            : 'Pengingat gagal terkirim di semua kanal — cek kontak pelanggan & konfigurasi WhatsApp.');

                        $sent ? $notification->success()->send() : $notification->danger()->send();
                    }),

                // Sebelumnya tidak ada cara cepat menghubungi customer dari
                // Filament sama sekali — staff yang kerja dari desktop
                // harus copy-paste nomor manual ke WhatsApp Web. Lihat
                // audit UI/UX Filament Booking 2026-08-27.
                    // Audit framework 2026-09-14, "Penanganan data
                    // pribadi (PII) pelanggan" -- customer_name/
                    // phone_number tetap utuh di database (bukti
                    // transaksi), TAPI aksi hubungi AKTIF (WA/telepon)
                    // sengaja disembunyikan kalau akun customer sudah
                    // dihapus -- kontak orang yang sudah minta akunnya
                    // dihapus tidak seharusnya tetap dipakai staff untuk
                    // menghubungi, walau nomornya masih tercatat.
                    Tables\Actions\Action::make('whatsapp')
                        ->label('WhatsApp')
                        ->icon('heroicon-o-chat-bubble-left-right')
                        ->color('success')
                        ->visible(fn (Booking $record) => ! ($record->customer_id && ! $record->customer)
                            && filled($record->phone_number ?? $record->customer?->phone_number))
                        ->url(fn (Booking $record) => 'https://wa.me/' . PhoneFormatter::toWhatsAppNumber($record->phone_number ?? $record->customer?->phone_number))
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('call')
                        ->label('Telepon')
                        ->icon('heroicon-o-phone')
                        ->color('info')
                        ->visible(fn (Booking $record) => ! ($record->customer_id && ! $record->customer)
                            && filled($record->phone_number ?? $record->customer?->phone_number))
                        ->url(fn (Booking $record) => 'tel:' . ($record->phone_number ?? $record->customer?->phone_number)),

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
                    // Otorisasi "Void" (audit Majoo vs Ginnva, "Toggle Void
                    // sbg permission terpisah") -- sebelumnya SIAPA PUN yang
                    // bisa lihat menu Booking bisa membatalkan, tanpa syarat
                    // akses apa pun. Keputusan user 2026-09-22: batasi ke
                    // store_manager & full-access (super_admin/direksi) saja.
                    // Diperluas 2026-09-23 (matriks Hak Akses granular,
                    // audit f64) -- staff LAIN sekarang BISA diberi izin
                    // Void juga, TAPI HARUS dicentang eksplisit admin di
                    // "Hak Akses Detail" (default FALSE, additive di atas
                    // 2 role yang sudah pasti boleh, bukan pengganti).
                    ->visible(fn (Booking $record) => in_array($record->status, ['pending', 'confirmed'], true)
                        && (auth()->user()?->isFullAccess()
                            || auth()->user()?->isStoreManager()
                            || (auth()->user()?->hasMenuAccess(static::class) && auth()->user()?->hasModuleAction(static::class, 'void', false))))
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan Booking?')
                    ->modalDescription('Booking ini akan ditandai Dibatalkan. Ini tidak otomatis membatalkan assignment installer/direksi yang sudah tersimpan.')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label(fn (Booking $record) => ($record->status === 'confirmed' ? 'Alasan' : 'Alasan (opsional)') . ' — dikirim ke pelanggan')
                            // Booking confirmed sudah memakai slot & kemungkinan DP
                            // -- alasan wajib (keputusan 2026-10-02).
                            ->required(fn (Booking $record) => $record->status === 'confirmed')
                            ->maxLength(500),
                    ])
                    ->action(function (Booking $record, array $data) {
                        $alreadyFinalStatus = DB::transaction(function () use ($record, $data) {
                            $locked = Booking::where('id', $record->id)->lockForUpdate()->first();

                            if (in_array($locked->status, ['completed', 'cancelled'], true)) {
                                return $locked->status;
                            }

                            $locked->cancelWith('staff', auth()->id(), $data['reason'] ?? null);

                            // Keputusan atasan 2026-09-19 (Topik 2, "Keputusan-
                            // PPN-DP-Produk-Stok-Ginnva.docx"): DP dikembalikan
                            // PENUH kalau booking dibatalkan -- no-op kalau
                            // booking ini tidak punya DP sama sekali.
                            app(\App\Services\DownPaymentService::class)
                                ->refundAllOnCancellation($locked->id, auth()->id());

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

                // Booking yang sudah diproses referral (partner_id terisi)
                // sudah menambah saldo poin Partner yang cash-convertible —
                // saldo itu TIDAK ikut hilang kalau booking-nya dihapus
                // (PartnerPointTransaction pakai reference_id lepas, bukan
                // FK sungguhan), jadi hapus booking bisa menghilangkan
                // jejak audit tanpa membatalkan saldo. Cuma super_admin/
                // direksi yang boleh hapus booking.
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->isFullAccess()),
                ])->icon('heroicon-o-ellipsis-horizontal')->tooltip('Aksi lain'),
            ])
            // Booking yang perlu tindakan (menunggu lewat SLA 4 jam, atau dikonfirmasi lewat tanggal tapi belum dikerjakan) diberi garis merah
            // lembut di kiri baris (gaya di admin-ui-styles).
            ->recordClasses(fn (Booking $record) => ($record->status === 'pending' && $record->created_at?->lte(now()->subHours(4)))
                || ($record->status === 'confirmed' && ! $record->hasWorkStarted() && $record->preferred_date?->lt(today()))
                ? 'cu-row-late' : null)
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->emptyStateHeading('Belum ada booking')
            ->emptyStateDescription('Booking dari aplikasi, WhatsApp, dan walk-in akan muncul di sini.')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            // Export Excel pindah ke kepala halaman (ListBookings::getHeaderActions), sejajar dengan tombol Tambah Booking.
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
            BookingResource\RelationManagers\RescheduleRequestsRelationManager::class,
            BookingResource\RelationManagers\CancellationRequestsRelationManager::class,
            \App\Filament\Resources\RelationManagers\ActivityHistoryRelationManager::class,
        ];
    }

    /**
     * Invoice / bukti transaksi PDF booking. transaction_amount disimpan
     * NET (sudah dipotong promo) — "kotor" = net + spend_promo_discount.
     * amount_received null = dianggap lunas penuh (pola BookingPostingService).
     *
     * PUBLIC (bukan protected lagi, audit Detail Penjualan 2026-09-11,
     * temuan #5) — dipakai ULANG oleh aksi "Cetak Invoice" di
     * SalesResource ("Daftar Invoice") supaya user tidak perlu pindah ke
     * halaman Booking dulu cuma untuk cetak. SATU implementasi, dua
     * pemicu — bukan logic PDF yang diduplikasi.
     */
    public static function downloadInvoicePdf(Booking $record)
    {
        $record->loadMissing(['store', 'customer', 'filmProduct', 'spendPromo', 'voucherClaim']);

        $net = (float) $record->transaction_amount;
        $discount = (float) ($record->spend_promo_discount ?? 0);
        // Gap ditutup 2026-09-26 (audit Voucher Promo) -- voucher_discount
        // SEBELUMNYA tidak ada, jadi tidak pernah masuk perhitungan gross
        // di sini (belum ada kolomnya sama sekali). Sekarang dijumlah
        // terpisah dari spend_promo_discount supaya invoice tetap
        // menampilkan rincian 2 baris potongan yang berbeda sumbernya.
        $voucherDiscount = (float) ($record->voucher_discount ?? 0);
        $gross = $net + $discount + $voucherDiscount;
        $received = $record->amount_received !== null ? (float) $record->amount_received : $net;
        $outstanding = max(0, round($net - $received, 2));

        $pdf = Pdf::loadView('pdf.booking_invoice', [
            'booking' => $record,
            'invoiceNumber' => 'INV/' . $record->booking_number,
            'customerName' => $record->customer_name ?? $record->customer?->name ?? '—',
            'customerPhone' => $record->phone_number ?? $record->customer?->phone_number,
            'net' => $net,
            'gross' => $gross,
            'discount' => $discount,
            'promoName' => $record->spendPromo?->name,
            'voucherDiscount' => $voucherDiscount,
            'voucherCode' => $record->voucherClaim?->code,
            'received' => $received,
            'outstanding' => $outstanding,
            // Rincian PPN (Topik 1, "Keputusan-PPN-DP-Produk-Stok-Ginnva.docx"
            // 2026-09-19) -- null untuk booking lama sebelum fitur ini aktif
            // (lihat Booking::applyPpnBreakdown()), blade cek null sebelum
            // menampilkan barisnya.
            'dppAmount' => $record->dpp_amount !== null ? (float) $record->dpp_amount : null,
            'ppnAmount' => $record->ppn_amount !== null ? (float) $record->ppn_amount : null,
        ])->setPaper('a4', 'portrait');

        $filename = 'Invoice-' . str_replace(['/', ' '], '-', $record->booking_number) . '.pdf';

        return response()->streamDownload(fn () => print($pdf->output()), $filename);
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

    /**
     * Pilihan "Bagian" di Varian Produk menurut jenis layanan: "Seluruh Bagian PPF" dan panel PPF (panel hanya untuk Eksterior Part),
     * serta "Seluruh Kaca" dan bagian kaca Window Film. Kosong berarti tidak dipakai (tanpa PPF dan Window Film).
     *
     * @param  array<int, string>  $services
     * @return array<string, array<string, string>|string>
     */
    public static function partOptions(array $services, ?string $ppfScope, bool $exterior = true, bool $interior = false): array
    {
        $options = [];

        if (in_array('ppf', $services, true)) {
            // "Seluruh Bagian PPF" selalu ada (untuk varian yang dipakai di semua bagian); panel satu per satu hanya untuk Eksterior Part,
            // dan "Interior" bila pemasangan Interior ikut dipilih (varian produk untuk paket interior).
            $labels = [Booking::PPF_ALL];

            if ($ppfScope === Booking::PPF_SCOPE_PARTIAL && $exterior) {
                $labels = [...$labels, ...array_values(Booking::PPF_PARTS)];
            }

            if ($interior) {
                $labels[] = Booking::PPF_INTERIOR_PART;
            }

            $options['PPF'] = array_combine($labels, $labels);
        }

        if (in_array('kaca_film', $services, true)) {
            $labels = [Booking::WF_ALL, ...Booking::WF_AREAS];
            $options['Window Film'] = array_combine($labels, $labels);
        }

        return $options;
    }

    /**
     * Isi bookings.film_product_id dari produk pertama di daftar Produk per Bagian (id terkecil). Kolom ini tetap dipakai laporan Produk
     * Terlaris, Memo Barang otomatis, dan Catat Penjualan. Tanpa baris produk, nilai lama dibiarkan (booking lama). Lewat query dasar agar
     * updated_at (token versi form) tidak ikut berubah.
     */
    public static function syncMainProduct(Booking $record): void
    {
        $first = $record->filmProducts()->orderBy('id')->value('film_product_id');

        if ($first !== null && (int) $first !== (int) $record->film_product_id) {
            Booking::query()->whereKey($record->getKey())->toBase()->update(['film_product_id' => $first]);
            $record->film_product_id = $first;
            $record->syncOriginalAttribute('film_product_id');
        }
    }

    /** Data inti booking yang sudah dikonfirmasi terkunci di form Ubah, kecuali akses penuh membuka kuncinya (toggle di form). */
    public static function coreLocked(?Booking $record, ?Forms\Get $get = null): bool
    {
        return $record?->status === 'confirmed' && ! ($get !== null && (bool) $get('core_unlocked'));
    }

    /** Kolom data inti yang tidak boleh berubah lewat form Ubah saat terkunci (penjaga server di EditBooking). */
    public const CORE_LOCKED_FIELDS = [
        'source', 'customer_id', 'customer_name', 'phone_number',
        'vehicle_plate', 'vehicle_brand', 'vehicle_model', 'vehicle_variant', 'vehicle_color', 'vehicle_year', 'vehicle_size', 'vehicle_power',
        'store_id', 'service_type', 'product_kaca_film', 'product_ppf', 'product_detailing', 'product_premium_wash',
        'ppf_area', 'ppf_exterior', 'ppf_interior', 'ppf_scope', 'preferred_date', 'preferred_time', 'duration_days',
    ];

    /** Hilangkan baris produk bagian "Interior" (Interior tidak lagi dipilih). */
    public static function withoutInteriorPart(array $rows): array
    {
        return collect($rows)->reject(fn ($row) => ($row['position'] ?? null) === Booking::PPF_INTERIOR_PART)->all();
    }

    /**
     * Setelah Eksterior atau Interior dicentang: Interior saja tidak punya cakupan Full Body/Part (dan daftar panelnya), Eksterior saja tidak punya bagian
     * Interior; lama pengerjaan bawaan menyesuaikan (Eksterior 3 hari, Interior saja 1 hari, keduanya tetap 3 hari) selama belum diubah manual.
     */
    public static function onPpfAreaChanged(Forms\Set $set, Forms\Get $get): void
    {
        $exterior = (bool) $get('ppf_exterior');
        $interior = (bool) $get('ppf_interior');

        if ($interior && ! $exterior) {
            $set('ppf_scope', null);
            $set('filmProducts', self::withoutPpfParts((array) $get('filmProducts')));
        }

        if (! $interior) {
            $set('filmProducts', self::withoutInteriorPart((array) $get('filmProducts')));
        }

        self::syncDuration($set, $get, true);
    }

    /** Isi lama pengerjaan bawaan hanya bila masih kosong atau masih salah satu angka bawaan, supaya durasi yang sengaja diubah staf tidak tertimpa. */
    public static function syncDuration(Forms\Set $set, Forms\Get $get, bool $isPpf): void
    {
        $current = $get('duration_days');
        $defaults = [Booking::DEFAULT_DURATION_DAYS_DEFAULT, Booking::DEFAULT_DURATION_DAYS_PPF, Booking::DEFAULT_DURATION_DAYS_PPF + Booking::DEFAULT_DURATION_DAYS_PPF_INTERIOR];

        if (! $current || in_array((int) $current, $defaults, true)) {
            $set('duration_days', Booking::defaultDurationDays($isPpf, (bool) $get('ppf_exterior'), (bool) $get('ppf_interior')));
        }
    }

    /** Buang baris panel PPF dari daftar Produk per Bagian (dipakai saat cakupan bukan Part), baris Window Film tetap. */
    public static function withoutPpfParts(array $rows): array
    {
        return collect($rows)
            ->reject(fn ($row) => in_array($row['position'] ?? null, array_values(Booking::PPF_PARTS), true))
            ->all();
    }

    /** Jenis produk yang cocok untuk sebuah bagian (panel PPF atau bagian kaca). */
    public static function productTypeForPart(string $position): string
    {
        return in_array($position, [...Booking::WF_AREAS, Booking::WF_ALL], true) ? 'window_film' : 'ppf';
    }

    /** Teks service_type dari pilihan jenis layanan: label dipisah koma, urutan tetap (sama dengan aplikasi pelanggan). */
    public static function serviceTypeLabel(?array $keys): string
    {
        return collect(\App\Models\BookingServiceType::FLAGS)
            ->keys()
            ->filter(fn ($key) => in_array($key, (array) $keys, true))
            ->map(fn ($key) => \App\Models\BookingServiceType::labelFor($key))
            ->implode(', ');
    }

}
