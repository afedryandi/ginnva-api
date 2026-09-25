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

    public function updatedStoreId(): void
    {
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

        $this->editingDate = $date;
        $this->editingCapacity = $this->storeId ? Booking::capacityForDate($this->storeId, Carbon::parse($date)) : null;
    }

    public function closeEdit(): void
    {
        $this->editingDate = null;
    }

    public function saveCapacity(): void
    {
        if (! $this->storeId || ! $this->editingDate || ! $this->editingCapacity || $this->editingCapacity < 1) {
            return;
        }

        StoreCapacityOverride::updateOrCreate(
            ['store_id' => $this->storeId, 'date' => $this->editingDate],
            ['capacity' => $this->editingCapacity, 'updated_by' => auth()->id()],
        );

        $this->editingDate = null;
    }

    /** Hapus override — tanggal itu kembali pakai default toko. */
    public function clearOverride(): void
    {
        if (! $this->storeId || ! $this->editingDate) {
            return;
        }

        StoreCapacityOverride::where('store_id', $this->storeId)
            ->where('date', $this->editingDate)
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
        if (! $this->storeId) {
            return [];
        }

        $store = Store::find($this->storeId);
        $monthStart = Carbon::parse($this->month . '-01');
        $monthEnd = $monthStart->copy()->endOfMonth();

        // Mundur ke Senin terdekat sebelum tanggal 1 (Carbon dayOfWeekIso:
        // 1=Senin..7=Minggu), maju ke Minggu terdekat setelah akhir bulan
        // — supaya grid selalu kelipatan 7 hari penuh.
        $gridStart = $monthStart->copy()->subDays($monthStart->dayOfWeekIso - 1);
        $gridEnd = $monthEnd->copy()->addDays(7 - $monthEnd->dayOfWeekIso);

        $overrides = StoreCapacityOverride::where('store_id', $this->storeId)
            ->whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->get()
            ->keyBy(fn (StoreCapacityOverride $o) => $o->date->toDateString());

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
                'used'        => $closed ? 0 : Booking::confirmedOverlapCount($this->storeId, $cursor->copy()),
                'hasOverride' => $overrides->has($dateStr),
                'isPast'      => $cursor->lt($today),
                'isToday'     => $cursor->isSameDay($today),
            ];

            $cursor->addDay();
        }

        return $days;
    }
}
