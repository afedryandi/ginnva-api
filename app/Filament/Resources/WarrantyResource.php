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
use App\Models\WarrantyMaintenanceVisit;
use App\Models\WarrantyOwnershipTransfer;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class WarrantyResource extends Resource
{
    protected static ?string $model = Warranty::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $cluster = \App\Filament\Clusters\BookingCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 300-399 "Setelah Layanan".
    protected static ?string $navigationGroup = 'Setelah Layanan';

    protected static ?int $navigationSort = 300;

    protected static ?string $navigationLabel = 'Garansi';

    protected static ?string $modelLabel = 'Garansi';

    protected static ?string $pluralModelLabel = 'Garansi';

    /**
     * store_manager (admin toko) hanya melihat garansi milik store-nya,
     * atau data lama yang belum punya store_id. super_admin lihat semua.
     */
    public static function getEloquentQuery(): Builder
    {
        // withCount('activeMaintenanceVisits') (fitur Kuota Maintenance,
        // 2026-09-25; disesuaikan 2026-09-26 supaya kunjungan yang
        // di-cancel tidak ikut terhitung) -- supaya kolom "Maintenance" di
        // tabel bisa hitung sisa kuota tanpa N+1 query per baris
        // (Warranty::getMaintenanceUsedAttribute() pakai
        // active_maintenance_visits_count ini kalau sudah tersedia).
        $query = parent::getEloquentQuery()->withCount('activeMaintenanceVisits');
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

    /**
     * Gap DIPERBAIKI 2026-09-25 (audit Garansi, "tidak ada mekanisme
     * revoke/void") -- SEBELUMNYA satu-satunya jalan membatalkan garansi
     * yang sudah approved adalah Delete permanen, tidak ada status yang
     * menjaga riwayat. Revoke MENYIMPAN baris & seluruh riwayatnya (beda
     * dari Delete), status raw kolom `status` di-set 'revoked' (lihat
     * migrasi add_revoke_to_warranties_table & Warranty::
     * getStatusAttribute() yang memprioritaskan nilai ini). Customer
     * pemilik dikirim push -- konsisten dengan pola notifikasi yang
     * diperbaiki di ClaimsRelationManager & TechnicianResource sesi ini.
     */
    public static function performRevoke(Warranty $warranty, string $reason): void
    {
        $warranty->update([
            'status'        => 'revoked',
            'revoke_reason' => $reason,
            'revoked_by'    => auth()->id(),
            'revoked_at'    => now(),
        ]);

        if ($warranty->customer_id) {
            app(\App\Services\PushNotificationService::class)->sendToCustomer(
                $warranty->customer_id,
                'Garansi Dibatalkan',
                "Garansi #{$warranty->warranty_code} sudah dibatalkan: {$reason}"
            );
        }

        Notification::make()->title('Garansi dibatalkan (revoked)')->warning()->send();
    }

    /**
     * Gap DIPERBAIKI 2026-09-25 (audit Garansi, "transfer garansi ke
     * pemilik baru tidak dipertimbangkan sama sekali") -- wajar terjadi
     * di industri PPF/WF (mobil dijual, pemilik baru klaim sisa masa
     * berlaku). Keputusan user: staff-only, penerima BOLEH tanpa akun
     * (customer_id opsional, sama pola dengan garansi baru), riwayat
     * pemilik lama tercatat eksplisit di WarrantyOwnershipTransfer
     * (bukan cuma activity_log mentah) supaya bisa ditampilkan sebagai
     * timeline di halaman View.
     *
     * BUKAN mengedit customer_name/phone_number/customer_id langsung
     * lewat form biasa -- field itu SUDAH DIKUNCI begitu approved (lihat
     * fix sesi ini sebelumnya), transfer ini SATU-SATUNYA jalan resmi
     * mengubahnya setelah approved, supaya setiap perpindahan kepemilikan
     * SELALU tercatat sebagai transfer (bukan edit diam-diam yang tidak
     * ketahuan apakah itu koreksi typo atau ganti pemilik sungguhan).
     *
     * Dibungkus lockForUpdate() -- sama pola kehati-hatian dengan
     * performExtend(), mencegah 2 transfer nyaris bersamaan saling timpa.
     */
    public static function performOwnershipTransfer(Warranty $warranty, array $data): void
    {
        DB::transaction(function () use ($warranty, $data) {
            $locked = Warranty::where('id', $warranty->id)->lockForUpdate()->first();

            WarrantyOwnershipTransfer::create([
                'warranty_id'             => $locked->id,
                'previous_customer_id'    => $locked->customer_id,
                'previous_customer_name'  => $locked->customer_name,
                'previous_phone_number'   => $locked->phone_number,
                'new_customer_id'         => $data['new_customer_id'] ?? null,
                'new_customer_name'       => $data['new_customer_name'],
                'new_phone_number'        => $data['new_phone_number'] ?? null,
                'note'                    => $data['note'] ?? null,
                'transferred_by'          => auth()->id(),
                'transferred_at'          => now(),
            ]);

            $oldCustomerId = $locked->customer_id;

            $locked->update([
                'customer_id'    => $data['new_customer_id'] ?? null,
                'customer_name'  => $data['new_customer_name'],
                'phone_number'   => $data['new_phone_number'] ?? $locked->phone_number,
            ]);

            $push = app(\App\Services\PushNotificationService::class);

            if ($oldCustomerId) {
                $push->sendToCustomer(
                    $oldCustomerId,
                    'Kepemilikan Garansi Dipindahkan',
                    "Garansi #{$locked->warranty_code} sudah dipindahkan kepemilikannya ke pemilik baru dan tidak lagi muncul di akun ini."
                );
            }

            if (! empty($data['new_customer_id'])) {
                $push->sendToCustomer(
                    $data['new_customer_id'],
                    'Garansi Baru di Akun',
                    "Garansi #{$locked->warranty_code} sudah dipindahkan kepemilikannya ke akun ini."
                );
            }
        });

        Notification::make()->title('Kepemilikan garansi berhasil dipindahkan.')->success()->send();
    }

    /**
     * Fitur "Kuota Maintenance" (2026-09-25) -- customer instalasi PPF/WF
     * diberi kesempatan datang maintenance sampai N kali (maintenance_quota,
     * diisi staff per garansi). Dipanggil staff tiap customer datang
     * walk-in ke toko untuk maintenance (bukan lewat alur booking/jadwal
     * terpisah, per keputusan user).
     *
     * Dibungkus DB::transaction() + lockForUpdate() -- SAMA pola kehati-
     * hatian dengan performOwnershipTransfer()/performExtend(): mencegah 2
     * klik "Catat Kunjungan" nyaris bersamaan (2 tab/2 staff) sama-sama
     * lolos cek "kuota masih sisa" sebelum salah satu commit duluan, yang
     * bisa membuat jumlah kunjungan tercatat MELEBIHI kuota yang
     * dijanjikan. Validasi sisa kuota dicek ULANG di dalam lock (bukan
     * cuma di ->visible() action, yang cuma pengecekan UI longgar).
     */
    public static function performRecordMaintenanceVisit(Warranty $warranty, array $data): void
    {
        DB::transaction(function () use ($warranty, $data) {
            $locked = Warranty::where('id', $warranty->id)->lockForUpdate()->first();

            if ($locked->maintenance_quota === null) {
                Notification::make()
                    ->title('Garansi ini belum punya kuota maintenance')
                    ->danger()
                    ->send();

                return;
            }

            // activeMaintenanceVisits() SUDAH mencakup kunjungan otomatis:
            // BookingObserver mencatat 1 baris WarrantyMaintenanceVisit tiap
            // booking Maintenance PPF 'completed'. Jangan tambahkan hitungan
            // schedule 'completed' lagi di sini -- jadi dobel (diperbaiki
            // 2026-10-02).
            $used = $locked->activeMaintenanceVisits()->count();
            if ($used >= $locked->maintenance_quota) {
                Notification::make()
                    ->title('Kuota maintenance sudah habis')
                    ->body("Garansi #{$locked->warranty_code} sudah memakai {$used}/{$locked->maintenance_quota} kunjungan.")
                    ->danger()
                    ->send();

                return;
            }

            $visit = WarrantyMaintenanceVisit::create([
                'warranty_id'  => $locked->id,
                'visited_at'   => $data['visited_at'],
                'note'         => $data['note'] ?? null,
                'recorded_by'  => auth()->id(),
            ]);

            // Poin kunjungan maintenance (0 = nonaktif, lihat config/loyalty.php).
            try {
                app(\App\Services\MaintenanceVisitPointService::class)->award($visit);
            } catch (\Throwable $e) {
                report($e);
            }

            $remaining = $locked->maintenance_quota - ($used + 1);

            // Pemberitahuan ke customer tidak boleh membatalkan pencatatan kunjungan yang sudah sah.
            if ($locked->customer_id) {
                try {
                    app(\App\Services\PushNotificationService::class)->sendToCustomer(
                        $locked->customer_id,
                        'Kunjungan Maintenance Tercatat',
                        $remaining > 0
                            ? "Kunjungan maintenance garansi #{$locked->warranty_code} tercatat. Sisa {$remaining} kunjungan lagi."
                            : "Kunjungan maintenance garansi #{$locked->warranty_code} tercatat. Kuota maintenance sudah habis."
                    );
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            Notification::make()
                ->title('Kunjungan maintenance dicatat')
                ->body("Sisa kuota: {$remaining}/{$locked->maintenance_quota}")
                ->success()
                ->send();
        });
    }

    /**
     * Gap "koreksi kunjungan salah catat" diperbaiki 2026-09-26 (audit
     * ulang Garansi) -- SEBELUMNYA kunjungan yang salah catat (salah
     * tanggal/salah pilih garansi) tidak bisa dikoreksi sama sekali, kuota
     * customer berkurang permanen akibat kesalahan staff. Full-access
     * only (beda dari performRecordMaintenanceVisit() yang memang untuk
     * staff toko biasa) -- membatalkan kunjungan mengembalikan kuota,
     * bukan aksi operasional rutin. SOFT-cancel (baris tetap ada, ditandai
     * batal) -- bukan hard-delete, supaya riwayat kesalahan tetap
     * tercatat (lihat WarrantyMaintenanceVisit::isCancelled()).
     */
    public static function performCancelMaintenanceVisit(Warranty $warranty, array $data): void
    {
        DB::transaction(function () use ($warranty, $data) {
            $visit = WarrantyMaintenanceVisit::where('id', $data['visit_id'])
                ->where('warranty_id', $warranty->id)
                ->whereNull('cancelled_at')
                ->lockForUpdate()
                ->first();

            if (! $visit) {
                Notification::make()
                    ->title('Kunjungan tidak ditemukan atau sudah dibatalkan')
                    ->danger()
                    ->send();

                return;
            }

            $visit->update([
                'cancelled_at'   => now(),
                'cancelled_by'   => auth()->id(),
                'cancel_reason'  => $data['cancel_reason'],
            ]);

            // Poin dari kunjungan yang salah catat ikut ditarik kembali.
            try {
                app(\App\Services\MaintenanceVisitPointService::class)->revoke($visit);
            } catch (\Throwable $e) {
                report($e);
            }

            if ($warranty->customer_id) {
                app(\App\Services\PushNotificationService::class)->sendToCustomer(
                    $warranty->customer_id,
                    'Kunjungan Maintenance Dibatalkan',
                    "Pencatatan kunjungan maintenance tanggal {$visit->visited_at->format('d M Y')} pada garansi #{$warranty->warranty_code} dibatalkan (kesalahan input). Kuota sudah dikembalikan."
                );
            }

            Notification::make()->title('Kunjungan dibatalkan, kuota dikembalikan')->success()->send();
        });
    }

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->isFullAccess();

        // Dua kolom di layar lebar: kiri isi garansi (pelanggan, kendaraan, perawatan, pemasangan), kanan status review.
        // Saat membuat garansi baru, kolom kanan berisi panduan singkat (status review baru ada setelah garansi tersimpan).
        return $form->schema([
            Forms\Components\Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
            Forms\Components\Group::make()
                ->columnSpan(['lg' => 2])
                ->schema([
            Forms\Components\Section::make('Pelanggan')
                ->description('Pemilik garansi dan akun aplikasinya.')
                ->icon('heroicon-o-user')
                ->columns(['default' => 1, 'sm' => 2])
                ->schema([
                    // Tidak diisi manual — nomor sekuensial GNV-PPF-00001 /
                    // GNV-WF-00001 dst, di-generate sekali begitu kode
                    // gulungan pertama diisi (lihat Warranty::booted()).
                    // Placeholder ini cuma info, bukan input.
                    Forms\Components\Placeholder::make('warranty_code_info')
                        ->label('Kode Garansi')
                        ->columnSpanFull()
                        ->content(fn (?Warranty $record) => $record?->warranty_code
                            ?? 'Otomatis begitu Detail Instalasi di bawah diisi'),

                    // BUG DIPERBAIKI 2026-09-25 (audit Garansi): SEBELUMNYA
                    // field inti sertifikat (customer_name, car_plate, vin,
                    // dealer_name, product_series, installation_date, dst)
                    // TIDAK PERNAH dikunci berdasar review_status -- staff/
                    // super_admin bisa edit bebas SETELAH garansi 'approved'
                    // & PDF-nya sudah didownload customer, membuat dokumen
                    // fisik/PDF yang sudah beredar tidak sinkron dengan
                    // database, tanpa re-review atau notifikasi apa pun.
                    // Dikunci PENUH (bukan cuma full-access) begitu approved
                    // -- konsisten dengan pola booking_id di SPK/Invoice
                    // sesi ini (kalau memang salah input, revoke/buat baru,
                    // bukan edit diam-diam; revoke belum ada, dicatat
                    // sebagai gap terpisah). expiry_date TETAP bisa diubah
                    // lewat aksi "Perpanjang Garansi" (performExtend(),
                    // tidak lewat form ini sama sekali).
                    Forms\Components\TextInput::make('customer_name')
                        ->label('Nama Pelanggan')
                        ->required()
                        ->maxLength(255)
                        ->disabled(fn (?Warranty $record) => $record?->review_status === 'approved'),

                    Forms\Components\TextInput::make('phone_number')
                        ->label('No. Telepon')
                        ->tel()
                        ->required()
                        ->maxLength(255)
                        ->disabled(fn (?Warranty $record) => $record?->review_status === 'approved'),

                    Forms\Components\Select::make('customer_id')
                        ->label('Akun Pelanggan (opsional)')
                        ->placeholder('Pilih akun pelanggan kalau sudah terdaftar di app')
                        ->options(fn () => Customer::orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Customer $c) => [
                                $c->id => trim(($c->name ?? 'Tanpa Nama') . ' — ' . $c->email),
                            ])
                        )
                        ->searchable()
                        ->nullable()
                        ->columnSpanFull()
                        ->helperText('Garansi akan langsung muncul di "Garansi Saya" pada mobile app pelanggan itu.')
                        // Dikunci begitu approved (2026-09-25, dibarengi
                        // fitur transfer kepemilikan) -- KALAU field ini
                        // tetap bebas diedit, staff bisa ganti pemilik
                        // lewat sini tanpa tercatat di
                        // WarrantyOwnershipTransfer sama sekali, membuat
                        // seluruh mekanisme transfer & riwayatnya
                        // percuma. Aksi "Transfer Kepemilikan" adalah
                        // SATU-SATUNYA jalan resmi ganti pemilik setelah
                        // approved.
                        ->disabled(fn (?Warranty $record) => $record?->review_status === 'approved'),

                    // Riwayat kepemilikan (audit Garansi 2026-09-25, gap
                    // "transfer ke pemilik baru") -- MURNI baca, ditampilkan
                    // langsung di sini (bukan halaman/infolist terpisah)
                    // karena WarrantyResource memang tidak punya infolist()
                    // sendiri, ViewWarranty jatuh ke form disabled bawaan.
                    Forms\Components\Placeholder::make('ownership_history')
                        ->label('Riwayat Kepemilikan')
                        ->columnSpanFull()
                        ->visible(fn (?Warranty $record) => $record && $record->ownershipTransfers->isNotEmpty())
                        ->content(function (?Warranty $record) {
                            if (! $record) {
                                return '';
                            }

                            $lines = $record->ownershipTransfers->map(function (WarrantyOwnershipTransfer $t) {
                                $when = $t->transferred_at?->format('d M Y H:i');
                                $by = $t->transferredBy?->name ?? 'Sistem';

                                return "{$t->previous_customer_name} → {$t->new_customer_name} ({$when}, oleh {$by})"
                                    . ($t->note ? " — {$t->note}" : '');
                            })->all();

                            return new \Illuminate\Support\HtmlString(implode('<br>', array_map('e', $lines)));
                        }),

                ]),

            Forms\Components\Section::make('Kendaraan & Produk')
                ->icon('heroicon-o-shield-check')
                ->columns(['default' => 1, 'sm' => 2])
                ->schema([
                    Forms\Components\TextInput::make('car_plate')
                        ->label('Plat Nomor')
                        ->required()
                        ->maxLength(255)
                        ->disabled(fn (?Warranty $record) => $record?->review_status === 'approved'),

                    Forms\Components\TextInput::make('car_type')
                        ->label('Tipe Mobil')
                        ->required()
                        ->maxLength(255)
                        ->disabled(fn (?Warranty $record) => $record?->review_status === 'approved'),

                    Forms\Components\TextInput::make('product_series')
                        ->label('Seri Produk')
                        ->placeholder('Contoh: A70')
                        ->required()
                        ->maxLength(255)
                        ->disabled(fn (?Warranty $record) => $record?->review_status === 'approved'),

                    Forms\Components\Select::make('product_category')
                        ->label('Kategori Produk')
                        ->options([
                            'window_film' => 'Window Film',
                            'ppf'         => 'PPF',
                        ])
                        ->live()
                        ->required()
                        ->disabled(fn (?Warranty $record) => $record?->review_status === 'approved'),

                    // Blok Maintenance dipindah ke sini (2026-10-12, laporan user): sebelumnya ada di ATAS Kategori Produk, jadi
                    // kolom Kuota Maintenance yang muncul saat memilih PPF tampil di atasnya. Sekarang tepat di bawah Kategori Produk.
                    // Fitur "Kuota Maintenance" (2026-09-25) -- berapa kali
                    // customer ini boleh datang maintenance setelah
                    // instalasi. Nullable (kosongkan kalau garansi ini
                    // memang tidak ditawarkan maintenance) -- TETAP bisa
                    // diedit kapan saja (tidak dikunci seperti field
                    // sertifikat lain) supaya staff bisa naikkan kuota
                    // untuk kasus goodwill tanpa perlu aksi terpisah.
                    // Gap ditutup 2026-10-01 (audit Bagian C) -- SEBELUMNYA
                    // tidak dibatasi product_category sama sekali, staff bisa
                    // (tanpa sadar) mengaktifkan maintenance untuk garansi
                    // Kaca Film/Window Film juga, padahal fitur ini (plus
                    // maintenance_interval_months di bawah) eksplisit khusus PPF.
                ]),

            Forms\Components\Section::make('Perawatan Berkala')
                ->icon('heroicon-o-wrench-screwdriver')
                ->columns(['default' => 1, 'sm' => 2])
                ->visible(fn (Forms\Get $get, ?Warranty $record) => $get('product_category') === 'ppf'
                    || ($record && $record->maintenanceVisits->isNotEmpty()))
                ->schema([
                    Forms\Components\TextInput::make('maintenance_quota')
                        ->label('Kuota Maintenance (kali)')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(50)
                        ->nullable()
                        ->live()
                        ->visible(fn (Forms\Get $get) => $get('product_category') === 'ppf')
                        ->helperText('Berapa kali pelanggan boleh datang maintenance setelah instalasi. Kosongkan kalau garansi ini tidak menawarkan maintenance.'),

                    // Bagian C, "Klaim Garansi & Maintenance PPF" (2026-10-01)
                    // -- interval antar kunjungan. Mengisi field ini (dari
                    // kosong) otomatis membuat jadwal pertama & mulai siklus
                    // konfirmasi-otomatis (lihat WarrantyObserver::updated()) --
                    // staff TIDAK perlu input tanggal manual sama sekali
                    // setelah ini, sistem yang hitung & jalankan semuanya.
                    Forms\Components\TextInput::make('maintenance_interval_months')
                        ->label('Interval Maintenance (bulan)')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(120)
                        ->nullable()
                        ->visible(fn (Forms\Get $get) => $get('product_category') === 'ppf' && $get('maintenance_quota') !== null)
                        ->helperText('Jarak antar kunjungan maintenance, mis. 6 = tiap 6 bulan. Jadwal baru berjalan setelah garansi disetujui. Riwayat kunjungan ada di tab Jadwal Maintenance.'),

                    Forms\Components\Placeholder::make('maintenance_schedule_status')
                        ->label('Jadwal Maintenance Aktif')
                        ->visible(fn (?Warranty $record) => $record?->product_category === 'ppf' && $record?->activeMaintenanceSchedule !== null)
                        ->content(function (?Warranty $record) {
                            $schedule = $record?->activeMaintenanceSchedule;
                            if (! $schedule) {
                                return '';
                            }

                            $statusLabel = match ($schedule->status) {
                                'confirmation_sent' => 'Menunggu konfirmasi pelanggan',
                                default => 'Menunggu jadwal (belum masuk window pengingat)',
                            };

                            return "Ke-{$schedule->sequence} dari {$record->maintenance_quota} — {$schedule->scheduled_date->format('d M Y')} ({$statusLabel})";
                        }),

                    Forms\Components\Placeholder::make('maintenance_history')
                        ->label('Riwayat Kunjungan Maintenance')
                        ->columnSpanFull()
                        ->visible(fn (?Warranty $record) => $record && $record->maintenanceVisits->isNotEmpty())
                        ->content(function (?Warranty $record) {
                            if (! $record) {
                                return '';
                            }

                            $used = $record->maintenanceVisits->whereNull('cancelled_at')->count();
                            $quota = $record->maintenance_quota;

                            $lines = $record->maintenanceVisits->map(function (WarrantyMaintenanceVisit $v) {
                                $when = $v->visited_at?->format('d M Y');
                                $by = $v->recordedBy?->name ?? 'Sistem';

                                if ($v->isCancelled()) {
                                    $cancelledBy = $v->cancelledBy?->name ?? 'Sistem';

                                    return "{$when} (dicatat oleh {$by}) — DIBATALKAN oleh {$cancelledBy}: {$v->cancel_reason}";
                                }

                                return "{$when} (dicatat oleh {$by})" . ($v->note ? " — {$v->note}" : '');
                            })->all();

                            $header = "Terpakai {$used}/{$quota} kunjungan:";

                            return new \Illuminate\Support\HtmlString($header . '<br>' . implode('<br>', array_map('e', $lines)));
                        }),

                ]),

            Forms\Components\Section::make('Pemasangan & Masa Berlaku')
                ->icon('heroicon-o-calendar-days')
                ->columns(['default' => 1, 'sm' => 2])
                ->schema([
                    Forms\Components\TextInput::make('vin')
                        ->label('VIN (Nomor Rangka)')
                        ->helperText('Berbeda dari plat nomor — VIN permanen, penting untuk garansi jangka panjang.')
                        ->maxLength(255)
                        ->disabled(fn (?Warranty $record) => $record?->review_status === 'approved'),

                    Forms\Components\TextInput::make('dealer_name')
                        ->label('Nama Dealer (teks bebas)')
                        ->required()
                        ->maxLength(255)
                        ->disabled(fn (?Warranty $record) => $record?->review_status === 'approved'),

                    // Hanya super_admin yang bisa pindah-pindahkan garansi
                    // antar store. Admin toko otomatis ke store miliknya.
                    Forms\Components\Select::make('store_id')
                        ->label('Toko/Dealer')
                        ->relationship('store', 'name')
                        ->searchable()
                        ->preload()
                        ->visible($isSuperAdmin)
                        ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id)
                        // ->live() WAJIB — dropdown Kode Gulungan di bawah
                        // (Detail Instalasi) baca $get('store_id') untuk
                        // filter per toko (lihat perbaikan di sana). Tanpa
                        // ->live(), ganti Toko oleh super_admin tidak
                        // memicu refresh pilihan kode gulungan sampai ada
                        // interaksi live lain. Ditemukan lewat testing
                        // manual 2026-08-31.
                        ->live(),

                    Forms\Components\DatePicker::make('installation_date')
                        ->label('Tanggal Pasang')
                        ->live()
                        ->required()
                        ->disabled(fn (?Warranty $record) => $record?->review_status === 'approved'),

                    // SEBELUMNYA tidak ada validasi sama sekali yang
                    // mengaitkan expiry_date ke installation_date — beda
                    // dari endpoint API publik (WarrantyController::
                    // submit()) yang sudah punya 'after:installation_date'.
                    // Staff bisa simpan Tanggal Berakhir yang lebih awal
                    // dari Tanggal Pasang tanpa ditolak. Ditemukan lewat
                    // testing manual 2026-08-31.
                    Forms\Components\DatePicker::make('expiry_date')
                        ->label('Tanggal Berakhir')
                        ->required()
                        ->after('installation_date')
                        ->validationMessages([
                            'after' => 'Tanggal Berakhir harus setelah Tanggal Pasang.',
                        ])
                        ->disabled(fn (?Warranty $record) => $record?->review_status === 'approved')
                        ->helperText(fn (?Warranty $record) => $record?->review_status === 'approved'
                            ? 'Sudah disetujui — pakai tombol "Perpanjang Garansi" di atas untuk mengubah tanggal ini.'
                            : null),
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
                ->icon('heroicon-o-sparkles')
                ->columns(['default' => 1, 'sm' => 2])
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
                        // SEBELUMNYA tidak difilter store_id sama sekali —
                        // staff toko A bisa pilih kode gulungan yang
                        // dialokasikan ke toko B (barang fisiknya belum
                        // tentu ada di toko A). Difilter ke toko yang sama
                        // dengan field 'store_id' di atas, sama pola dengan
                        // Booking::installers/Teknisi. Ditemukan lewat
                        // testing manual 2026-08-31.
                        ->options(fn (?Warranty $record, Forms\Get $get) => ScrollCode::query()
                            ->when($get('store_id'), fn ($q, $storeId) => $q->where('store_id', $storeId))
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
                        ->helperText('Pilih kode gulungan PPF yang dipakai. Kode tetap muncul untuk mobil berikutnya sampai ditandai habis (Produk PPF/WF, Tandai Habis).'),

                    Forms\Components\Select::make('roll_number_2')
                        ->label('Kode Gulungan Kedua (opsional)')
                        ->placeholder('Pilih kalau mobil ini butuh 2 gulungan')
                        // Sebagian mobil (biasanya bodi besar) tidak cukup
                        // dilapisi 1 gulungan PPF — field ini opsional,
                        // diperlakukan SAMA PERSIS seperti roll_number
                        // (dipakai berkali-kali sampai ditandai habis manual,
                        // lihat WarrantyObserver).
                        // Sama seperti roll_number di atas — difilter store_id.
                        ->options(fn (?Warranty $record, Forms\Get $get) => ScrollCode::query()
                            ->when($get('store_id'), fn ($q, $storeId) => $q->where('store_id', $storeId))
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
                ->icon('heroicon-o-sun')
                ->columns(['default' => 1, 'sm' => 2])
                ->schema([
                    Forms\Components\Select::make('roll_number_front')
                        ->label('Kode Gulungan — Kaca Depan')
                        ->placeholder('Pilih kode gulungan kaca depan')
                        ->helperText('Hanya gulungan untuk posisi kaca depan. Satu gulungan bisa dipakai banyak mobil sampai ditandai habis. Kode Garansi dibuat terpisah.')
                        // Difilter product_type='window_film' + posisi
                        // 'front' lewat filmProduct-nya — supaya staff cuma
                        // lihat gulungan Window Film yang relevan untuk kaca
                        // depan (bukan ikut kecampur kode gulungan PPF).
                        // Kaca depan & samping/belakang selalu produk (seri)
                        // berbeda — tidak ada produk yang dipakai di
                        // keduanya, jadi tidak perlu whereIn('all').
                        // Difilter store_id juga — sama alasan dengan
                        // roll_number (PPF) di atas.
                        ->options(fn (?Warranty $record, Forms\Get $get) => ScrollCode::query()
                            ->when($get('store_id'), fn ($q, $storeId) => $q->where('store_id', $storeId))
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
                        // Difilter store_id juga — sama alasan dengan
                        // roll_number (PPF) di atas.
                        ->options(fn (?Warranty $record, Forms\Get $get) => ScrollCode::query()
                            ->when($get('store_id'), fn ($q, $storeId) => $q->where('store_id', $storeId))
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
                ]),
            Forms\Components\Group::make()
                ->columnSpan(['lg' => 1])
                ->schema([
            Forms\Components\Section::make('Panduan Pengisian')
                ->icon('heroicon-o-light-bulb')
                ->visible(fn (?Warranty $record) => $record === null)
                ->schema([
                    Forms\Components\Placeholder::make('create_tips')
                        ->hiddenLabel()
                        ->content(new \Illuminate\Support\HtmlString(
                            '<ul style="margin:0;padding-left:1.1rem;list-style:disc;line-height:1.7;">'
                            . '<li>Pilih <strong>akun pelanggan</strong> supaya garansi langsung muncul di "Garansi Saya" pada aplikasi mereka.</li>'
                            . '<li>Pilih <strong>kategori produk</strong> dulu: isian Detail Instalasi (PPF atau Window Film) menyesuaikan.</li>'
                            . '<li><strong>Kode garansi</strong> dibuat otomatis begitu kode gulungan pertama diisi.</li>'
                            . '<li>Kode gulungan yang tampil hanya milik <strong>toko yang dipilih</strong>.</li>'
                            . '<li>Garansi baru berstatus <strong>Pending Review</strong> sampai disetujui QA.</li>'
                            . '</ul>'
                        )),
                ]),

            Forms\Components\Section::make('Status Review (QA Certificate)')
                ->icon('heroicon-o-check-badge')
                ->columns(1)
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
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('warranty_code')
                    ->label('Kode')
                    ->weight('semibold')
                    ->copyable()
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('display_customer_name')
                    ->label('Pelanggan')
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('customer_name', 'like', "%{$search}%")),

                Tables\Columns\TextColumn::make('customer.email')
                    ->label('Akun App')
                    ->placeholder('—')
                    ->badge()
                    ->color('info')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('car_plate')
                    ->label('Plat Nomor')
                    ->badge()
                    ->color('gray')
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

                Tables\Columns\TextColumn::make('display_phone_number')
                    ->label('No. Telepon')
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('phone_number', 'like', "%{$search}%"))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('review_status')
                    ->label('Review QA')
                    ->tooltip(fn (?string $state): ?string => \App\Support\Tooltips::warrantyReview($state))
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
                    ->tooltip(fn (?string $state): ?string => \App\Support\Tooltips::warrantyStatus($state))
                    ->formatStateUsing(fn (string $state): string => $state === 'revoked' ? 'Revoked' : $state)
                    ->colors([
                        'success' => 'active',
                        'danger'  => fn ($state) => in_array($state, ['expired', 'rejected', 'revoked']),
                        // 'pending' DIHAPUS dari sini — kolom mentah `status`
                        // di database cuma pernah diisi 'active' (lihat
                        // WarrantyController::submit()), tidak ada alur
                        // manapun yang set 'pending', dan accessor
                        // getStatusAttribute() juga tidak pernah
                        // mengembalikannya. Opsi ini mubazir/tidak pernah
                        // ke-trigger. Lihat audit modul Garansi 2026-08-27.
                        'warning' => 'pending_review',
                    ]),

                Tables\Columns\TextColumn::make('expiry_date')
                    ->label('Berakhir')
                    ->date('d M Y')
                    ->sortable()
                    ->description(fn ($record) => $record->extension_years > 0
                        ? "+{$record->extension_years} thn diperpanjang"
                        : null),

                // Highlight kuning kalau sisa hari sedikit — SEBELUMNYA
                // tidak ada penekanan visual apa pun, staff harus baca
                // angkanya satu-satu buat triase mana yang mendesak. Cuma
                // untuk garansi yang benar-benar aktif (approved & belum
                // lewat expiry) — 'pending_review'/'rejected'/'expired'
                // tidak relevan diberi warning "akan habis". Lihat audit
                // modul Garansi 2026-08-27.
                Tables\Columns\TextColumn::make('remaining_days')
                    ->label('Sisa Hari')
                    ->sortable(false)
                    ->color(fn ($record) => $record->status === 'active' && $record->remaining_days <= 30
                        ? 'warning'
                        : null),

                // Fitur "Kuota Maintenance" (2026-09-25). Pakai
                // active_maintenance_visits_count dari getEloquentQuery()
                // withCount() -- HINDARI panggil accessor
                // getMaintenanceUsedAttribute() langsung di sini, yang
                // fallback ke query per-baris kalau count belum tersedia.
                Tables\Columns\TextColumn::make('maintenance_quota')
                    ->label('Maintenance')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?int $state, $record) => $state === null ? null : "{$record->active_maintenance_visits_count}/{$state} dipakai")
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                        'pending_review' => 'Pending Review',
                        'rejected'       => 'Rejected',
                        // 'revoked' ditambah 2026-09-25 (gap "revoke/void"
                        // diperbaiki, audit Garansi) — lihat performRevoke().
                        'revoked'        => 'Revoked',
                        // 'pending' DIHAPUS — kolom mentah `status` di
                        // database tidak pernah diisi nilai itu di alur
                        // manapun, opsi ini mubazir/tidak pernah
                        // menghasilkan baris. Lihat audit modul Garansi
                        // 2026-08-27.
                    ])
                    ->query(function (Builder $query, array $data) {
                        $value = $data['value'] ?? null;

                        return match ($value) {
                            'pending_review' => $query->where('review_status', 'pending_review'),
                            'rejected'       => $query->where('review_status', 'rejected'),
                            'revoked'        => $query->where('review_status', 'approved')
                                ->where('status', 'revoked'),
                            'expired'        => $query->where('review_status', 'approved')
                                ->where('status', '!=', 'revoked')
                                ->whereDate('expiry_date', '<', now()),
                            'active'         => $query->where('review_status', 'approved')
                                ->where('status', 'active')
                                ->whereDate('expiry_date', '>=', now()),
                            default          => $query,
                        };
                    }),

                // Quick-filter triase — SEBELUMNYA staff harus filter
                // "Active" lalu urutkan manual by "Berakhir" untuk cari
                // yang mendesak. Sama pola dengan filter "upcoming" di
                // BookingResource/BlockedDateResource. Lihat audit modul
                // Garansi 2026-08-27.
                // Pilihan (bukan kotak centang) supaya tinggi dan labelnya sejajar dengan isian lain di baris filter.
                Tables\Filters\TernaryFilter::make('expiring_soon')
                    ->label('Masa Berlaku')
                    ->placeholder('Semua')
                    ->trueLabel('Berakhir dalam 30 hari')
                    ->falseLabel('Lebih dari 30 hari')
                    ->queries(
                        true: fn (Builder $query) => $query
                            ->where('review_status', 'approved')
                            ->whereDate('expiry_date', '>=', now())
                            ->whereDate('expiry_date', '<=', now()->addDays(30)),
                        false: fn (Builder $query) => $query
                            ->where('review_status', 'approved')
                            ->whereDate('expiry_date', '>', now()->addDays(30)),
                        blank: fn (Builder $query) => $query,
                    ),

                Tables\Filters\SelectFilter::make('product_category')
                    ->label('Kategori Produk')
                    ->options([
                        'window_film' => 'Window Film',
                        'ppf'         => 'PPF',
                    ]),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->searchable()
                    ->preload()
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),

                Tables\Filters\Filter::make('created_at')
                    ->label('Rentang Tanggal Diajukan')
                    // Dua kolom selebar dua sel filter, jadi Dari dan Sampai bersebelahan dalam satu baris (sebelumnya bertumpuk di satu sel).
                    ->columnSpan(2)
                    ->columns(2)
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari'),
                        Forms\Components\DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            // Lihat, Ubah, Approve, dan Reject sebagai ikon; sisanya dikelompokkan di menu "...", supaya baris tidak penuh tombol.
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton()->tooltip('Lihat detail'),
                Tables\Actions\EditAction::make()->iconButton()->tooltip('Ubah'),

                // Approve/Reject hanya untuk super_admin, sesuai keputusan
                // akses QA review. Muncul hanya kalau masih pending_review.
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->iconButton()
                    ->tooltip('Approve')
                    ->visible(fn (Warranty $record) => auth()->user()?->isFullAccess() && $record->review_status === 'pending_review')
                    ->requiresConfirmation()
                    ->action(fn (Warranty $record) => static::performApprove($record)),

                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->iconButton()
                    ->tooltip('Reject')
                    ->visible(fn (Warranty $record) => auth()->user()?->isFullAccess() && $record->review_status === 'pending_review')
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')
                            ->label('Alasan Reject')
                            ->required(),
                    ])
                    ->action(fn (Warranty $record, array $data) => static::performReject($record, $data['rejection_reason'])),

                Tables\Actions\ActionGroup::make([
                // Gap "revoke/void" diperbaiki 2026-09-25 (audit Garansi)
                // -- lihat performRevoke() di atas.
                Tables\Actions\Action::make('revoke')
                    ->label('Batalkan Garansi')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (Warranty $record) => auth()->user()?->isFullAccess()
                        && $record->review_status === 'approved'
                        && $record->status !== 'revoked')
                    ->form([
                        Forms\Components\Textarea::make('revoke_reason')
                            ->label('Alasan Pembatalan')
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Garansi yang dibatalkan TIDAK bisa diaktifkan lagi lewat aksi ini — riwayatnya tetap tersimpan (beda dari Delete).')
                    ->action(fn (Warranty $record, array $data) => static::performRevoke($record, $data['revoke_reason'])),

                // Gap "transfer ke pemilik baru" diperbaiki 2026-09-25
                // (audit Garansi) -- lihat performOwnershipTransfer().
                Tables\Actions\Action::make('transfer_ownership')
                    ->label('Transfer Kepemilikan')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('gray')
                    ->visible(fn (Warranty $record) => auth()->user()?->isFullAccess()
                        && $record->review_status === 'approved'
                        && $record->status !== 'revoked')
                    ->form(fn (Warranty $record) => [
                        Forms\Components\Placeholder::make('current_owner_info')
                            ->label('Pemilik Saat Ini')
                            ->content($record->display_customer_name . ($record->display_phone_number !== '—' ? " ({$record->display_phone_number})" : '')),

                        Forms\Components\TextInput::make('new_customer_name')
                            ->label('Nama Pemilik Baru')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('new_phone_number')
                            ->label('No. Telepon Pemilik Baru')
                            ->tel()
                            ->maxLength(255),

                        Forms\Components\Select::make('new_customer_id')
                            ->label('Akun Pelanggan Baru (opsional)')
                            ->placeholder('Pilih kalau pemilik baru sudah punya akun app')
                            ->options(fn () => Customer::orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (Customer $c) => [
                                    $c->id => trim(($c->name ?? 'Tanpa Nama') . ' — ' . $c->email),
                                ])
                            )
                            ->searchable()
                            ->helperText('Kalau diisi, garansi ini akan otomatis muncul di "Garansi Saya" akun itu.'),

                        Forms\Components\Textarea::make('note')
                            ->label('Catatan (opsional)')
                            ->placeholder('Contoh: bukti jual-beli kwitansi No. 123'),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Kepemilikan garansi akan dipindahkan dari pemilik saat ini ke pemilik baru. Riwayat pemilik lama tetap tersimpan.')
                    ->action(fn (Warranty $record, array $data) => static::performOwnershipTransfer($record, $data)),

                // Fitur "Kuota Maintenance" (2026-09-25) -- lihat
                // performRecordMaintenanceVisit(). Cuma tampil kalau
                // maintenance_quota sudah diisi DAN masih ada sisa --
                // pengecekan sisa nyata tetap di dalam performRecordMaintenanceVisit()
                // (lockForUpdate()), ini cuma UI convenience.
                Tables\Actions\Action::make('record_maintenance_visit')
                    ->label('Catat Kunjungan Maintenance')
                    ->tooltip('Catat pelanggan yang datang maintenance tanpa jadwal. Mengurangi kuota maintenance garansi ini.')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('success')
                    ->visible(fn (Warranty $record) => $record->review_status === 'approved'
                        && $record->status !== 'revoked'
                        && $record->maintenance_quota !== null
                        && $record->maintenance_remaining > 0)
                    ->form([
                        Forms\Components\DatePicker::make('visited_at')
                            ->label('Tanggal Kunjungan')
                            ->required()
                            ->default(now())
                            ->maxDate(now())
                            // Gap diperbaiki 2026-09-26 (audit ulang Garansi)
                            // -- SEBELUMNYA tidak dibatasi, staff bisa input
                            // kunjungan maintenance SEBELUM tanggal
                            // instalasi, tidak masuk akal untuk bisnis
                            // instalasi PPF/WF (maintenance selalu setelah
                            // pemasangan).
                            ->minDate(fn (Warranty $record) => $record->installation_date),

                        Forms\Components\Textarea::make('note')
                            ->label('Catatan (opsional)')
                            ->placeholder('Contoh: cek kondisi PPF area kap mesin'),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription(fn (Warranty $record) => "Sisa kuota saat ini: {$record->maintenance_remaining}/{$record->maintenance_quota} kunjungan.")
                    ->action(fn (Warranty $record, array $data) => static::performRecordMaintenanceVisit($record, $data)),

                // Gap "koreksi kunjungan salah catat" diperbaiki 2026-09-26
                // -- lihat performCancelMaintenanceVisit(). Full-access
                // only, beda dari catat kunjungan yang untuk staff toko
                // biasa.
                Tables\Actions\Action::make('cancel_maintenance_visit')
                    ->label('Batalkan Kunjungan Maintenance')
                    ->tooltip('Batalkan kunjungan yang salah catat. Kuota dikembalikan. Hanya untuk akses penuh.')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    // Pakai active_maintenance_visits_count dari
                    // getEloquentQuery() withCount() (BUKAN
                    // activeMaintenanceVisits()->exists(), yang akan jadi
                    // query tambahan per baris tabel -- beda dari
                    // Edit/ViewWarranty yang cuma render 1 record, di situ
                    // ->exists() aman).
                    ->visible(fn (Warranty $record) => auth()->user()?->isFullAccess()
                        && $record->active_maintenance_visits_count > 0)
                    ->form(fn (Warranty $record) => [
                        Forms\Components\Select::make('visit_id')
                            ->label('Kunjungan yang Dibatalkan')
                            ->options(fn () => $record->activeMaintenanceVisits()
                                ->orderByDesc('visited_at')
                                ->get()
                                ->mapWithKeys(fn (WarrantyMaintenanceVisit $v) => [
                                    $v->id => $v->visited_at->format('d M Y') . ($v->note ? " — {$v->note}" : ''),
                                ])
                            )
                            ->required(),

                        Forms\Components\Textarea::make('cancel_reason')
                            ->label('Alasan Pembatalan')
                            ->placeholder('Contoh: salah pilih garansi / salah tanggal')
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Kunjungan yang dibatalkan mengembalikan kuota pelanggan. Riwayatnya tetap tersimpan (ditandai batal), bukan dihapus.')
                    ->action(fn (Warranty $record, array $data) => static::performCancelMaintenanceVisit($record, $data)),

                // SEBELUMNYA tidak dibatasi sama sekali — padahal Approve/
                // Reject di atas sengaja dikunci super_admin. Akibatnya
                // Store Manager biasa bisa hapus permanen garansi yang
                // sudah di-approve (plus riwayat klaimnya), padahal tidak
                // boleh sekalipun cuma reject. Disamakan aturannya.
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Halaman Lihat: tampilan baca-saja yang tertata (sebelumnya jatuh ke form yang dikunci). Dua kolom: kiri pelanggan, kendaraan,
     * pemasangan, dan riwayat; kanan status, masa berlaku, dan hasil review.
     */
    public static function infolist(\Filament\Infolists\Infolist $infolist): \Filament\Infolists\Infolist
    {
        $entry = \Filament\Infolists\Components\TextEntry::class;

        return $infolist->schema([
            \Filament\Infolists\Components\Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
                \Filament\Infolists\Components\Group::make()->columnSpan(['lg' => 2])->schema([
                    \Filament\Infolists\Components\Section::make('Pelanggan')
                        ->icon('heroicon-o-user')
                        ->columns(2)
                        ->schema([
                            $entry::make('customer_name')->label('Nama'),
                            $entry::make('phone_number')->label('Telepon'),
                            $entry::make('customer.email')->label('Akun Aplikasi')->placeholder('Belum tertaut akun'),
                            $entry::make('ownership_history')
                                ->label('Riwayat Kepemilikan')
                                ->columnSpanFull()
                                ->visible(fn (Warranty $record) => $record->ownershipTransfers->isNotEmpty())
                                ->bulleted()
                                ->state(fn (Warranty $record) => $record->ownershipTransfers->map(function (WarrantyOwnershipTransfer $t) {
                                    return "{$t->previous_customer_name} → {$t->new_customer_name} ({$t->transferred_at?->format('d M Y H:i')}, oleh " . ($t->transferredBy?->name ?? 'Sistem') . ')'
                                        . ($t->note ? " — {$t->note}" : '');
                                })->all()),
                        ]),

                    \Filament\Infolists\Components\Section::make('Kendaraan & Produk')
                        ->icon('heroicon-o-shield-check')
                        ->columns(['default' => 1, 'sm' => 2])
                        ->schema([
                            $entry::make('car_plate')->label('Plat Nomor')->placeholder('—'),
                            $entry::make('car_type')->label('Tipe Mobil')->placeholder('—'),
                            $entry::make('vin')->label('No. Rangka (VIN)')->placeholder('—'),
                            $entry::make('product_series')->label('Seri Produk')->placeholder('—'),
                            $entry::make('product_category')
                                ->label('Kategori')
                                ->badge()
                                ->formatStateUsing(fn (?string $state) => match ($state) {
                                    'window_film' => 'Window Film',
                                    'ppf' => 'PPF',
                                    default => $state ?? '—',
                                }),
                            $entry::make('dealer_name')->label('Dealer')->placeholder('—'),
                        ]),

                    \Filament\Infolists\Components\Section::make('Detail Instalasi')
                        ->icon('heroicon-o-sparkles')
                        ->columns(['default' => 1, 'sm' => 2])
                        ->schema([
                            $entry::make('installation_position')
                                ->label('Posisi Pemasangan')
                                ->formatStateUsing(fn (?string $state, Warranty $record) => match ($state) {
                                    'full_body' => 'Seluruh Bodi',
                                    'partial' => 'Parsial' . ($record->installation_position_detail ? ' — ' . $record->installation_position_detail : ''),
                                    default => '—',
                                })
                                ->visible(fn (Warranty $record) => $record->product_category === 'ppf'),
                            $entry::make('roll_number')->label('Kode Gulungan')->placeholder('—')->visible(fn (Warranty $record) => $record->product_category === 'ppf'),
                            $entry::make('roll_number_2')->label('Kode Gulungan Kedua')->placeholder('—')->visible(fn (Warranty $record) => $record->product_category === 'ppf' && filled($record->roll_number_2)),
                            $entry::make('roll_number_front')->label('Gulungan Kaca Depan')->placeholder('—')->visible(fn (Warranty $record) => $record->product_category === 'window_film'),
                            $entry::make('roll_number_side_rear')->label('Gulungan Kaca Samping & Belakang')->placeholder('—')->visible(fn (Warranty $record) => $record->product_category === 'window_film'),
                        ]),

                    \Filament\Infolists\Components\Section::make('Riwayat Maintenance')
                        ->icon('heroicon-o-wrench-screwdriver')
                        ->visible(fn (Warranty $record) => $record->maintenanceVisits->isNotEmpty() || filled($record->maintenance_quota))
                        ->schema([
                            $entry::make('maintenance_info')
                                ->label('Kuota')
                                ->state(fn (Warranty $record) => 'Terpakai ' . $record->maintenanceVisits->whereNull('cancelled_at')->count() . '/' . ($record->maintenance_quota ?? '—') . ' kunjungan'
                                    . ($record->maintenance_interval_months ? ", tiap {$record->maintenance_interval_months} bulan" : '')),
                            $entry::make('maintenance_history')
                                ->hiddenLabel()
                                ->visible(fn (Warranty $record) => $record->maintenanceVisits->isNotEmpty())
                                ->bulleted()
                                ->state(fn (Warranty $record) => $record->maintenanceVisits->map(function (WarrantyMaintenanceVisit $v) {
                                    $when = $v->visited_at?->format('d M Y');
                                    $by = $v->recordedBy?->name ?? 'Sistem';

                                    if ($v->isCancelled()) {
                                        return "{$when} (dicatat oleh {$by}) — DIBATALKAN oleh " . ($v->cancelledBy?->name ?? 'Sistem') . ": {$v->cancel_reason}";
                                    }

                                    return "{$when} (dicatat oleh {$by})" . ($v->note ? " — {$v->note}" : '');
                                })->all()),
                        ]),
                ]),

                \Filament\Infolists\Components\Group::make()->columnSpan(['lg' => 1])->schema([
                    \Filament\Infolists\Components\Section::make('Ringkasan')
                        ->icon('heroicon-o-clipboard-document-check')
                        ->columns(1)
                        ->schema([
                            $entry::make('warranty_code')->label('Kode Garansi')->placeholder('Belum dibuat')->copyable(),
                            $entry::make('status')
                                ->label('Status Garansi')
                                ->badge()
                                ->color(fn (string $state) => match ($state) {
                                    'active' => 'success',
                                    'pending_review' => 'warning',
                                    'expired', 'rejected', 'revoked' => 'danger',
                                    default => 'gray',
                                })
                                ->formatStateUsing(fn (string $state) => match ($state) {
                                    'active' => 'Active',
                                    'expired' => 'Expired',
                                    'pending_review' => 'Pending Review',
                                    'rejected' => 'Rejected',
                                    'revoked' => 'Revoked',
                                    default => $state,
                                }),
                            $entry::make('store.name')->label('Toko')->placeholder('—'),
                            $entry::make('installation_date')->label('Tanggal Pasang')->date('d M Y')->placeholder('—'),
                            $entry::make('expiry_date')->label('Berlaku Sampai')->date('d M Y')->placeholder('—'),
                            $entry::make('sisa_hari')
                                ->label('Sisa Masa Berlaku')
                                ->state(function (Warranty $record) {
                                    if (! $record->expiry_date) {
                                        return null;
                                    }

                                    $days = (int) now()->startOfDay()->diffInDays($record->expiry_date->copy()->startOfDay(), false);

                                    return $days >= 0 ? number_format($days, 0, ',', '.') . ' hari lagi' : 'Sudah berakhir ' . number_format(abs($days), 0, ',', '.') . ' hari lalu';
                                })
                                ->placeholder('—'),
                        ]),

                    \Filament\Infolists\Components\Section::make('Review QA')
                        ->icon('heroicon-o-check-badge')
                        ->columns(1)
                        ->schema([
                            $entry::make('review_status')
                                ->label('Status Review')
                                ->badge()
                                ->color(fn (?string $state) => match ($state) {
                                    'approved' => 'success',
                                    'rejected' => 'danger',
                                    default => 'warning',
                                })
                                ->formatStateUsing(fn (?string $state) => match ($state) {
                                    'approved' => 'Approved',
                                    'rejected' => 'Rejected',
                                    default => 'Pending Review',
                                }),
                            $entry::make('reviewed_at')->label('Direview Pada')->dateTime('d M Y H:i')->placeholder('—'),
                            $entry::make('rejection_reason')->label('Alasan Reject')
                                ->visible(fn (Warranty $record) => $record->review_status === 'rejected'),
                        ]),
                ]),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ClaimsRelationManager::class,
            RelationManagers\MaintenanceSchedulesRelationManager::class,
            \App\Filament\Resources\RelationManagers\ActivityHistoryRelationManager::class,
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