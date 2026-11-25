<?php

namespace App\Filament\Pages;

use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\FieldDutyRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PartialLeaveRequest;
use App\Services\AttendanceCorrectionService;
use App\Services\FieldDutyRequestService;
use App\Services\LeaveSubmissionService;
use App\Services\PartialLeaveRequestService;
use App\Support\SensitiveFiles;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * "Absen Saya" (2026-11-03): absen masuk dan keluar dari panel untuk karyawan yang belum bisa memakai aplikasi staff (mis. iPhone sebelum
 * aplikasi iOS tersedia). Memakai jalur yang SAMA dengan aplikasi: Attendance::clockIn()/clockOut() beserta radius toko dan selfie wajib.
 * Lokasi diambil dari GPS browser dan selfie dari kamera depan yang dibuka langsung di halaman (getUserMedia, tanpa pilihan galeri). Browser tidak melaporkan lokasi palsu, jadi penanda
 * is_mocked dikirim kosong, sama seperti aplikasi iOS.
 *
 * Sejak 2026-11-22 halaman ini juga menjadi pintu masuk fitur karyawan untuk pengguna tanpa aplikasi: tab "Pengajuan" (Izin/Cuti/Sakit, Izin Sebagian Hari, Dinas Luar,
 * Koreksi Absensi) memakai layanan yang SAMA dengan aplikasi. Cukup hak "Absen Saya": karyawan
 * tidak perlu dicentang di menu Dinas Luar, Koreksi Absensi, dst. (menu itu tetap untuk yang memutuskan), dan hanya melihat pengajuannya sendiri.
 */
class MyAttendance extends Page
{
    use WithFileUploads;

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Absensi & Cuti';

    protected static ?string $navigationLabel = 'Absen Saya';

    protected static ?string $title = 'Absen Saya';

    // Di atas Absensi Karyawan (100) dalam grup yang sama.
    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.my-attendance';

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    /** Tab yang tampil: 'absen', 'pengajuan', atau 'riwayat'. */
    #[Url(as: 'tab')]
    public string $tab = 'absen';

