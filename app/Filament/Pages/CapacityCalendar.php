<?php

namespace App\Filament\Pages;

use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\Store;
use App\Models\StoreCapacityOverride;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * "Kalender Kapasitas & Libur" (sebelumnya "Kalender Kapasitas" + menu terpisah "Tanggal Tidak Tersedia", digabung 2026-10-26):
 * satu kalender untuk dua keputusan per tanggal: berapa pekerjaan berat yang boleh masuk (StoreCapacityOverride) dan apakah tanggal
 * itu ditutup penuh (BlockedDate). Dua tabel tetap terpisah; endpoint publik blocked-dates dan aturan penolakan booking tidak berubah.
 * Izin menutup/membuka tanggal memakai kunci hak akses lama 'BlockedDateResource' (lihat canManageClosures()), supaya akses yang sudah
 * diberikan tetap berlaku. Libur nasional/cuti bersama ditentukan manual oleh staf (keputusan pemilik 2026-10-28): tanggal tutup inilah
 * yang dipakai booking, absensi (tanpa Alpha), dan hitungan jam kerja gaji.
 *
 * Riwayat: "Kalender Kapasitas" — redesain kapasitas instalasi per tanggal
 * (diminta user langsung, audit Booking Instalasi 2026-09-25). SEBELUMNYA
 * kapasitas per tanggal TIDAK PERNAH tersimpan — staff mengetik ulang
 * dari nol tiap approve booking (Repeater 'capacities' di form Booking),
 * sehingga staff berbeda bisa mengisi angka berbeda untuk tanggal yang
 * sama tanpa sistem menegur. Halaman ini jadi SATU tempat mengatur
 * override kapasitas per tanggal (StoreCapacityOverride) — form
 * approve booking sekarang MURNI baca (lihat BookingResource::form(),
 * placeholder 'date_range_preview'), tidak ada input kapasitas lagi di
 * sana maupun di endpoint mobile confirm().
 *
 * SENGAJA custom Page + Blade sendiri (bukan Resource/Table) — kalender
 * bulan bukan pola tabel/CRUD baris standar, dan pola "plain Livewire
 * property + wire:click ke method PHP biasa" ini SUDAH TERBUKTI jalan di
 * SalesDashboard.php (bukan menebak API Filament\Actions yang belum
 * pernah diverifikasi di codebase ini).
 */
