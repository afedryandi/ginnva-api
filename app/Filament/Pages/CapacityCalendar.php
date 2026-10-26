<?php

namespace App\Filament\Pages;

use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\Store;
use App\Models\StoreCapacityOverride;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

/**
 * "Kalender Kapasitas & Libur": satu kalender untuk dua keputusan per tanggal, berapa pekerjaan berat yang boleh masuk
 * (StoreCapacityOverride) dan apakah tanggal itu ditutup penuh (BlockedDate). Dulu dua menu: "Kalender Kapasitas" dan "Tanggal Tidak Tersedia"
 * (digabung 2026-10-26, tampilannya dirombak 2026-10-26). Dua tabel tetap terpisah; endpoint publik blocked-dates dan aturan penolakan booking
 * tidak berubah.
 *
 * Semua dialog memakai Aksi Filament (modal, form, validasi, dan mode gelap bawaan), bukan modal buatan sendiri. Kotak kalendernya satu-satunya
 * tampilan khusus; gayanya CSS polos berprefiks .cc- karena panel memakai CSS Filament yang sudah dikompilasi, bukan Tailwind proyek ini.
 *
 * Izin: kapasitas mengikuti akses menu Booking Instalasi; menutup/membuka tanggal memakai kunci hak akses lama 'BlockedDateResource' (lihat
 * canManageClosures()) supaya akses yang sudah diberikan tetap berlaku. Libur nasional/cuti bersama ditentukan manual oleh staf (keputusan pemilik
 * 2026-10-28): tanggal tutup inilah yang dipakai booking, absensi (tanpa Alpha), dan hitungan jam kerja gaji.
 *
 * Toko yang berlaku selalu lewat effectiveStoreId(): staf non-akses-penuh dipaksa ke tokonya sendiri walau $storeId dimanipulasi lewat request
 * Livewire (celah keamanan yang pernah ditemukan 2026-09-25).
 */