    /** Bulan riwayat absensi (Y-m); kosong atau tidak valid berarti bulan ini. */
    #[Url(as: 'bulan')]
    public ?string $month = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->canAccessStaffArea()
            && ! $user->isAttendanceExempt()
            && $user->hasMenuAccess(static::class);
    }

    public function updatedTab(): void
    {
        if (! in_array($this->tab, ['absen', 'pengajuan', 'riwayat'], true)) {
            $this->tab = 'absen';
        }
    }

    // ------------------------------------------------------------------ tab Riwayat

    /** Bulan yang dipilih (Y-m), dibatasi ke 12 bulan terakhir; selain itu bulan ini. */
    public function selectedMonth(): string
    {
        $current = today()->format('Y-m');

        if (! is_string($this->month) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
            return $current;
        }

        return array_key_exists($this->month, $this->monthOptions()) ? $this->month : $current;
    }

    /** @return array<string, string> Y-m => "November 2026", dari bulan ini mundur 12 bulan. */
    public function monthOptions(): array
    {
        $options = [];

        for ($i = 0; $i < 12; $i++) {
            $month = today()->startOfMonth()->subMonthsNoOverflow($i);
            $options[$month->format('Y-m')] = $month->translatedFormat('F Y');
        }

        return $options;
    }

    /** Absensi milik sendiri pada bulan terpilih, terbaru dulu. */
    public function myHistory(): \Illuminate\Support\Collection
    {
        $start = Carbon::createFromFormat('Y-m-d', $this->selectedMonth() . '-01')->startOfDay();

        return Attendance::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->orderByDesc('date')
            ->get();
    }

    /** Riwayat bulan terpilih tanpa tanggal yang lompat: tiap hari satu baris (catatan absen, libur, atau belum ada catatan). */
    public function myHistoryDays(): \Illuminate\Support\Collection
    {
        $start = Carbon::createFromFormat('Y-m-d', $this->selectedMonth() . '-01')->startOfDay();

        return app(\App\Services\AttendanceCalendar::class)->days(auth()->user(), $start);
    }

    // ------------------------------------------------------------------ tab Pengajuan

    /** Dinas luar milik sendiri (terbaru dulu); tanpa lingkup toko, supaya pengajuan lama tetap tampil kalau karyawan pindah toko. */
    public function myFieldDuties(): \Illuminate\Support\Collection
    {
        return FieldDutyRequest::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    public function myCorrections(): \Illuminate\Support\Collection
    {
        return AttendanceCorrectionRequest::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    public function myLeaves(): \Illuminate\Support\Collection
    {
        return LeaveRequest::withoutGlobalScopes()
            ->with('leaveType:id,name')
            ->where('user_id', auth()->id())
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    /** Jatah cuti tahun berjalan: [jatah, terpakai, sisa]. */
    public function cutiQuota(): array
    {
        $user = auth()->user();
        $year = today()->year;
        $quota = LeaveRequest::annualQuotaFor($user, $year);
        $used = LeaveRequest::usedCutiDaysFor($user, $year);

        return [$quota, $used, max(0, $quota - $used)];
    }

    public function leaveAction(): Action
    {
        LeaveType::ensureDefaults();

        return Action::make('leave')
            ->label('Ajukan Izin / Cuti / Sakit')
            ->icon('heroicon-o-calendar-days')
            ->modalHeading('Ajukan Izin / Cuti / Sakit')
            ->modalDescription(function () {
                [, , $remaining] = $this->cutiQuota();

                return 'Izin dan cuti diajukan sebelum hari H; sakit bisa diajukan di hari H atau sehari sesudahnya. Sisa jatah cuti tahun ini: ' . $remaining . ' hari.';
            })
            ->modalSubmitActionLabel('Kirim Pengajuan')
            ->form([
                Forms\Components\Select::make('leave_type_id')
                    ->label('Jenis')
                    ->options(fn () => LeaveType::where('is_active', true)->orderBy('sort_order')->orderBy('id')->pluck('name', 'id'))
                    ->required()
                    ->live()
                    ->helperText(fn (Forms\Get $get) => $get('leave_type_id') ? LeaveType::find($get('leave_type_id'))?->ruleSummary(withDocument: false) : null),

                Forms\Components\DatePicker::make('start_date')
                    ->label('Tanggal Mulai')
                    ->required()
                    ->default(today())
                    ->minDate(today()->subDays(LeaveRequest::SICK_BACKDATE_DAYS)),

                Forms\Components\DatePicker::make('end_date')
                    ->label('Tanggal Selesai')
                    ->required()
                    ->default(today())
                    ->afterOrEqual('start_date'),

                Forms\Components\Textarea::make('reason')
                    ->label('Alasan')
                    ->required()
                    ->maxLength(1000)
                    ->rows(3),

                Forms\Components\FileUpload::make('document')
                    ->label('Lampiran (opsional, mis. surat dokter)')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                    ->maxSize(10240)
                    ->storeFiles(false),
            ])
            ->action(function (array $data, Action $action) {
                abort_unless(static::canAccess(), 403);

                $document = $data['document'] ?? null;
                $document = $document instanceof \Illuminate\Http\UploadedFile ? $document : null;

                try {
                    app(LeaveSubmissionService::class)->submit(
                        auth()->user(),
                        (int) $data['leave_type_id'],
                        null,
                        (string) $data['start_date'],
                        (string) $data['end_date'],
                        (string) $data['reason'],
                        $document,
                    );
                } catch (\Illuminate\Validation\ValidationException $e) {
                    Notification::make()->title('Pengajuan ditolak')->body((string) collect($e->errors())->flatten()->first())->danger()->send();
                    $action->halt();

                    return;
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Pengajuan ditolak')->body($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Pengajuan terkirim')->body('Menunggu persetujuan atasan.')->success()->send();
            });
    }

    public function cancelLeaveAction(): Action
    {
        return Action::make('cancelLeave')
            ->label('Batalkan')
            ->color('danger')
            ->size('xs')
            ->requiresConfirmation()
            ->modalHeading('Batalkan pengajuan?')
            ->action(function (array $arguments) {
                abort_unless(static::canAccess(), 403);

                $leave = LeaveRequest::withoutGlobalScopes()->where('id', $arguments['id'] ?? 0)->where('user_id', auth()->id())->first();

                if (! $leave) {
                    Notification::make()->title('Pengajuan tidak ditemukan')->danger()->send();

                    return;
                }

                try {
                    app(LeaveSubmissionService::class)->cancel($leave, auth()->user());
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Tidak bisa dibatalkan')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Pengajuan dibatalkan')->success()->send();
            });
    }

    public function myPartialLeaves(): \Illuminate\Support\Collection
    {
        return PartialLeaveRequest::withoutGlobalScopes()
            ->where('user_id', auth()->id())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    public function partialLeaveAction(): Action
    {
        $lateArrival = PartialLeaveRequest::CATEGORY_LATE_ARRIVAL;
        $leaveOffice = PartialLeaveRequest::CATEGORY_LEAVE_OFFICE;
        $earlyLeave = PartialLeaveRequest::CATEGORY_EARLY_LEAVE;

        return Action::make('partialLeave')
            ->label('Ajukan Izin Sebagian Hari')
            ->icon('heroicon-o-clock')
            ->modalHeading('Ajukan Izin Sebagian Hari')
            ->modalDescription('Untuk masuk siang, keluar sebentar, atau pulang lebih awal. Boleh susulan sampai ' . PartialLeaveRequestService::MAX_DAYS_BACK . ' hari ke belakang dan paling jauh ' . PartialLeaveRequestService::MAX_DAYS_AHEAD . ' hari ke depan.')
            ->modalSubmitActionLabel('Kirim Pengajuan')
            ->form([
                Forms\Components\Select::make('category')
                    ->label('Jenis')
                    ->options(PartialLeaveRequest::CATEGORY_LABELS)
                    ->required()
                    ->live(),

                Forms\Components\DatePicker::make('date')
                    ->label('Tanggal')
                    ->required()
                    ->default(today())
                    ->minDate(today()->subDays(PartialLeaveRequestService::MAX_DAYS_BACK))
                    ->maxDate(today()->addDays(PartialLeaveRequestService::MAX_DAYS_AHEAD)),

                Forms\Components\TimePicker::make('time')
                    ->label(fn (Forms\Get $get) => $get('category') === $lateArrival ? 'Paling lambat tiba pukul' : 'Mulai pulang pukul')
                    ->seconds(false)
                    ->visible(fn (Forms\Get $get) => in_array($get('category'), [$lateArrival, $earlyLeave], true))
                    ->required(fn (Forms\Get $get) => in_array($get('category'), [$lateArrival, $earlyLeave], true)),

                Forms\Components\TimePicker::make('start_time')
                    ->label('Jam Keluar')
                    ->seconds(false)
                    ->visible(fn (Forms\Get $get) => $get('category') === $leaveOffice)
                    ->required(fn (Forms\Get $get) => $get('category') === $leaveOffice),

                Forms\Components\TimePicker::make('end_time')
                    ->label('Jam Kembali')
                    ->seconds(false)
                    ->visible(fn (Forms\Get $get) => $get('category') === $leaveOffice)
                    ->required(fn (Forms\Get $get) => $get('category') === $leaveOffice),

                Forms\Components\Textarea::make('reason')
                    ->label('Alasan')
                    ->required()
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(function (array $data, Action $action) use ($leaveOffice) {
                abort_unless(static::canAccess(), 403);

                $isOffice = ($data['category'] ?? null) === $leaveOffice;

                try {
                    app(PartialLeaveRequestService::class)->submit(auth()->user(), [
                        'date' => $data['date'],
                        'category' => $data['category'],
                        'time' => $isOffice ? null : substr((string) ($data['time'] ?? ''), 0, 5),
                        'start_time' => $isOffice ? substr((string) ($data['start_time'] ?? ''), 0, 5) : null,
                        'end_time' => $isOffice ? substr((string) ($data['end_time'] ?? ''), 0, 5) : null,
                        'reason' => $data['reason'],
                    ]);
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Pengajuan ditolak')->body($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Pengajuan izin sebagian hari terkirim')->body('Menunggu persetujuan atasan.')->success()->send();
            });
    }

    public function cancelPartialLeaveAction(): Action
    {
        return Action::make('cancelPartialLeave')
            ->label('Batalkan')
            ->color('danger')
            ->size('xs')
            ->requiresConfirmation()
            ->modalHeading('Batalkan pengajuan izin sebagian hari?')
            ->action(function (array $arguments) {
                abort_unless(static::canAccess(), 403);

                $leave = PartialLeaveRequest::withoutGlobalScopes()->where('id', $arguments['id'] ?? 0)->where('user_id', auth()->id())->first();

                if (! $leave) {
                    Notification::make()->title('Pengajuan tidak ditemukan')->danger()->send();

                    return;
                }

                try {
                    app(PartialLeaveRequestService::class)->cancel($leave, auth()->id());
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Tidak bisa dibatalkan')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Pengajuan izin sebagian hari dibatalkan')->success()->send();
            });
    }

    public function fieldDutyAction(): Action
    {
        return Action::make('fieldDuty')
            ->label('Ajukan Dinas Luar')
            ->icon('heroicon-o-map-pin')
            ->modalHeading('Ajukan Dinas Luar')
            ->modalDescription('Untuk bekerja di luar toko (mis. kanvasing). Boleh susulan sampai ' . FieldDutyRequestService::MAX_DAYS_BACK . ' hari ke belakang. Setelah disetujui, kirim foto awal saat tiba dan foto akhir saat selesai.')
            ->modalSubmitActionLabel('Kirim Pengajuan')
            ->form([
                Forms\Components\DatePicker::make('date')
                    ->label('Tanggal Dinas Luar')
                    ->required()
                    ->default(today())
                    ->minDate(today()->subDays(FieldDutyRequestService::MAX_DAYS_BACK))
                    ->maxDate(today()->addDays(FieldDutyRequestService::MAX_DAYS_AHEAD)),

                Forms\Components\Toggle::make('partial')
                    ->label('Hanya sebagian hari')
                    ->helperText('Nyalakan kalau hanya sebagian hari di luar (absen di toko tetap wajib). Matikan untuk sehari penuh.')
                    ->live(),

                Forms\Components\TimePicker::make('start_time')
                    ->label('Jam Mulai')
                    ->seconds(false)
                    ->visible(fn (Forms\Get $get) => (bool) $get('partial'))
                    ->required(fn (Forms\Get $get) => (bool) $get('partial')),

                Forms\Components\TimePicker::make('end_time')
                    ->label('Jam Selesai')
                    ->seconds(false)
                    ->visible(fn (Forms\Get $get) => (bool) $get('partial'))
                    ->required(fn (Forms\Get $get) => (bool) $get('partial')),

                Forms\Components\Textarea::make('description')
                    ->label('Tujuan / Pekerjaan')
                    ->required()
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(function (array $data, Action $action) {
                abort_unless(static::canAccess(), 403);

                $partial = (bool) ($data['partial'] ?? false);

                try {
                    app(FieldDutyRequestService::class)->submit(auth()->user(), [
                        'date' => $data['date'],
                        'description' => $data['description'],
                        'start_time' => $partial ? substr((string) $data['start_time'], 0, 5) : null,
                        'end_time' => $partial ? substr((string) $data['end_time'], 0, 5) : null,
                    ]);
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Pengajuan ditolak')->body($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Pengajuan dinas luar terkirim')->body('Menunggu persetujuan atasan.')->success()->send();
            });
    }

    public function correctionAction(): Action
    {
        return Action::make('correction')
            ->label('Ajukan Koreksi Absensi')
            ->icon('heroicon-o-pencil-square')
            ->modalHeading('Ajukan Koreksi Absensi')
            ->modalDescription('Untuk lupa absen atau absen yang tidak tercatat. Isi minimal salah satu jam, dan jelaskan alasannya.')
            ->modalSubmitActionLabel('Kirim Pengajuan')
            // Dari baris riwayat tanggalnya terisi; dari tombol biasa bawaannya kemarin.
            ->fillForm(fn (array $arguments) => ['date' => $arguments['date'] ?? today()->subDay()->toDateString()])
            ->form([
                Forms\Components\DatePicker::make('date')
                    ->label('Tanggal Absensi')
                    ->required()
                    ->live()
                    ->maxDate(today()),

                // Hari itu tercatat Dinas Luar sehari penuh: koreksi yang disetujui menggantinya menjadi absen biasa.
                Forms\Components\Placeholder::make('field_duty_notice')
                    ->hiddenLabel()
                    ->content('Tanggal ini tercatat Dinas Luar sehari penuh yang sudah disetujui. Kalau koreksi ini disetujui, Dinas Luar hari itu diganti menjadi absen biasa sesuai jam yang kamu isi. Ajukan hanya bila kamu memang absen di toko pada hari itu.')
                    ->visible(fn (\Filament\Forms\Get $get) => filled($get('date'))
                        && app(AttendanceCorrectionService::class)->fullDayFieldDutyOn((int) auth()->id(), (string) $get('date')) !== null),

                Forms\Components\TimePicker::make('clock_in_at')->label('Jam Masuk')->seconds(false),

                Forms\Components\TimePicker::make('clock_out_at')->label('Jam Keluar')->seconds(false),

                Forms\Components\Textarea::make('reason')
                    ->label('Alasan')
                    ->required()
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(function (array $data, Action $action) {
                abort_unless(static::canAccess(), 403);

                try {
                    app(AttendanceCorrectionService::class)->submitForStaff(
                        auth()->user(),
                        (string) $data['date'],
                        filled($data['clock_in_at'] ?? null) ? substr((string) $data['clock_in_at'], 0, 5) : null,
                        filled($data['clock_out_at'] ?? null) ? substr((string) $data['clock_out_at'], 0, 5) : null,
                        (string) $data['reason'],
                    );
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Pengajuan ditolak')->body($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Pengajuan koreksi terkirim')->body('Menunggu persetujuan atasan.')->success()->send();
            });
    }

    public function cancelFieldDutyAction(): Action
    {
        return Action::make('cancelFieldDuty')
            ->label('Batalkan')
            ->color('danger')
            ->size('xs')
            ->requiresConfirmation()
            ->modalHeading('Batalkan pengajuan dinas luar?')
            ->action(function (array $arguments) {
                abort_unless(static::canAccess(), 403);

                $duty = FieldDutyRequest::withoutGlobalScopes()->where('id', $arguments['id'] ?? 0)->where('user_id', auth()->id())->first();

                if (! $duty) {
                    Notification::make()->title('Pengajuan tidak ditemukan')->danger()->send();

                    return;
                }

                try {
                    app(FieldDutyRequestService::class)->cancel($duty, auth()->id());
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Tidak bisa dibatalkan')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Pengajuan dinas luar dibatalkan')->success()->send();
            });
    }

    public function cancelCorrectionAction(): Action
    {
        return Action::make('cancelCorrection')
            ->label('Batalkan')
            ->color('danger')
            ->size('xs')
            ->requiresConfirmation()
            ->modalHeading('Batalkan pengajuan koreksi?')
            ->action(function (array $arguments) {
                abort_unless(static::canAccess(), 403);

                $correction = AttendanceCorrectionRequest::withoutGlobalScopes()->where('id', $arguments['id'] ?? 0)->where('user_id', auth()->id())->first();

                if (! $correction) {
                    Notification::make()->title('Pengajuan tidak ditemukan')->danger()->send();

                    return;
                }

                try {
                    app(AttendanceCorrectionService::class)->cancel($correction, auth()->id());
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Tidak bisa dibatalkan')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Pengajuan koreksi dibatalkan')->success()->send();
            });
    }

    public function today(): ?Attendance
    {
        return Attendance::where('user_id', auth()->id())
            ->where('date', Carbon::today()->toDateString())
            ->first();
    }

    public function store(): ?\App\Models\Store
    {
        return auth()->user()?->store;
    }

    public function radiusMeters(): int
    {
        return (int) ($this->store()?->attendance_radius_meters ?? Attendance::DEFAULT_RADIUS_METERS);
    }

    /** Dipanggil dari halaman sesudah lokasi didapat. $kind: 'in' (masuk) atau 'out' (keluar). */
    public function submitClock(string $kind, float $latitude, float $longitude): void
    {
        abort_unless(static::canAccess(), 403);

        if (! in_array($kind, ['in', 'out'], true) || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            Notification::make()->title('Lokasi tidak valid')->body('Coba ambil lokasi lagi.')->danger()->send();

            return;
        }

        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            Notification::make()->title('Tidak bisa absen')->body('Akun ini belum terhubung ke toko mana pun.')->danger()->send();

            return;
        }

        $this->validate(
            ['photo' => 'required|image|max:5120|dimensions:min_width=200,min_height=200'],
            [
                'photo.required' => 'Ambil foto selfie dulu sebelum absen.',
                'photo.image' => 'Berkas harus berupa foto.',
                'photo.max' => 'Ukuran foto maksimal 5 MB.',
                'photo.dimensions' => 'Foto terlalu kecil (minimal 200 x 200 piksel).',
            ]
        );

        $photoPath = SensitiveFiles::store($this->photo, 'attendance-photos');

        try {
            $attendance = $kind === 'in'
                ? Attendance::clockIn($user, $store, $latitude, $longitude, null, $photoPath)
                : Attendance::clockOut($user, $latitude, $longitude, null, $photoPath);
        } catch (\InvalidArgumentException $e) {
            SensitiveFiles::delete($photoPath);
            Notification::make()->title('Absen ditolak')->body($e->getMessage())->danger()->send();

            return;
        } catch (\Throwable $e) {
            SensitiveFiles::delete($photoPath);

            throw $e;
        }

        $this->photo = null;

        Notification::make()
            ->title($kind === 'in' ? 'Absen masuk tercatat' : 'Absen keluar tercatat')
            ->body('Pukul ' . ($kind === 'in' ? $attendance->clock_in_at : $attendance->clock_out_at)?->format('H:i'))
            ->success()
            ->send();
    }
}