class CapacityCalendar extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $cluster = \App\Filament\Clusters\BookingCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 100-199 "Operasional". Catatan lama
    // ("setelah Booking Instalasi") sudah tidak relevan sejak dikelompokkan per grup.
    protected static ?string $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 100;

    protected static ?string $navigationLabel = 'Kalender Kapasitas & Libur';

    protected static ?string $title = 'Kalender Kapasitas & Libur';

    protected static string $view = 'filament.pages.capacity-calendar';

    #[Url(as: 'toko')]
    public ?int $storeId = null;

    #[Url(as: 'bulan')]
    public string $month = '';

    public ?string $editingDate = null;

    public ?int $editingCapacity = null;

    /**
     * GAP DIPERBAIKI 2026-09-25 (audit Kalender Kapasitas) -- "siapa &
     * kapan" untuk override tanggal yang sedang dibuka di panel edit.
     * Datanya (updated_by/updated_at) sudah lama ada di
     * StoreCapacityOverride, cuma belum pernah ditampilkan langsung di
     * UI (staff harus gali activity_log manual). null = tanggal ini
     * belum pernah di-override (masih pakai default toko).
     */
    public ?array $editingOverrideInfo = null;

    // Gap "bulk edit rentang tanggal" (audit Kalender Kapasitas
    // 2026-09-25) -- panel terpisah dari klik-per-tanggal, buat skenario
    // umum "minggu depan kapasitas turun jadi 2 karena kurang installer"
    // tanpa klik satu-satu.
    public bool $rangeEditorOpen = false;

    public ?string $rangeFrom = null;

    public ?string $rangeTo = null;

    public ?int $rangeCapacity = null;

    // Alasan tanggal ditutup pada panel hari (isi saat menutup, atau alasan yang tersimpan saat tanggal sudah ditutup).
    public ?string $editingReason = null;

    public bool $editingBlocked = false;

    public bool $closureRangeOpen = false;

    public ?string $closureFrom = null;

    public ?string $closureTo = null;

    public ?string $closureReason = null;

    public bool $closeAllOpen = false;

    public ?string $allFrom = null;

    public ?string $allTo = null;

    public ?string $allReason = null;

    /** Kunci hak akses lama menu "Tanggal Tidak Tersedia"; dipertahankan supaya izin menutup/membuka tanggal yang sudah diberikan tetap berlaku. */
    public const CLOSURE_PERMISSION_KEY = 'BlockedDateResource';

    /** Batas satu kali tutup rentang, jaring pengaman bila staf salah memilih rentang tahunan. */
    public const MAX_CLOSURE_DAYS = 120;

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

    public function mount(): void
    {
        if ($this->month === '' || $this->month === null) {
            $this->month = now()->format('Y-m');
        }

        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;

        if (! $isFullAccess) {
            // Staff toko selalu terkunci ke tokonya sendiri — tidak peduli
            // isi query string ?toko= yang mungkin diutak-atik manual.
            $this->storeId = $user?->store_id;
        } elseif (! $this->storeId) {
            $this->storeId = Store::where('is_active', true)->orderBy('name')->value('id');
        }
    }

    public function getStoreOptions(): array
    {
        return Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Toko yang BENAR-BENAR berlaku setelah aturan akses — SATU-SATUNYA
     * cara yang boleh dipakai membaca toko di method lain di class ini
     * (openDay/saveCapacity/clearOverride/getCalendarDays), TIDAK PERNAH
     * langsung baca $this->storeId mentah.
     *
     * BUG KEAMANAN DIPERBAIKI 2026-09-25 (ditemukan audit lanjutan
     * setelah redesain kapasitas): mount() SEBELUMNYA cuma mengunci
     * $this->storeId ke toko staff SEKALI di awal (page load pertama).
     * $storeId adalah public Livewire property biasa (#[Url]) — request
     * Livewire BERIKUTNYA (mis. payload wire:model/snapshot dimanipulasi
     * lewat DevTools) bisa menimpa nilainya lagi, dan updatedStoreId()
     * SEBELUMNYA tidak mengunci ulang, cuma reset editingDate. Staff
     * toko non-full-access jadi BISA mengubah kapasitas toko LAIN kalau
     * memanipulasi request secara manual. Pola perbaikan SAMA PERSIS
     * dengan SalesDashboard::effectiveStoreId() (sudah terbukti aman di
     * situ): full-access boleh pilih bebas (null = belum pilih toko),
     * non-full-access SELALU dipaksa ke store_id akunnya sendiri apa pun
     * isi $this->storeId saat method ini dipanggil.
     */
    public function effectiveStoreId(): ?int
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false) ? $this->storeId : $user?->store_id;
    }

    public function updatedStoreId(): void
    {
        // Non-full-access TIDAK BOLEH mengubah toko lewat cara apa pun —
        // timpa balik ke toko sendiri setiap kali property ini berubah,
        // bukan cuma mengandalkan mount(). Lihat catatan effectiveStoreId().
        $user = auth()->user();
        if (! ($user?->isFullAccess() ?? false)) {
            $this->storeId = $user?->store_id;
        }

        $this->editingDate = null;
    }

    public function prevMonth(): void
    {
        $this->month = Carbon::parse($this->month . '-01')->subMonthNoOverflow()->format('Y-m');
        $this->editingDate = null;
    }

    public function nextMonth(): void
    {
        $this->month = Carbon::parse($this->month . '-01')->addMonthNoOverflow()->format('Y-m');
        $this->editingDate = null;
    }

    public function goToday(): void
    {
        $this->month = now()->format('Y-m');
        $this->editingDate = null;
    }

    /**
     * Buka panel edit untuk 1 tanggal — cuma tanggal hari ini/ke depan
     * yang boleh diedit (tanggal lampau sudah tidak relevan untuk
     * penjadwalan, sekadar riwayat).
     */
    public function openDay(string $date): void
    {
        if (Carbon::parse($date)->lt(now()->startOfDay())) {
            return;
        }

        $storeId = $this->effectiveStoreId();

        $this->closureRangeOpen = false;
        $this->closeAllOpen = false;
        $this->rangeEditorOpen = false;

        $blocked = $storeId ? BlockedDate::where('store_id', $storeId)->whereDate('date', $date)->first() : null;

        if ($storeId && $blocked === null && Store::find($storeId)?->isClosedOn(Carbon::parse($date))) {
            return;
        }

        $this->editingBlocked = $blocked !== null;
        $this->editingReason = $blocked?->reason;

        $this->editingDate = $date;
        $this->editingCapacity = $storeId ? Booking::capacityForDate($storeId, Carbon::parse($date)) : null;

        $override = $storeId
            ? StoreCapacityOverride::where('store_id', $storeId)->where('date', $date)->with('updatedBy:id,name')->first()
            : null;

        $this->editingOverrideInfo = $override
            ? ['name' => $override->updatedBy?->name ?? '—', 'at' => $override->updated_at->format('d M Y H:i')]
            : null;
    }

    public function closeEdit(): void
    {
        $this->editingDate = null;
        $this->editingOverrideInfo = null;
        $this->editingBlocked = false;
        $this->editingReason = null;
    }

    public function saveCapacity(): void
    {
        $storeId = $this->effectiveStoreId();

        if (! self::canManageCapacity() || ! $storeId || ! $this->editingDate || ! $this->editingCapacity || $this->editingCapacity < 1) {
            return;
        }

        // Tanggal yang ditutup penuh tidak punya kapasitas yang berarti; buka dulu tanggalnya.
        if ($this->editingBlocked) {
            return;
        }

        StoreCapacityOverride::updateOrCreate(
            ['store_id' => $storeId, 'date' => $this->editingDate],
            ['capacity' => $this->editingCapacity, 'updated_by' => auth()->id()],
        );

        $this->editingDate = null;
        $this->editingOverrideInfo = null;
    }

    /** Hapus override — tanggal itu kembali pakai default toko. */
    public function clearOverride(): void
    {
        $storeId = $this->effectiveStoreId();

        if (! self::canManageCapacity() || ! $storeId || ! $this->editingDate) {
            return;
        }

        StoreCapacityOverride::where('store_id', $storeId)
            ->where('date', $this->editingDate)
            ->delete();

        $this->editingDate = null;
        $this->editingOverrideInfo = null;
    }

    public function openRangeEditor(): void
    {
        if (! self::canManageCapacity()) {
            return;
        }

        $this->closureRangeOpen = false;
        $this->closeAllOpen = false;
        $this->rangeEditorOpen = true;
        $this->rangeFrom = now()->toDateString();
        $this->rangeTo = now()->addDays(6)->toDateString();
        $this->rangeCapacity = null;
        $this->editingDate = null;
    }

    public function closeRangeEditor(): void
    {
        $this->rangeEditorOpen = false;
    }

    /**
     * Terapkan 1 angka kapasitas ke SEMUA tanggal dalam rentang
     * [rangeFrom, rangeTo] sekaligus — gap "bulk edit" (audit Kalender
     * Kapasitas 2026-09-25). Tanggal lampau dalam rentang dilewati
     * (bukan gagal total) supaya staff bisa asal pilih "minggu ini" tanpa
     * perlu hitung manual mulai dari hari ini.
     */
    public function applyRangeCapacity(): void
    {
        $storeId = $this->effectiveStoreId();

        if (! self::canManageCapacity() || ! $storeId || ! $this->rangeFrom || ! $this->rangeTo || ! $this->rangeCapacity || $this->rangeCapacity < 1) {
            return;
        }

        $from = Carbon::parse($this->rangeFrom);
        $to = Carbon::parse($this->rangeTo);

        if ($to->lt($from)) {
            return;
        }

        // Dibatasi 90 hari sekali terap -- jaring pengaman kalau staff
        // salah pilih rentang tahunan, bukan batasan bisnis genuine.
        $to = $from->copy()->addDays(89)->lt($to) ? $from->copy()->addDays(89) : $to;

        $today = now()->startOfDay();
        $userId = auth()->id();

        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            if ($cursor->gte($today)) {
                StoreCapacityOverride::updateOrCreate(
                    ['store_id' => $storeId, 'date' => $cursor->toDateString()],
                    ['capacity' => $this->rangeCapacity, 'updated_by' => $userId],
                );
            }

            $cursor->addDay();
        }

        $this->rangeEditorOpen = false;
    }

    /**
     * Hapus SEMUA override toko yang sedang dipilih (kembali ke default
     * install_capacity_per_day untuk semua tanggal) — gap "bulk-clear"
     * (audit Kalender Kapasitas 2026-09-25). Cuma menghapus tanggal
     * hari-ini-ke-depan -- override tanggal lampau dibiarkan (murni
     * riwayat, tidak memengaruhi penjadwalan apa pun lagi).
     */
    public function clearAllOverrides(): void
    {
        $storeId = $this->effectiveStoreId();

        if (! self::canManageCapacity() || ! $storeId) {
            return;
        }

        StoreCapacityOverride::where('store_id', $storeId)
            ->where('date', '>=', now()->toDateString())
            ->delete();

        $this->editingDate = null;
    }

    private function flash(string $title, ?string $body = null, string $level = 'success'): void
    {
        Notification::make()->title($title)->body($body)->{$level}()->send();
    }

    private function cleanReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : mb_substr($reason, 0, 255);
    }

    /** Tutup tanggal yang sedang dibuka di panel hari. Booking yang sudah ada tidak dibatalkan. */
    public function closeDay(): void
    {
        $storeId = $this->effectiveStoreId();

        if (! self::canManageClosures('create') || ! $storeId || ! $this->editingDate || Carbon::parse($this->editingDate)->lt(today())) {
            return;
        }

        $row = BlockedDate::firstOrCreate(
            ['store_id' => $storeId, 'date' => Carbon::parse($this->editingDate)->toDateString()],
            ['reason' => $this->cleanReason($this->editingReason)],
        );

        $this->flash($row->wasRecentlyCreated ? 'Tanggal ditutup' : 'Tanggal ini sudah ditutup sebelumnya', null, $row->wasRecentlyCreated ? 'success' : 'warning');
        $this->closeEdit();
    }

    /** Buka kembali tanggal yang sedang dibuka di panel hari. */
    public function reopenDay(): void
    {
        $storeId = $this->effectiveStoreId();

        if (! self::canManageClosures('delete') || ! $storeId || ! $this->editingDate) {
            return;
        }

        BlockedDate::where('store_id', $storeId)->whereDate('date', $this->editingDate)->get()->each->delete();

        $this->flash('Tanggal dibuka kembali');
        $this->closeEdit();
    }

    public function saveBlockedReason(): void
    {
        $storeId = $this->effectiveStoreId();

        if (! self::canManageClosures('update') || ! $storeId || ! $this->editingDate || ! $this->editingBlocked) {
            return;
        }

        BlockedDate::where('store_id', $storeId)->whereDate('date', $this->editingDate)->get()
            ->each(fn (BlockedDate $row) => $row->update(['reason' => $this->cleanReason($this->editingReason)]));

        $this->flash('Alasan diperbarui');
        $this->closeEdit();
    }

    /** Buka kembali tanggal dari daftar tanggal tutup di bawah kalender. */
    public function reopenById(int $id): void
    {
        $storeId = $this->effectiveStoreId();

        if (! self::canManageClosures('delete') || ! $storeId) {
            return;
        }

        // Dibatasi ke toko yang berlaku: staf toko tidak bisa membuka tanggal toko lain walau id-nya diketik manual.
        BlockedDate::where('store_id', $storeId)->whereKey($id)->get()->each->delete();

        $this->flash('Tanggal dibuka kembali');
    }

    public function openClosureRange(): void
    {
        if (! self::canManageClosures('create')) {
            return;
        }

        $this->closureRangeOpen = true;
        $this->closureFrom = now()->toDateString();
        $this->closureTo = now()->toDateString();
        $this->closureReason = null;
        $this->editingDate = null;
        $this->rangeEditorOpen = false;
        $this->closeAllOpen = false;
    }

    public function closeClosureRange(): void
    {
        $this->closureRangeOpen = false;
    }

    /** Tanggal dalam rentang [from, to] yang valid ditutup: hari ini dan seterusnya, paling banyak MAX_CLOSURE_DAYS hari. */
    private function closureDates(?string $from, ?string $to): array
    {
        if (! $from || ! $to) {
            return [];
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        if ($end->lt($start)) {
            return [];
        }

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
     *
     * @return list<string>
     */
    public function closureOverlapLines(): array
    {
        $storeId = $this->effectiveStoreId();
        $dates = $this->closureRangeOpen ? $this->closureDates($this->closureFrom, $this->closureTo) : [];

        if (! $storeId || $dates === [] || count($dates) > 31) {
            return [];
        }

        $lines = [];

        foreach ($dates as $date) {
            $count = Booking::confirmedOverlapCount($storeId, Carbon::parse($date), null, true); // termasuk layanan ringan

            if ($count > 0) {
                $lines[] = Carbon::parse($date)->format('d M Y') . ": {$count} booking confirmed (tidak ikut dibatalkan, hanya info)";
            }
        }

        return $lines;
    }

    public function applyClosureRange(): void
    {
        $storeId = $this->effectiveStoreId();

        if (! self::canManageClosures('create') || ! $storeId || ! $this->closureFrom || ! $this->closureTo) {
            return;
        }

        if (Carbon::parse($this->closureTo)->lt(Carbon::parse($this->closureFrom))) {
            $this->flash('Tanggal selesai tidak boleh sebelum tanggal mulai', null, 'danger');

            return;
        }

        $dates = $this->closureDates($this->closureFrom, $this->closureTo);

        if ($dates === []) {
            $this->flash('Tidak ada tanggal yang bisa ditutup', 'Tanggal yang sudah lewat tidak bisa ditutup.', 'danger');

            return;
        }

        $reason = $this->cleanReason($this->closureReason);
        $created = 0;

        foreach ($dates as $date) {
            $row = BlockedDate::firstOrCreate(['store_id' => $storeId, 'date' => $date], ['reason' => $reason]);
            $created += $row->wasRecentlyCreated ? 1 : 0;
        }

        $skipped = count($dates) - $created;
        $this->flash("{$created} tanggal ditutup", $skipped > 0 ? "{$skipped} sudah tertutup sebelumnya dan dilewati." : null);
        $this->closureRangeOpen = false;
    }

    public function openCloseAll(): void
    {
        if (! (auth()->user()?->isFullAccess() ?? false) || ! self::canManageClosures('create')) {
            return;
        }

        $this->closeAllOpen = true;
        $this->allFrom = now()->toDateString();
        $this->allTo = now()->toDateString();
        $this->allReason = null;
        $this->editingDate = null;
        $this->rangeEditorOpen = false;
        $this->closureRangeOpen = false;
    }

    public function closeCloseAll(): void
    {
        $this->closeAllOpen = false;
    }

    /**
     * Menutup SEMUA toko aktif sekaligus (libur nasional, cuti bersama) supaya staf tidak mengulang per toko. Tanggal yang sudah tertutup
     * dilewati dan alasannya tidak ditimpa. Hanya akses penuh.
     */
    public function applyCloseAll(): void
    {
        if (! (auth()->user()?->isFullAccess() ?? false) || ! self::canManageClosures('create') || ! $this->allFrom || ! $this->allTo) {
            return;
        }

        $reason = $this->cleanReason($this->allReason);

        if ($reason === null) {
            $this->flash('Alasan wajib diisi', 'Mis. Libur Nasional - Hari Kemerdekaan.', 'danger');

            return;
        }

        if (Carbon::parse($this->allTo)->lt(Carbon::parse($this->allFrom))) {
            $this->flash('Tanggal selesai tidak boleh sebelum tanggal mulai', null, 'danger');

            return;
        }

        $dates = $this->closureDates($this->allFrom, $this->allTo);

        if ($dates === []) {
            $this->flash('Tidak ada tanggal yang bisa ditutup', 'Tanggal yang sudah lewat tidak bisa ditutup.', 'danger');

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
        $this->closeAllOpen = false;
    }

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
     * @return list<array{date: string, inMonth: bool, closed: bool, blocked: bool, blockedReason: ?string, weeklyClosed: bool, capacity: int, used: int, hasOverride: bool, isPast: bool, isToday: bool}>
     *
     * Grid kalender PENUH termasuk hari bulan sebelum/sesudahnya (biar
     * baris pertama/terakhir tetap 7 kolom) — hari di luar bulan aktif
     * ditandai inMonth=false, ditampilkan pudar & tidak bisa diklik.
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

        // Mundur ke Senin terdekat sebelum tanggal 1 (Carbon dayOfWeekIso:
        // 1=Senin..7=Minggu), maju ke Minggu terdekat setelah akhir bulan
        // — supaya grid selalu kelipatan 7 hari penuh.
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

        // GAP DIPERBAIKI 2026-09-25 (audit Kalender Kapasitas) -- SEBELUMNYA
        // confirmedOverlapCount() dipanggil 1x PER HARI di dalam loop di
        // bawah (35-42 query terpisah tiap buka halaman). Sekarang 1
        // query batch untuk seluruh grid, dibaca dari array hasil per
        // tanggal. Lihat Booking::confirmedOverlapCountsForRange().
        $usedByDate = Booking::confirmedOverlapCountsForRange($storeId, $gridStart, $gridEnd);

        $today = now()->startOfDay();
        $days = [];
        $cursor = $gridStart->copy();

        while ($cursor->lte($gridEnd)) {
            $dateStr = $cursor->toDateString();
            $closed = (bool) $store?->isClosedOn($cursor);
            $blockedRow = $blockedRows->get($dateStr);

            $days[] = [
                'date'        => $dateStr,
                'inMonth'     => $cursor->month === $monthStart->month,
                'closed'      => $closed,
                // Ditutup lewat tanggal tutup (bisa dibuka kembali di sini) atau libur rutin mingguan dari jam operasional toko (diatur di Toko).
                'blocked'       => $blockedRow !== null,
                'blockedReason' => $blockedRow?->reason,
                'weeklyClosed'  => $closed && $blockedRow === null,
                'capacity'    => $overrides->has($dateStr) ? $overrides[$dateStr]->capacity : (int) ($store?->install_capacity_per_day ?: 3),
                'used'        => $closed ? 0 : ($usedByDate[$dateStr] ?? 0),
                'hasOverride' => $overrides->has($dateStr),
                'isPast'      => $cursor->lt($today),
                'isToday'     => $cursor->isSameDay($today),
            ];

            $cursor->addDay();
        }

        return $days;
    }
}