class CapacityCalendar extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $cluster = \App\Filament\Clusters\BookingCluster::class;

    protected static ?string $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 100;

    protected static ?string $navigationLabel = 'Kalender Kapasitas & Libur';

    protected static ?string $title = 'Kalender Kapasitas & Libur';

    protected static string $view = 'filament.pages.capacity-calendar';

    #[Url(as: 'toko')]
    public ?int $storeId = null;

    #[Url(as: 'bulan')]
    public string $month = '';

    /** Kunci hak akses lama menu "Tanggal Tidak Tersedia"; dipertahankan supaya izin menutup/membuka tanggal yang sudah diberikan tetap berlaku. */
    public const CLOSURE_PERMISSION_KEY = 'BlockedDateResource';

    /** Batas satu kali tutup rentang, jaring pengaman bila staf salah memilih rentang tahunan. */
    public const MAX_CLOSURE_DAYS = 120;

    /** Batas satu kali atur kapasitas rentang. */
    public const MAX_CAPACITY_DAYS = 90;

    // ------------------------------------------------------------------ akses

    /** Mengatur kapasitas harian: sama dengan akses menu Booking Instalasi (kalender ini sub-tool alur approve booking). */
    public static function canManageCapacity(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(\App\Filament\Resources\BookingResource::class);
    }

    /** Menutup atau membuka tanggal: aksi create/update/delete pada kunci hak akses lama 'BlockedDateResource' (bawaan boleh, seperti sebelumnya). */
    public static function canManageClosures(string $action = 'create'): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasModuleAction(self::CLOSURE_PERMISSION_KEY, $action, true);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return self::canManageCapacity()
            || (($user?->canAccessStaffArea() ?? false) && $user->hasMenuAccess(self::CLOSURE_PERMISSION_KEY));
    }

    // ------------------------------------------------------------------ toko dan bulan

    public function mount(): void
    {
        if ($this->month === '' || $this->month === null) {
            $this->month = now()->format('Y-m');
        }

        $user = auth()->user();

        if (! ($user?->isFullAccess() ?? false)) {
            // Staf toko selalu terkunci ke tokonya sendiri, apa pun isi query string ?toko=.
            $this->storeId = $user?->store_id;
        } elseif (! $this->storeId) {
            $this->storeId = Store::where('is_active', true)->orderBy('name')->value('id');
        }
    }

    public function getStoreOptions(): array
    {
        return Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Toko yang benar-benar berlaku setelah aturan akses; satu-satunya cara membaca toko di method lain di class ini. */
    public function effectiveStoreId(): ?int
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false) ? $this->storeId : $user?->store_id;
    }

    public function updatedStoreId(): void
    {
        $user = auth()->user();

        if (! ($user?->isFullAccess() ?? false)) {
            $this->storeId = $user?->store_id;
        }
    }

    public function prevMonth(): void
    {
        $this->month = Carbon::parse($this->month . '-01')->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = Carbon::parse($this->month . '-01')->addMonthNoOverflow()->format('Y-m');
    }

    public function goToday(): void
    {
        $this->month = now()->format('Y-m');
    }

    public function getSubheading(): ?string
    {
        return 'Atur kapasitas harian layanan berat dan tutup tanggal libur, renovasi, atau acara. Klik tanggal untuk mengaturnya.';
    }

    /** Tanggal berbahasa Indonesia terlepas dari locale aplikasi. */
    public static function idDate(Carbon|string $date, string $format): string
    {
        return Carbon::parse($date)->locale('id')->translatedFormat($format);
    }

    // ------------------------------------------------------------------ aksi di kepala halaman

    protected function getHeaderActions(): array
    {
        return [
            Action::make('closeRange')
                ->label('Tutup Rentang Tanggal')
                ->icon('heroicon-o-no-symbol')
                ->color('gray')
                ->visible(fn () => self::canManageClosures('create') && $this->effectiveStoreId() !== null)
                ->modalHeading('Tutup Rentang Tanggal')
                ->modalDescription('Menutup semua tanggal dalam rentang untuk toko ini (libur, renovasi, acara). Tanggal yang sudah ditutup dilewati. Untuk libur rutin tiap minggu, atur di Toko > Jam Operasional.')
                ->modalWidth('lg')
                ->modalSubmitActionLabel('Tutup Tanggal')
                ->form([
                    Grid::make(2)->schema([
                        DatePicker::make('from')->label('Dari Tanggal')->native(false)->default(today())->minDate(today())->required()->live(),
                        DatePicker::make('to')->label('Sampai Tanggal')->native(false)->default(today())->minDate(today())->afterOrEqual('from')->required()->live(),
                    ]),
                    TextInput::make('reason')->label('Alasan (opsional)')->placeholder('Contoh: Libur nasional, Workshop penuh, Maintenance')->maxLength(255),
                    Placeholder::make('overlap')
                        ->label('Booking yang sudah ada')
                        ->visible(fn (Get $get) => $this->overlapLines($get('from'), $get('to')) !== [])
                        ->content(fn (Get $get) => new HtmlString(
                            '<div style="color:#b45309">' . implode('<br>', array_map(fn (string $l) => '⚠ ' . e($l), $this->overlapLines($get('from'), $get('to')))) . '</div>'
                        )),
                ])
                ->action(fn (array $data) => $this->closeRange($data['from'], $data['to'], $data['reason'] ?? null)),

            Action::make('capacityRange')
                ->label('Atur Kapasitas Rentang')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->visible(fn () => self::canManageCapacity() && $this->effectiveStoreId() !== null)
                ->modalHeading('Atur Kapasitas Rentang Tanggal')
                ->modalDescription('Terapkan satu angka kapasitas ke semua tanggal dalam rentang, mis. minggu depan turun jadi 2 karena kurang installer. Tanggal yang sudah lewat dilewati; paling banyak ' . self::MAX_CAPACITY_DAYS . ' hari sekali terap.')
                ->modalWidth('lg')
                ->modalSubmitActionLabel('Terapkan')
                ->form([
                    Grid::make(2)->schema([
                        DatePicker::make('from')->label('Dari Tanggal')->native(false)->default(today())->required(),
                        DatePicker::make('to')->label('Sampai Tanggal')->native(false)->default(today()->addDays(6))->afterOrEqual('from')->required(),
                    ]),
                    TextInput::make('capacity')->label('Kapasitas per hari')->numeric()->integer()->minValue(1)->required()
                        ->helperText('Jumlah pekerjaan berat (PPF, Kaca Film) yang boleh masuk per hari.'),
                ])
                ->action(fn (array $data) => $this->applyCapacityRange($data['from'], $data['to'], (int) $data['capacity'])),

            ActionGroup::make([
                Action::make('closeAll')
                    ->label('Tutup Semua Toko')
                    ->icon('heroicon-o-building-storefront')
                    ->visible(fn () => (auth()->user()?->isFullAccess() ?? false) && self::canManageClosures('create'))
                    ->modalHeading('Tutup Semua Toko')
                    ->modalDescription('Menutup semua toko aktif pada tanggal ini, misalnya libur nasional atau cuti bersama. Tanggal ini tidak bisa dibooking, tidak dihitung Alpha, dan tidak dihitung jam kerjanya di gaji. Booking yang sudah ada tidak dibatalkan.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Tutup Semua Toko')
                    ->form([
                        Grid::make(2)->schema([
                            DatePicker::make('from')->label('Dari Tanggal')->native(false)->default(today())->minDate(today())->required(),
                            DatePicker::make('to')->label('Sampai Tanggal')->native(false)->default(today())->minDate(today())->afterOrEqual('from')->required(),
                        ]),
                        TextInput::make('reason')->label('Alasan')->placeholder('Mis. Libur Nasional - Hari Kemerdekaan')->required()->maxLength(255),
                    ])
                    ->action(fn (array $data) => $this->closeAllStores($data['from'], $data['to'], $data['reason'])),

                Action::make('clearOverrides')
                    ->label('Hapus Semua Override')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn () => self::canManageCapacity() && $this->effectiveStoreId() !== null)
                    ->requiresConfirmation()
                    ->modalHeading('Hapus semua override kapasitas?')
                    ->modalDescription('Semua tanggal hari ini dan seterusnya untuk toko ini kembali memakai kapasitas bawaan toko. Override tanggal yang sudah lewat tetap tersimpan sebagai riwayat.')
                    ->modalSubmitActionLabel('Hapus Override')
                    ->action(fn () => $this->clearAllOverrides()),
            ])
                ->label('Lainnya')
                ->icon('heroicon-m-ellipsis-vertical')
                ->button()
                ->color('gray'),
        ];
    }

    // ------------------------------------------------------------------ dialog satu tanggal

    /** Dibuka dari kotak kalender lewat mountAction('manageDay', { date }). */
    public function manageDayAction(): Action
    {
        return Action::make('manageDay')
            ->modalWidth('md')
            ->modalHeading(fn (array $arguments) => self::idDate($arguments['date'] ?? now(), 'l, d F Y'))
            ->modalSubmitActionLabel('Simpan')
            ->fillForm(fn (array $arguments) => $this->dayDefaults((string) ($arguments['date'] ?? '')))
            ->form(fn (array $arguments) => $this->dayFormSchema((string) ($arguments['date'] ?? '')))
            ->action(fn (array $data, array $arguments) => $this->saveDay((string) ($arguments['date'] ?? ''), $data));
    }

    /** Dibuka dari daftar tanggal ditutup lewat mountAction('reopenClosure', { id }). */
    public function reopenClosureAction(): Action
    {
        return Action::make('reopenClosure')
            ->requiresConfirmation()
            ->modalHeading('Buka kembali tanggal ini?')
            ->modalDescription('Tanggal ini bisa dipilih pelanggan lagi untuk booking.')
            ->modalSubmitActionLabel('Buka Kembali')
            ->color('danger')
            ->action(fn (array $arguments) => $this->reopenById((int) ($arguments['id'] ?? 0)));
    }

    /**
     * Keadaan satu tanggal untuk toko yang berlaku, atau null bila tanggal/toko tidak sah.
     *
     * @return null|array{date: Carbon, past: bool, weekly: bool, blocked: ?BlockedDate, override: ?StoreCapacityOverride, used: int, capacity: int}
     */
    private function dayState(string $date): ?array
    {
        $storeId = $this->effectiveStoreId();

        if (! $storeId || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $store = Store::find($storeId);

        if (! $store) {
            return null;
        }

        $day = Carbon::parse($date)->startOfDay();
        $blocked = BlockedDate::where('store_id', $storeId)->whereDate('date', $day)->first();

        return [
            'date' => $day,
            'past' => $day->lt(today()),
            'weekly' => $blocked === null && $store->isClosedOn($day),
            'blocked' => $blocked,
            'override' => StoreCapacityOverride::where('store_id', $storeId)->whereDate('date', $day)->with('updatedBy:id,name')->first(),
            'used' => (int) (Booking::confirmedOverlapCountsForRange($storeId, $day, $day)[$day->toDateString()] ?? 0),
            'capacity' => Booking::capacityForDate($storeId, $day),
        ];
    }

    private function dayDefaults(string $date): array
    {
        $state = $this->dayState($date);

        return [
            'capacity' => $state['capacity'] ?? null,
            'reason' => $state['blocked']?->reason,
            'reset_capacity' => false,
            'close' => false,
            'reopen' => false,
        ];
    }

    /** @return list<\Filament\Forms\Components\Component> */
    private function dayFormSchema(string $date): array
    {
        $state = $this->dayState($date);

        if (! $state || $state['past'] || $state['weekly']) {
            return [Placeholder::make('invalid')->label('')->content('Tanggal ini tidak bisa diatur: sudah lewat atau libur rutin mingguan toko.')];
        }

        if ($state['blocked']) {
            return [
                Placeholder::make('info')->label('')->content('Tanggal ini ditutup. Pelanggan tidak bisa memilihnya dan kapasitas tidak berlaku. Booking yang sudah ada tidak dibatalkan.'),
                TextInput::make('reason')->label('Alasan')->maxLength(255)->disabled(! self::canManageClosures('update'))->dehydrated(),
                Toggle::make('reopen')->label('Buka kembali tanggal ini')->helperText('Tanggal bisa dipilih pelanggan lagi.')->visible(self::canManageClosures('delete')),
            ];
        }

        $override = $state['override'];
        $info = "{$state['used']} booking confirmed menempati tanggal ini. Kapasitas saat ini {$state['capacity']}"
            . ($override
                ? ', diatur manual oleh ' . ($override->updatedBy?->name ?? '—') . ' pada ' . $override->updated_at->format('d M Y H:i') . '.'
                : ', bawaan toko.');

        return [
            Placeholder::make('info')->label('')->content($info),

            TextInput::make('capacity')
                ->label('Kapasitas')
                ->numeric()->integer()->minValue(1)
                ->required(fn (Get $get) => ! $get('close') && ! $get('reset_capacity'))
                ->disabled(fn (Get $get) => (bool) $get('close') || (bool) $get('reset_capacity'))
                ->visible(self::canManageCapacity())
                ->helperText('Jumlah pekerjaan berat (PPF, Kaca Film) yang boleh masuk pada tanggal ini.'),

            Toggle::make('reset_capacity')
                ->label('Pakai kapasitas bawaan toko')
                ->helperText('Menghapus pengaturan manual tanggal ini.')
                ->live()
                ->visible(self::canManageCapacity() && $override !== null),

            Toggle::make('close')
                ->label('Tutup tanggal ini')
                ->helperText('Untuk libur, renovasi, atau acara. Booking yang sudah ada tidak dibatalkan.')
                ->live()
                ->visible(self::canManageClosures('create')),

            TextInput::make('reason')
                ->label('Alasan (opsional)')
                ->placeholder('Contoh: Libur nasional')
                ->maxLength(255)
                ->visible(fn (Get $get) => (bool) $get('close')),
        ];
    }

    private function saveDay(string $date, array $data): void
    {
        $state = $this->dayState($date);

        if (! $state || $state['past'] || $state['weekly']) {
            $this->flash('Tanggal ini tidak bisa diatur', 'Sudah lewat atau libur rutin mingguan toko.', 'danger');

            return;
        }

        $storeId = $this->effectiveStoreId();
        $day = $state['date'];

        if ($state['blocked']) {
            if (! empty($data['reopen']) && self::canManageClosures('delete')) {
                BlockedDate::where('store_id', $storeId)->whereDate('date', $day)->get()->each->delete();
                $this->flash('Tanggal dibuka kembali');

                return;
            }

            if (self::canManageClosures('update')) {
                BlockedDate::where('store_id', $storeId)->whereDate('date', $day)->get()
                    ->each(fn (BlockedDate $row) => $row->update(['reason' => $this->cleanReason($data['reason'] ?? null)]));
                $this->flash('Alasan diperbarui');
            }

            return;
        }

        if (! empty($data['close']) && self::canManageClosures('create')) {
            $row = BlockedDate::firstOrCreate(['store_id' => $storeId, 'date' => $day->toDateString()], ['reason' => $this->cleanReason($data['reason'] ?? null)]);
            $this->flash($row->wasRecentlyCreated ? 'Tanggal ditutup' : 'Tanggal ini sudah ditutup sebelumnya', null, $row->wasRecentlyCreated ? 'success' : 'warning');

            return;
        }

        if (! self::canManageCapacity()) {
            return;
        }

        if (! empty($data['reset_capacity'])) {
            StoreCapacityOverride::where('store_id', $storeId)->whereDate('date', $day)->get()->each->delete();
            $this->flash('Kapasitas kembali ke bawaan toko');

            return;
        }

        $capacity = (int) ($data['capacity'] ?? 0);

        if ($capacity < 1) {
            return;
        }

        StoreCapacityOverride::updateOrCreate(
            ['store_id' => $storeId, 'date' => $day->toDateString()],
            ['capacity' => $capacity, 'updated_by' => auth()->id()],
        );
        $this->flash('Kapasitas disimpan');
    }

    // ------------------------------------------------------------------ logika massal

    private function flash(string $title, ?string $body = null, string $level = 'success'): void
    {
        Notification::make()->title($title)->body($body)->{$level}()->send();
    }

    private function cleanReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : mb_substr($reason, 0, 255);
    }

    /** Tanggal dalam rentang yang valid ditutup: hari ini dan seterusnya, paling banyak MAX_CLOSURE_DAYS hari. */
    private function closureDates(?string $from, ?string $to): array
    {
        if (! $from || ! $to) {
            return [];
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $dates = [];

        for ($day = $start->copy(); $day->lte($end) && count($dates) < self::MAX_CLOSURE_DAYS; $day->addDay()) {
            if ($day->gte(today())) {
                $dates[] = $day->toDateString();
            }
        }

        return $dates;
    }

    /**
     * Peringatan (bukan penolakan) untuk rentang yang akan ditutup: booking confirmed yang sudah menempati tanggal itu tidak ikut dibatalkan.
     * Hanya untuk rentang sampai 31 hari supaya pratinjaunya tetap ringan.
     *
     * @return list<string>
     */
    public function overlapLines(?string $from, ?string $to): array
    {
        $storeId = $this->effectiveStoreId();
        $dates = $this->closureDates($from, $to);

        if (! $storeId || $dates === [] || count($dates) > 31) {
            return [];
        }

        $lines = [];

        foreach ($dates as $date) {
            $count = Booking::confirmedOverlapCount($storeId, Carbon::parse($date), null, true); // termasuk layanan ringan

            if ($count > 0) {
                $lines[] = self::idDate($date, 'd M Y') . ": {$count} booking confirmed (tidak ikut dibatalkan, hanya info)";
            }
        }

        return $lines;
    }

    public function closeRange(string $from, string $to, ?string $reason): void
    {
        $storeId = $this->effectiveStoreId();

        if (! self::canManageClosures('create') || ! $storeId) {
            return;
        }

        $dates = $this->closureDates($from, $to);

        if ($dates === []) {
            $this->flash('Tidak ada tanggal yang bisa ditutup', 'Tanggal yang sudah lewat tidak bisa ditutup.', 'danger');

            return;
        }

        $created = 0;

        foreach ($dates as $date) {
            $row = BlockedDate::firstOrCreate(['store_id' => $storeId, 'date' => $date], ['reason' => $this->cleanReason($reason)]);
            $created += $row->wasRecentlyCreated ? 1 : 0;
        }

        $skipped = count($dates) - $created;
        $this->flash("{$created} tanggal ditutup", $skipped > 0 ? "{$skipped} sudah tertutup sebelumnya dan dilewati." : null);
    }

    /** Menutup SEMUA toko aktif sekaligus (libur nasional, cuti bersama). Tanggal yang sudah tertutup dilewati dan alasannya tidak ditimpa. Hanya akses penuh. */
    public function closeAllStores(string $from, string $to, ?string $reason): void
    {
        if (! (auth()->user()?->isFullAccess() ?? false) || ! self::canManageClosures('create')) {
            return;
        }

        $reason = $this->cleanReason($reason);
        $dates = $this->closureDates($from, $to);

        if ($reason === null || $dates === []) {
            $this->flash('Tanggal tidak ditutup', 'Isi alasan, dan pilih tanggal hari ini atau ke depan.', 'danger');

            return;
        }

        $created = 0;
        $skipped = 0;

        foreach (Store::where('is_active', true)->get() as $store) {
            foreach ($dates as $date) {
                $row = BlockedDate::firstOrCreate(['store_id' => $store->id, 'date' => $date], ['reason' => $reason]);
                $row->wasRecentlyCreated ? $created++ : $skipped++;
            }
        }

        $this->flash("{$created} tanggal ditutup", $skipped > 0 ? "{$skipped} sudah tertutup sebelumnya dan dilewati." : null);
    }

    /** Buka kembali tanggal dari daftar di bawah kalender; dibatasi ke toko yang berlaku. */
    public function reopenById(int $id): void
    {
        $storeId = $this->effectiveStoreId();

        if (! self::canManageClosures('delete') || ! $storeId) {
            return;
        }

        BlockedDate::where('store_id', $storeId)->whereKey($id)->get()->each->delete();

        $this->flash('Tanggal dibuka kembali');
    }

    /** Terapkan satu angka kapasitas ke semua tanggal hari ini dan seterusnya dalam rentang (tanggal lampau dilewati, paling banyak MAX_CAPACITY_DAYS hari). */
    public function applyCapacityRange(string $from, string $to, int $capacity): void
    {
        $storeId = $this->effectiveStoreId();

        if (! self::canManageCapacity() || ! $storeId || $capacity < 1) {
            return;
        }

        $start = Carbon::parse($from);
        $end = Carbon::parse($to);

        if ($end->lt($start)) {
            return;
        }

        $end = $start->copy()->addDays(self::MAX_CAPACITY_DAYS - 1)->lt($end) ? $start->copy()->addDays(self::MAX_CAPACITY_DAYS - 1) : $end;
        $applied = 0;

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            if ($cursor->gte(today())) {
                StoreCapacityOverride::updateOrCreate(
                    ['store_id' => $storeId, 'date' => $cursor->toDateString()],
                    ['capacity' => $capacity, 'updated_by' => auth()->id()],
                );
                $applied++;
            }
        }

        $this->flash("Kapasitas {$capacity} diterapkan ke {$applied} tanggal");
    }

    /** Hapus semua override hari ini dan seterusnya; override tanggal lampau dibiarkan sebagai riwayat. */
    public function clearAllOverrides(): void
    {
        $storeId = $this->effectiveStoreId();

        if (! self::canManageCapacity() || ! $storeId) {
            return;
        }

        StoreCapacityOverride::where('store_id', $storeId)->where('date', '>=', now()->toDateString())->delete();

        $this->flash('Semua override kapasitas dihapus');
    }

    // ------------------------------------------------------------------ data tampilan

    /** Tanggal tutup yang akan datang untuk toko yang dipilih, paling dekat dulu. */
    public function getUpcomingClosures(): \Illuminate\Support\Collection
    {
        $storeId = $this->effectiveStoreId();

        if (! $storeId) {
            return collect();
        }

        return BlockedDate::where('store_id', $storeId)->whereDate('date', '>=', today())->orderBy('date')->limit(100)->get();
    }

    /**
     * Grid kalender penuh, termasuk hari bulan sebelum/sesudahnya supaya tiap baris 7 kolom; hari di luar bulan aktif ditandai inMonth=false.
     *
     * @return list<array{date: string, inMonth: bool, closed: bool, blocked: bool, blockedReason: ?string, weeklyClosed: bool, capacity: int, used: int, hasOverride: bool, isPast: bool, isToday: bool}>
     */
    public function getCalendarDays(): array
    {
        $storeId = $this->effectiveStoreId();

        if (! $storeId) {
            return [];
        }

        $store = Store::find($storeId);
        $monthStart = Carbon::parse($this->month . '-01');
        $monthEnd = $monthStart->copy()->endOfMonth();

        // Mundur ke Senin sebelum tanggal 1 dan maju ke Minggu setelah akhir bulan (dayOfWeekIso: 1=Senin..7=Minggu).
        $gridStart = $monthStart->copy()->subDays($monthStart->dayOfWeekIso - 1);
        $gridEnd = $monthEnd->copy()->addDays(7 - $monthEnd->dayOfWeekIso);

        $overrides = StoreCapacityOverride::where('store_id', $storeId)
            ->whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->get()
            ->keyBy(fn (StoreCapacityOverride $o) => $o->date->toDateString());

        $blockedRows = BlockedDate::where('store_id', $storeId)
            ->whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->get()
            ->keyBy(fn (BlockedDate $b) => $b->date->toDateString());

        // Satu kueri batch untuk seluruh grid, bukan satu per hari (lihat Booking::confirmedOverlapCountsForRange()).
        $usedByDate = Booking::confirmedOverlapCountsForRange($storeId, $gridStart, $gridEnd);

        $today = now()->startOfDay();
        $days = [];

        for ($cursor = $gridStart->copy(); $cursor->lte($gridEnd); $cursor->addDay()) {
            $dateStr = $cursor->toDateString();
            $closed = (bool) $store?->isClosedOn($cursor);
            $blockedRow = $blockedRows->get($dateStr);

            $days[] = [
                'date'          => $dateStr,
                'inMonth'       => $cursor->month === $monthStart->month,
                'closed'        => $closed,
                // Ditutup lewat tanggal tutup (bisa dibuka kembali di sini) atau libur rutin mingguan dari jam operasional toko (diatur di Toko).
                'blocked'       => $blockedRow !== null,
                'blockedReason' => $blockedRow?->reason,
                'weeklyClosed'  => $closed && $blockedRow === null,
                'capacity'      => $overrides->has($dateStr) ? $overrides[$dateStr]->capacity : (int) ($store?->install_capacity_per_day ?: 3),
                'used'          => $closed ? 0 : ($usedByDate[$dateStr] ?? 0),
                'hasOverride'   => $overrides->has($dateStr),
                'isPast'        => $cursor->lt($today),
                'isToday'       => $cursor->isSameDay($today),
            ];
        }

        return $days;
    }
}
