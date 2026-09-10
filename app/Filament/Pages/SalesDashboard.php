<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\Refund;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * "Dashboard Penjualan" — diminta 2026-09-08, referensi
 * dashboard.majoo.id/sales-dashboard. Versi PERTAMA (widget statis
 * Hari Ini/Bulan Ini) TETAP ada di app/Filament/Widgets/
 * BookingRevenue*.php dan tetap tampil di Dashboard utama /admin —
 * halaman INI (tab Penjualan) diganti total jadi versi INTERAKTIF
 * (permintaan susulan): toggle Harian/Mingguan/Bulanan + navigasi
 * tanggal, sama pola Majoo persis. BUKAN extend Filament\Pages\
 * Dashboard atau merender StatsOverviewWidget/ChartWidget — Filament
 * Page sendiri sudah Livewire component, jadi $period/$referenceDate
 * cukup jadi public property biasa, tidak perlu wiring widget terpisah.
 *
 * Sumber tetap SAMA PERSIS dengan widget lain (whereHas('journalEntry'),
 * transaction_amount > 0) — SATU sumber kebenaran, konsisten dengan
 * Jurnal Umum & SalesResource.
 */
class SalesDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    // 2026-09-10: KELUAR dari cluster. Struktur baru ala Majoo —
    // "Penjualan" jadi navigationGroup (bukan Cluster lagi), halaman ini
    // berdiri langsung di bawah grup itu sebagai sibling dari cluster
    // "Laporan" (bekas PenjualanCluster). Hasilnya di top-nav: dropdown
    // "Penjualan" > [Dashboard, Laporan].
    protected static ?string $navigationGroup = 'Penjualan';

    // 14 (bukan 1) — di top-nav, urutan grup ikut nilai sort TERKECIL
    // anggotanya (pola sama seperti sub-nav cluster, lihat memory
    // filament_cluster_navigation_group_order). Cluster 'Laporan' =
    // sort 15; item ini 14 supaya (a) grup 'Penjualan' mendarat di
    // posisi top-nav ~sama seperti dulu, (b) "Dashboard" tampil di atas
    // "Laporan" di dalam dropdown.
    protected static ?int $navigationSort = 14;

    // Diganti jadi "Dashboard" saja (diminta 2026-09-08) -- sudah jelas
    // dari konteksnya berada di tab Penjualan, "Penjualan" di nama jadi
    // berlebihan.
    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard';

    protected static string $view = 'filament.pages.sales-dashboard';

    public string $period = 'harian';

    public string $referenceDate;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Permission SENDIRI (bukan lagi ikut BookingResource) — angka omzet
        // adalah data manajemen, tidak semua orang yang bisa input jadwal
        // booking perlu melihatnya. Diatur di "Akses Menu" akun user.
        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->referenceDate = now()->toDateString();
    }

    public function setPeriod(string $period): void
    {
        if (! in_array($period, ['harian', 'mingguan', 'bulanan'], true)) {
            return;
        }

        $this->period = $period;
        $this->referenceDate = now()->toDateString();
    }

    public function goPrev(): void
    {
        $this->referenceDate = $this->shift($this->referenceDate, $this->period, -1)->toDateString();
    }

    public function goNext(): void
    {
        // Tidak boleh maju melewati periode yang mengandung hari ini —
        // sama pola dengan tombol '>' Majoo yang disabled begitu sampai
        // periode berjalan (lihat screenshot 08 Sep 26 - 08 Sep 26).
        $next = $this->shift($this->referenceDate, $this->period, 1);
        if ($next->greaterThan(now())) {
            return;
        }

        $this->referenceDate = $next->toDateString();
    }

    private function shift(string $date, string $period, int $direction): Carbon
    {
        $carbon = Carbon::parse($date);

        return match ($period) {
            'harian' => $carbon->addDays($direction),
            'mingguan' => $carbon->addWeeks($direction),
            'bulanan' => $carbon->addMonthsNoOverflow($direction),
            default => $carbon,
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function currentRange(): array
    {
        $ref = Carbon::parse($this->referenceDate);

        return match ($this->period) {
            'harian' => [$ref->copy()->startOfDay(), $ref->copy()->endOfDay()],
            'mingguan' => [$ref->copy()->startOfWeek(Carbon::MONDAY), $ref->copy()->endOfWeek(Carbon::SUNDAY)],
            'bulanan' => [$ref->copy()->startOfMonth(), $ref->copy()->endOfMonth()],
            default => [$ref->copy()->startOfDay(), $ref->copy()->endOfDay()],
        };
    }

    /**
     * Periode SEBELUMNYA yang sama panjangnya, langsung berbatasan
     * dengan awal periode berjalan — pembanding apple-to-apple, sama
     * definisi dengan badge %perubahan di BookingRevenueStatsWidget.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function previousRange(): array
    {
        [$start, $end] = $this->currentRange();

        return match ($this->period) {
            'harian' => [$start->copy()->subDay(), $end->copy()->subDay()],
            'mingguan' => [$start->copy()->subWeek(), $end->copy()->subWeek()],
            'bulanan' => [$start->copy()->subMonthNoOverflow()->startOfMonth(), $start->copy()->subMonthNoOverflow()->endOfMonth()],
            default => [$start->copy()->subDay(), $end->copy()->subDay()],
        };
    }

    public function getRangeLabel(): string
    {
        [$start, $end] = $this->currentRange();

        return $start->isSameDay($end)
            ? $start->translatedFormat('d M Y')
            : $start->translatedFormat('d M Y') . ' - ' . $end->translatedFormat('d M Y');
    }

    public function canGoNext(): bool
    {
        $next = $this->shift($this->referenceDate, $this->period, 1);

        return $next->lessThanOrEqualTo(now());
    }

    public function getResult(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->isFullAccess() ?? false;

        [$start, $end] = $this->currentRange();
        [$prevStart, $prevEnd] = $this->previousRange();

        $current = $this->summarize($start, $end, $user, $isSuperAdmin);
        $previous = $this->summarize($prevStart, $prevEnd, $user, $isSuperAdmin);

        // "Akumulasi dari Awal Bulan" & "Proyeksi Bulan Ini" SELALU
        // dihitung dari bulan KALENDER berjalan (bukan ikut $period
        // terpilih) — sama seperti Majoo yang tetap menampilkan 2 baris
        // ini apa pun toggle Harian/Mingguan/Bulan yang dipilih.
        // Pakai angka BERSIH (dikurangi refund) supaya konsisten dgn P&L.
        $monthStart = now()->startOfMonth();
        $monthToDate = $this->summarize($monthStart, now()->endOfDay(), $user, $isSuperAdmin);
        $monthToDateNet = $monthToDate['revenue'] - $monthToDate['refund'];
        $daysElapsed = now()->day;
        $daysInMonth = now()->daysInMonth;
        $projection = $daysElapsed > 0 ? ($monthToDateNet / $daysElapsed) * $daysInMonth : 0;

        // "Growth insight banner" ala Majoo ("penjualanmu bulan ini
        // meningkat senilai Rp4.700.000") — dibandingkan ke bulan lalu
        // TAPI cuma sejumlah hari yang SAMA sudah berjalan bulan ini
        // (apple-to-apple, bukan bulan lalu PENUH vs bulan ini yang
        // masih separuh jalan — itu SELALU kelihatan "turun" padahal
        // cuma belum selesai sebulan).
        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();
        $comparableDayCount = min($daysElapsed, $lastMonthStart->daysInMonth);
        $lastMonthToDate = $this->summarize(
            $lastMonthStart,
            $lastMonthStart->copy()->addDays($comparableDayCount - 1)->endOfDay(),
            $user,
            $isSuperAdmin
        );

        // Booking SELESAI yang belum "Proses Referral" (belum ada jurnal
        // pendapatan) — pendapatan yang SUDAH terjadi tapi belum tercatat.
        // Booking cancelled tidak mungkin punya jurnal (cancel diblokir
        // setelah 'completed'), jadi tidak perlu dikecualikan lagi.
        $pendingQuery = Booking::query()
            ->where('status', 'completed')
            ->whereDoesntHave('journalEntry');
        if (! $isSuperAdmin) {
            $pendingQuery->where('store_id', $user->store_id);
        }

        $lastMonthToDateNet = $lastMonthToDate['revenue'] - $lastMonthToDate['refund'];

        return [
            'current' => $current,
            'previous' => $previous,
            'monthToDateRevenue' => $monthToDateNet,
            'projection' => $projection,
            'growthDelta' => $monthToDateNet - $lastMonthToDateNet,
            'growthHasComparison' => $lastMonthToDateNet > 0,
            'pendingCount' => $pendingQuery->count(),
        ];
    }

    /**
     * Booking cancelled TIDAK mungkin masuk sini (cancel diblokir setelah
     * status 'completed', jurnal cuma dibuat setelah 'completed' + Proses
     * Referral) — jadi filter whereHas('journalEntry') sudah cukup.
     * `refund` = nominal refund yang DIPROSES dalam periode ini (by
     * Refund.created_at) — SAMA definisi dengan SalesSummaryReport supaya
     * "Total Penjualan (bersih)" di dashboard = "Penjualan Bersih" di P&L.
     *
     * @return array{revenue: float, received: float, outstanding: float, count: int, productsSold: int, refund: float}
     */
    private function summarize(Carbon $start, Carbon $end, $user, bool $isSuperAdmin): array
    {
        $query = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()]))
            ->where('transaction_amount', '>', 0);

        if (! $isSuperAdmin) {
            // Strict per toko — samakan dengan SalesResource. store_id
            // di bookings NOT NULL (lihat migrasi create_bookings_table),
            // jadi orWhereNull() dulu itu dead code + potensi bocor angka
            // toko lain untuk manajer.
            $query->where('store_id', $user->store_id);
        }

        $revenue = 0.0;
        $received = 0.0;
        $count = 0;
        // "Produk Terjual" ala Majoo dipetakan ke jumlah PRODUK (kategori)
        // yang tercakup per booking — booking Kaca Film+PPF+Detailing
        // sekaligus dihitung 3, bukan 1 — Ginnva tidak jual satuan barang
        // diskrit seperti retail, jadi flag product_* adalah definisi
        // paling masuk akal dari data yang ada.
        $productsSold = 0;

        $query->get(['transaction_amount', 'amount_received', 'product_kaca_film', 'product_ppf', 'product_detailing'])
            ->each(function (Booking $booking) use (&$revenue, &$received, &$count, &$productsSold) {
                $amount = (float) $booking->transaction_amount;
                $receivedAmount = $booking->amount_received !== null ? (float) $booking->amount_received : $amount;

                $revenue += $amount;
                $received += $receivedAmount;
                $count++;
                $productsSold += ($booking->product_kaca_film ? 1 : 0)
                    + ($booking->product_ppf ? 1 : 0)
                    + ($booking->product_detailing ? 1 : 0);
            });

        $refund = (float) Refund::query()
            ->whereBetween('created_at', [$start, $end])
            ->when(! $isSuperAdmin, fn ($q) => $q->whereHas('booking', fn ($q2) => $q2->where('store_id', $user->store_id)))
            ->sum('amount');

        return [
            'revenue' => $revenue,
            'received' => $received,
            'outstanding' => max(0, $revenue - $received),
            'count' => $count,
            'productsSold' => $productsSold,
            'refund' => $refund,
        ];
    }
}
