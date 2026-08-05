<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WarrantyResource\Pages;
use App\Filament\Resources\WarrantyResource\RelationManagers;
use App\Exports\WarrantyExport;
use App\Models\Customer;
use App\Models\FilmProduct;
use App\Models\ScrollCode;
use App\Models\Store;
use App\Models\Warranty;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class WarrantyResource extends Resource
{
    protected static ?string $model = Warranty::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Garansi';

    protected static ?string $modelLabel = 'Garansi';

    protected static ?string $pluralModelLabel = 'Garansi';

    /**
     * store_manager (admin toko) hanya melihat garansi milik store-nya,
     * atau data lama yang belum punya store_id. super_admin lihat semua.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where(function (Builder $q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        }

        return $query;
    }

    /**
     * Badge angka merah di sidebar — jumlah QA Certificate yang masih
     * menunggu review, supaya super_admin langsung tahu ada berapa yang
     * perlu ditindak begitu login.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('review_status', 'pending_review')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Logika approve/reject/extend garansi — SATU sumber kebenaran,
     * dipanggil dari 3 tempat yang sebelumnya copy-paste sendiri-sendiri
     * (table row action, EditWarranty, ViewWarranty). Cosmetic wiring
     * (label/icon/color/visible/form) tetap lokal di masing-masing
     * tempat karena jarang berubah dan beda API antara Tables\Actions\Action
     * vs Filament\Actions\Action — tapi mutasi datanya (bagian yang
     * paling rawan bug kalau lupa disinkronkan) sekarang cuma di sini.
     */
    public static function performApprove(Warranty $warranty): void
    {
        $warranty->update([
            'review_status'    => 'approved',
            'rejection_reason' => null,
            'reviewed_by'      => auth()->id(),
            'reviewed_at'      => now(),
        ]);

        Notification::make()->title('Garansi disetujui')->success()->send();
    }

    public static function performReject(Warranty $warranty, string $reason): void
    {
        $warranty->update([
            'review_status'    => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by'      => auth()->id(),
            'reviewed_at'      => now(),
        ]);

        Notification::make()->title('Garansi ditolak')->warning()->send();
    }

    public static function performExtend(Warranty $warranty, int $years): void
    {
        // Lock row-nya — SEBELUMNYA baca-lalu-tulis tanpa lock, jadi kalau
        // tombol "Perpanjang Garansi" diklik dobel/hampir bersamaan, dua
        // request bisa dua-duanya baca extension_years yang sama sebelum
        // salah satu commit (lost update) — satu klik perpanjangan bisa
        // hilang diam-diam.
        DB::transaction(function () use ($warranty, $years) {
            $locked = Warranty::where('id', $warranty->id)->lockForUpdate()->first();

            if (! $locked->original_expiry_date) {
                $locked->original_expiry_date = $locked->expiry_date;
            }

            $locked->extension_years += $years;
            $locked->expiry_date = Carbon::parse($locked->original_expiry_date)
                ->addYears($locked->extension_years);
            $locked->save();

            $warranty->refresh();
        });

        Notification::make()->title("Garansi diperpanjang +{$years} tahun")->success()->send();
    }

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->isFullAccess();

        return $form->schema([
            Forms\Components\Section::make('Data Garansi')
                ->columns(2)
                ->schema([
                    // Tidak diisi manual — nomor sekuensial GNV-PPF-00001 /
                    // GNV-WF-00001 dst, di-generate sekali begitu kode
                    // gulungan pertama diisi (lihat Warranty::booted()).
                    // Placeholder ini cuma info, bukan input.
                    Forms\Components\Placeholder::make('warranty_code_info')
                        ->label('Kode Garansi')
                        ->content(fn (?Warranty $record) => $record?->warranty_code
                            ?? 'Otomatis begitu Detail Instalasi di bawah diisi'),

                    Forms\Components\TextInput::make('customer_name')
                        ->label('Nama Pelanggan')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone_number')
                        ->label('No. Telepon')
                        ->tel()
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('customer_id')
                        ->label('Akun Customer (opsional)')
                        ->placeholder('Pilih akun customer jika sudah terdaftar di app')
                        ->options(fn () => Customer::orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Customer $c) => [
                                $c->id => trim(($c->name ?? 'Tanpa Nama') . ' — ' . $c->email),
                            ])
                        )
                        ->searchable()
                        ->nullable()
                        ->columnSpanFull()
                        ->helperText('Garansi akan langsung muncul di "Garansi Saya" pada mobile app customer tersebut.'),

                    Forms\Components\TextInput::make('car_plate')
                        ->label('Plat Nomor')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('car_type')
                        ->label('Tipe Mobil')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('product_series')
                        ->label('Seri Produk')
                        ->placeholder('Contoh: A70')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('product_category')
                        ->label('Kategori Produk')
                        ->options([
                            'window_film' => 'Window Film',
                            'ppf'         => 'PPF',
                        ])
                        ->live()
                        ->required(),

                    Forms\Components\TextInput::make('vin')
                        ->label('VIN (Nomor Rangka)')
                        ->helperText('Berbeda dari plat nomor — VIN permanen, penting untuk garansi jangka panjang.')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('dealer_name')
                        ->label('Nama Dealer (teks bebas)')
                        ->required()
                        ->maxLength(255),

                    // Hanya super_admin yang bisa pindah-pindahkan garansi
                    // antar store. Admin toko otomatis ke store miliknya.
                    Forms\Components\Select::make('store_id')
                        ->label('Toko/Dealer')
                        ->relationship('store', 'name')
                        ->searchable()
                        ->preload()
                        ->visible($isSuperAdmin)
                        ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id),

                    Forms\Components\DatePicker::make('installation_date')
                        ->label('Tanggal Pasang')
                        ->required(),

                    Forms\Components\DatePicker::make('expiry_date')
                        ->label('Tanggal Berakhir')
                        ->required(),
                ]),

            // Field kondisional berdasarkan kategori produk — supaya
            // staff toko tidak diminta isi field yang tidak relevan.
            // PPF: installation_position pilihan tetap (Seluruh Bodi/
            // Parsial) sesuai sertifikat China (装贴部位: [ ] 整车 [ ] 局部),
            // dengan keterangan detail area kalau Parsial dipilih.
            // Window Film: roll number & tipe film DIPISAH untuk Kaca
            // Depan vs Kaca Samping & Belakang, karena biasanya pakai
            // roll film yang berbeda (卷芯号 前挡 vs 侧后).
            Forms\Components\Section::make('Detail Instalasi (PPF)')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('installation_position')
                        ->label('Posisi Pemasangan')
                        ->options([
                            'full_body' => 'Seluruh Bodi',
                            'partial' => 'Parsial (Bagian Tertentu)',
                        ])
                        ->live()
                        ->helperText('Area mobil yang dilapisi PPF.'),

                    Forms\Components\TextInput::make('installation_position_detail')
                        ->label('Keterangan Area')
                        ->placeholder('Contoh: Bumper Depan, Kap Mesin, Fender')
                        ->visible(fn (Forms\Get $get) => $get('installation_position') === 'partial'),

                    Forms\Components\Select::make('roll_number')
                        ->label('Kode Gulungan')
                        ->placeholder('Pilih atau ketik kode dari gulungan fisik')
                        // Difilter product_type='ppf' lewat filmProduct-nya —
                        // sebelumnya query ini tidak difilter sama sekali,
                        // jadi kode gulungan Window Film ikut nyelip di sini.
                        // Kode yang sudah kepilih di roll_number_2 dikecualikan
                        // supaya staff tidak bisa pilih gulungan yang sama
                        // persis 2x untuk 1 mobil.
                        ->options(fn (?Warranty $record, Forms\Get $get) => ScrollCode::query()
                            ->whereHas('filmProduct', fn ($q) => $q->where('product_type', 'ppf'))
                            ->where(fn ($q) => $q
                                ->where('status', 'allocated')
                                ->orWhere('code', $record?->roll_number)
                            )
                            ->when($get('roll_number_2'), fn ($q, $excluded) => $q->where('code', '!=', $excluded))
                            ->pluck('code', 'code')
                        )
                        ->searchable()
                        ->live()
                        ->helperText('Pilih kode gulungan PPF yang digunakan. Kode tetap muncul di pilihan untuk mobil berikutnya sampai ditandai habis manual (menu Kode Gulungan → "Tandai Habis").'),

                    Forms\Components\Select::make('roll_number_2')
                        ->label('Kode Gulungan Kedua (opsional)')
                        ->placeholder('Pilih kalau mobil ini butuh 2 gulungan')
                        // Sebagian mobil (biasanya bodi besar) tidak cukup
                        // dilapisi 1 gulungan PPF — field ini opsional,
                        // diperlakukan SAMA PERSIS seperti roll_number
                        // (dipakai berkali-kali sampai ditandai habis manual,
                        // lihat WarrantyObserver).
                        ->options(fn (?Warranty $record, Forms\Get $get) => ScrollCode::query()
                            ->whereHas('filmProduct', fn ($q) => $q->where('product_type', 'ppf'))
                            ->where(fn ($q) => $q
                                ->where('status', 'allocated')
                                ->orWhere('code', $record?->roll_number_2)
                            )
                            ->when($get('roll_number'), fn ($q, $excluded) => $q->where('code', '!=', $excluded))
                            ->pluck('code', 'code')
                        )
                        ->searchable()
                        ->live()
                        // Exclude di options() di atas cuma UI (bisa lolos
                        // lewat back/forward browser atau race klik cepat)
                        // — rule ini yang benar-benar menegakkan di sisi
                        // server saat form disimpan.
                        ->rules(['different:roll_number'])
                        ->validationMessages(['different' => 'Kode Gulungan Kedua tidak boleh sama dengan Kode Gulungan (pertama).'])
                        ->helperText('Isi kalau 1 gulungan tidak cukup untuk mobil ini (mis. bodi besar). Kosongkan kalau cukup 1 gulungan.'),
                ])
                ->visible(fn (Forms\Get $get) => $get('product_category') === 'ppf'),

            Forms\Components\Section::make('Detail Instalasi (Window Film)')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('roll_number_front')
                        ->label('Kode Gulungan — Kaca Depan')
                        ->placeholder('Pilih kode gulungan kaca depan')
                        ->helperText('Cuma menampilkan gulungan yang terdaftar untuk posisi kaca depan. 1 gulungan bisa dipakai berkali-kali (~30 mobil) — tetap muncul di pilihan sampai ditandai habis manual. Kode Garansi mobil ini di-generate otomatis terpisah (GNV-WF-XXXXX), bukan dari kode gulungan ini.')
                        // Difilter product_type='window_film' + posisi
                        // 'front' lewat filmProduct-nya — supaya staff cuma
                        // lihat gulungan Window Film yang relevan untuk kaca
                        // depan (bukan ikut kecampur kode gulungan PPF).
                        // Kaca depan & samping/belakang selalu produk (seri)
                        // berbeda — tidak ada produk yang dipakai di
                        // keduanya, jadi tidak perlu whereIn('all').
                        ->options(fn (?Warranty $record) => ScrollCode::query()
                            ->whereHas('filmProduct', fn ($q) => $q
                                ->where('product_type', 'window_film')
                                ->where('position', 'front'))
                            ->where(fn ($q) => $q
                                ->where('status', 'allocated')
                                ->orWhere('code', $record?->roll_number_front)
                            )
                            ->with('filmProduct')
                            ->get()
                            ->mapWithKeys(fn (ScrollCode $sc) => [
                                $sc->code => $sc->filmProduct ? "{$sc->code} — {$sc->filmProduct->name}" : $sc->code,
                            ])
                        )
                        ->live()
                        ->searchable(),

                    Forms\Components\Select::make('roll_number_side_rear')
                        ->label('Kode Gulungan — Kaca Samping & Belakang')
                        ->placeholder('Pilih kode gulungan kaca samping & belakang')
                        ->helperText('Cuma menampilkan gulungan yang terdaftar untuk posisi kaca samping/belakang. Isi kalau mobil ini juga pasang di posisi ini.')
                        // Difilter product_type='window_film' + posisi
                        // 'side_rear' — sama seperti dropdown kaca depan
                        // di atas.
                        ->options(fn (?Warranty $record) => ScrollCode::query()
                            ->whereHas('filmProduct', fn ($q) => $q
                                ->where('product_type', 'window_film')
                                ->where('position', 'side_rear'))
                            ->where(fn ($q) => $q
                                ->where('status', 'allocated')
                                ->orWhere('code', $record?->roll_number_side_rear)
                            )
                            ->with('filmProduct')
                            ->get()
                            ->mapWithKeys(fn (ScrollCode $sc) => [
                                $sc->code => $sc->filmProduct ? "{$sc->code} — {$sc->filmProduct->name}" : $sc->code,
                            ])
                        )
                        ->live()
                        ->searchable(),

                    // Placeholder "Tipe Film" dihapus — cuma duplikat info
                    // yang sudah tampil di label opsi dropdown kode gulungan
                    // di atas ("{kode} — {nama produk}"), dan membingungkan
                    // di form Tambah Garansi baru karena Placeholder baca
                    // dari $record (baru terisi SETELAH save), jadi selalu
                    // kelihatan kosong walau kode gulungan sudah dipilih.
                    // Data film_model_front/film_model_side_rear sendiri
                    // TETAP disimpan (lihat Warranty::booted()) — masih
                    // dipakai di Export Excel & PDF E-Warranty.
                ])
                ->visible(fn (Forms\Get $get) => $get('product_category') === 'window_film'),

            // Section QA Certificate review — hanya ditampilkan sebagai
            // info (read-only) di form. Aksi approve/reject yang
            // sebenarnya dilakukan lewat tombol di tabel/halaman edit
            // (lihat getHeaderActions di EditWarranty), bukan field form,
            // supaya tidak bisa "diam-diam" diubah lewat save form biasa.
            Forms\Components\Section::make('Status Review (QA Certificate)')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('review_status')
                        ->label('Status Review')
                        ->content(fn (?Warranty $record) => match ($record?->review_status) {
                            'approved' => 'Approved',
                            'rejected' => 'Rejected',
                            default => 'Pending Review',
                        }),

                    Forms\Components\Placeholder::make('reviewed_at')
                        ->label('Direview Pada')
                        ->content(fn (?Warranty $record) => $record?->reviewed_at?->format('d M Y H:i') ?? '—'),

                    Forms\Components\Placeholder::make('rejection_reason')
                        ->label('Alasan Reject')
                        ->content(fn (?Warranty $record) => $record?->rejection_reason ?? '—')
                        ->columnSpanFull()
                        ->visible(fn (?Warranty $record) => $record?->review_status === 'rejected'),
                ])
                ->visible(fn (?Warranty $record) => $record !== null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('warranty_code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->searchable(),

                Tables\Columns\TextColumn::make('customer.email')
                    ->label('Akun App')
                    ->placeholder('—')
                    ->badge()
                    ->color('info')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('car_plate')
                    ->label('Plat Nomor')
                    ->searchable(),

                Tables\Columns\TextColumn::make('product_series')
                    ->label('Produk')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('product_category')
                    ->label('Kategori')
                    ->placeholder('—')
                    ->colors([
                        'info'    => 'window_film',
                        'success' => 'ppf',
                    ])
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'window_film' => 'Window Film',
                        'ppf'         => 'PPF',
                        default       => '—',
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('phone_number')
                    ->label('No. Telepon')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('review_status')
                    ->label('Review QA')
                    ->colors([
                        'warning' => 'pending_review',
                        'success' => 'approved',
                        'danger' => 'rejected',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending_review' => 'Pending Review',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        default => $state,
                    }),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status Garansi')
                    ->colors([
                        'success' => 'active',
                        'danger'  => fn ($state) => in_array($state, ['expired', 'rejected']),
                        'warning' => fn ($state) => in_array($state, ['pending', 'pending_review']),
                    ]),

                Tables\Columns\TextColumn::make('expiry_date')
                    ->label('Berakhir')
                    ->date('d M Y')
                    ->sortable()
                    ->description(fn ($record) => $record->extension_years > 0
                        ? "+{$record->extension_years} thn diperpanjang"
                        : null),

                Tables\Columns\TextColumn::make('remaining_days')
                    ->label('Sisa Hari')
                    ->sortable(false),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                // Export Excel untuk dikirim manual (email) ke tim Ginnva
                // China secara mingguan/bulanan. Ini PENGGANTI mekanisme
                // sync API realtime yang sebelumnya direncanakan (job
                // SyncWarrantyToChina) — per info resmi China (akhir Juni
                // 2026), mereka belum bisa sediakan API/data interface
                // karena ketentuan pemerintah. Hanya super_admin yang bisa
                // export, karena ini data sensitif (info pelanggan
                // lengkap) yang dikirim ke pihak luar.
                Tables\Actions\Action::make('exportExcel')
                    ->label('Export ke Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                    ->form([
                        Forms\Components\DatePicker::make('start_date')
                            ->label('Dari Tanggal (opsional)')
                            ->helperText('Kosongkan untuk export semua data dari awal.'),

                        Forms\Components\DatePicker::make('end_date')
                            ->label('Sampai Tanggal (opsional)')
                            ->helperText('Kosongkan untuk export sampai data terbaru.'),
                    ])
                    ->action(function (array $data) {
                        $filename = 'warranty-export-' . now()->format('Ymd-His') . '.xlsx';

                        return Excel::download(
                            new WarrantyExport($data['start_date'] ?? null, $data['end_date'] ?? null),
                            $filename
                        );
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('review_status')
                    ->label('Status Review QA')
                    ->options([
                        'pending_review' => 'Pending Review',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),

                // Opsi & hasil filter ini SENGAJA dibuat sama persis dengan
                // apa yang ditampilkan BadgeColumn 'status' di atas (yang
                // mengikuti accessor Warranty::getStatusAttribute(), bisa
                // menampilkan pending_review/rejected juga) — supaya staff
                // tidak bingung memfilter "Active" tapi hasilnya ada baris
                // yang badge-nya kelihatan "Pending Review" (kolom `status`
                // mentah di database cuma punya active/expired/pending,
                // beda dari apa yang sebenarnya ditampilkan ke staff).
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status Garansi')
                    ->options([
                        'active'         => 'Active',
                        'expired'        => 'Expired',
                        'pending'        => 'Pending',
                        'pending_review' => 'Pending Review',
                        'rejected'       => 'Rejected',
                    ])
                    ->query(function (Builder $query, array $data) {
                        $value = $data['value'] ?? null;

                        return match ($value) {
                            'pending_review' => $query->where('review_status', 'pending_review'),
                            'rejected'       => $query->where('review_status', 'rejected'),
                            'expired'        => $query->where('review_status', 'approved')
                                ->whereDate('expiry_date', '<', now()),
                            'active'         => $query->where('review_status', 'approved')
                                ->where('status', 'active')
                                ->whereDate('expiry_date', '>=', now()),
                            'pending'        => $query->where('review_status', 'approved')
                                ->where('status', 'pending')
                                ->whereDate('expiry_date', '>=', now()),
                            default          => $query,
                        };
                    }),

                Tables\Filters\SelectFilter::make('product_category')
                    ->label('Kategori Produk')
                    ->options([
                        'window_film' => 'Window Film',
                        'ppf'         => 'PPF',
                    ]),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),

                Tables\Filters\Filter::make('created_at')
                    ->label('Rentang Tanggal Diajukan')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari'),
                        Forms\Components\DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                // Approve/Reject hanya untuk super_admin, sesuai keputusan
                // akses QA review. Muncul hanya kalau masih pending_review.
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Warranty $record) => auth()->user()?->isFullAccess() && $record->review_status === 'pending_review')
                    ->requiresConfirmation()
                    ->action(fn (Warranty $record) => static::performApprove($record)),

                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Warranty $record) => auth()->user()?->isFullAccess() && $record->review_status === 'pending_review')
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')
                            ->label('Alasan Reject')
                            ->required(),
                    ])
                    ->action(fn (Warranty $record, array $data) => static::performReject($record, $data['rejection_reason'])),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                // SEBELUMNYA tidak dibatasi sama sekali — padahal Approve/
                // Reject di atas sengaja dikunci super_admin. Akibatnya
                // Store Manager biasa bisa hapus permanen garansi yang
                // sudah di-approve (plus riwayat klaimnya), padahal tidak
                // boleh sekalipun cuma reject. Disamakan aturannya.
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ClaimsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWarranties::route('/'),
            'create' => Pages\CreateWarranty::route('/create'),
            'view'   => Pages\ViewWarranty::route('/{record}'),
            'edit'   => Pages\EditWarranty::route('/{record}/edit'),
        ];
    }
}