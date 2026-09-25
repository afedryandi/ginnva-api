<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\Store;
use App\Models\StoreCapacityOverride;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * "Kalender Kapasitas" — redesain kapasitas instalasi per tanggal
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

    // Setelah BookingResource (Booking Instalasi, sort 20) — sub-tool
    // langsung dari alur approve booking, wajar ditaruh tepat di
    // bawahnya di sidebar cluster Booking.
    protected static ?int $navigationSort = 21;

    protected static ?string $navigationLabel = 'Kalender Kapasitas';

    protected static ?string $title = 'Kalender Kapasitas Instalasi';

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

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Akses SAMA dengan menu Booking Instalasi — kalender ini murni
        // sub-tool dari alur approve booking, bukan modul terpisah yang
        // butuh permission sendiri.
        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(\App\Filament\Resources\BookingResource::class);
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
    }

    public function saveCapacity(): void
    {
        $storeId = $this->effectiveStoreId();

        if (! $storeId || ! $this->editingDate || ! $this->editingCapacity || $this->editingCapacity < 1) {
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

        if (! $storeId || ! $this->editingDate) {
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

        if (! $storeId || ! $this->rangeFrom || ! $this->rangeTo || ! $this->rangeCapacity || $this->rangeCapacity < 1) {
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

        if (! $storeId) {
            return;
        }

        StoreCapacityOverride::where('store_id', $storeId)
            ->where('date', '>=', now()->toDateString())
            ->delete();

        $this->editingDate = null;
    }

    /**
     * @return list<array{date: string, inMonth: bool, closed: bool, capacity: int, used: int, hasOverride: bool, isPast: bool, isToday: bool}>
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

            $days[] = [
                'date'        => $dateStr,
                'inMonth'     => $cursor->month === $monthStart->month,
                'closed'      => $closed,
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
