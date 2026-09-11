<?php

namespace App\Filament\Pages;

use App\Services\SalesSnapshotService;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

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
 * Sejak audit 2026-09-10 seluruh perhitungan ditarik ke
 * App\Services\SalesSnapshotService — halaman ini tinggal memanggil
 * ->snapshot() lalu memetakan hasilnya ke bentuk yang dipakai blade.
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

    // #[Url] (audit 2026-09-11) — filter disimpan di query string supaya
    // link bisa di-bookmark/dibagikan dalam kondisi filter tertentu dan
    // bertahan lewat refresh/tombol back, bukan cuma di state Livewire
    // yang hilang begitu halaman dimuat ulang. Alias nama query dibuat
    // ramah-baca (?periode=&tanggal=&cabang=) bukan nama property mentah.
    // Nilai dari query TIDAK dipercaya mentah-mentah — divalidasi/di-clamp
    // di mount() (sama seperti updatedReferenceDate()/setPeriod()).
    #[Url(as: 'periode')]
    public string $period = 'harian';

    #[Url(as: 'tanggal')]
    public string $referenceDate = '';

    /** Filter cabang — hanya untuk full-access. null = seluruh cabang. */
    #[Url(as: 'cabang')]
    public ?int $storeId = null;

    /**
     * Memoisasi hasil getResult() dalam 1 request Livewire. Property
     * private → tidak diserialisasi antar request, otomatis fresh tiap
     * re-render (toggle periode / navigasi tanggal) tapi tidak dihitung
     * ulang kalau dipanggil >1x dalam render yang sama.
     */
    private ?array $resultCache = null;

    private function snapshotService(): SalesSnapshotService
    {
        return app(SalesSnapshotService::class);
    }

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
        // referenceDate/period/storeId sudah di-hydrate dari query string
        // (#[Url]) SEBELUM mount() ini jalan — nilai kosong berarti tidak
        // ada di URL (kunjungan baru), nilai terisi berarti dari link yang
        // dibagikan/di-bookmark. Kedua kasus tetap divalidasi di sini,
        // supaya URL yang diutak-atik manual tidak bisa memaksa periode
        // tidak dikenal atau tanggal masa depan.
        if (! in_array($this->period, SalesSnapshotService::PERIODS, true)) {
            $this->period = 'harian';
        }

        $parsed = null;
        if ($this->referenceDate !== '') {
            try {
                $parsed = Carbon::parse($this->referenceDate);
            } catch (\Throwable) {
                $parsed = null;
            }
        }

        $this->referenceDate = (! $parsed || $parsed->greaterThan(now()))
            ? now()->toDateString()
            : $parsed->toDateString();
    }

    public function setPeriod(string $period): void
    {
        if (! in_array($period, SalesSnapshotService::PERIODS, true)) {
            return;
        }

        $this->period = $period;
        $this->referenceDate = now()->toDateString();
        $this->resultCache = null;
    }

    public function goPrev(): void
    {
        $this->referenceDate = $this->snapshotService()
            ->shift(Carbon::parse($this->referenceDate), $this->period, -1)
            ->toDateString();
        $this->resultCache = null;
    }

    public function goNext(): void
    {
        // Tidak boleh maju melewati periode yang mengandung hari ini —
        // sama pola dengan tombol '>' Majoo yang disabled begitu sampai
        // periode berjalan (lihat screenshot 08 Sep 26 - 08 Sep 26).
        $next = $this->snapshotService()->shift(Carbon::parse($this->referenceDate), $this->period, 1);
        if ($next->greaterThan(now())) {
            return;
        }

        $this->referenceDate = $next->toDateString();
        $this->resultCache = null;
    }

    // Dipicu date-picker "Lompat ke tanggal" — clamp ke hari ini supaya
    // tidak bisa lihat periode masa depan (konsisten dengan goNext()).
    public function updatedReferenceDate($value): void
    {
        if ($value && Carbon::parse($value)->greaterThan(now())) {
            $this->referenceDate = now()->toDateString();
        }
        $this->resultCache = null;
    }

    public function updatedStoreId(): void
    {
        $this->resultCache = null;
    }

    /** Daftar toko untuk filter (full-access saja). */
    public function getStoreOptions(): array
    {
        return \App\Models\Store::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function currentRange(): array
    {
        return $this->snapshotService()->range($this->period, Carbon::parse($this->referenceDate));
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
        $next = $this->snapshotService()->shift(Carbon::parse($this->referenceDate), $this->period, 1);

        return $next->lessThanOrEqualTo(now());
    }

    /**
     * Toko yang BENAR-BENAR berlaku setelah aturan akses: full-access
     * boleh pilih (null = semua cabang), staff toko SELALU dikunci ke
     * tokonya sendiri (tidak peduli isi $this->storeId). Satu method ini
     * dipakai untuk metrik ringkasan (getResult), drill-down link, DAN
     * kedua chart di blade (audit 2026-09-11, temuan #2) — supaya
     * semuanya konsisten scope ke cabang yang sama.
     */
    public function effectiveStoreId(): ?int
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false) ? $this->storeId : $user?->store_id;
    }

    public function getResult(): array
    {
        if ($this->resultCache !== null) {
            return $this->resultCache;
        }

        $snapshot = $this->snapshotService()->snapshot(
            $this->period,
            Carbon::parse($this->referenceDate),
            $this->effectiveStoreId()
        );

        return $this->resultCache = [
            'current' => $snapshot['current'],
            'previous' => $snapshot['previous'],
            'monthToDateRevenue' => $snapshot['monthToDateNet'],
            'projection' => $snapshot['projection'],
            'growthDelta' => $snapshot['growthDelta'],
            'growthHasComparison' => $snapshot['growthHasComparison'],
            'pendingCount' => $snapshot['pendingCount'],
        ];
    }
}
