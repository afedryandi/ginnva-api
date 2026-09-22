<?php

namespace App\Filament\Pages;

use App\Exports\ReservationUtilizationReportExport;
use App\Models\Booking;
use App\Models\Store;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/**
 * "Laporan Reservasi & Utilisasi" — diminta 2026-09-09, analog Majoo.
 * AWALNYA (audit 2026-09-08) ditandai blocked ("tingkat utilisasi belum
 * pernah dihitung") -- dikoreksi setelah ditemukan Store.install_capacity_per_day
 * (kapasitas instalasi/hari per toko) + Booking::confirmedOverlapCount()
 * (jumlah booking 'confirmed' yang menyentuh tanggal tertentu, SUMBER
 * KEBENARAN YANG SAMA dipakai BookingResource untuk cek kapasitas saat
 * approve booking) -- 2 bahan itu cukup untuk hitung Utilisasi = booking
 * terpakai / kapasitas.
 *
 * KETERBATASAN PENTING: kapasitas harian yang staff input manual saat
 * approve booking (form "Sisa Slot Instalasi" di BookingResource) TIDAK
 * PERNAH DISIMPAN ke database -- cuma dipakai sesaat untuk validasi,
 * lalu hilang. Jadi laporan historis ini TIDAK BISA tahu kapasitas
 * PERSIS yang berlaku di hari tertentu di masa lalu (mis. kalau tim
 * instalasi kebetulan lagi kurang orang hari itu) -- dipakai
 * Store.install_capacity_per_day (setting DEFAULT toko saat ini) SEBAGAI
 * PENDEKATAN, bukan angka pasti historis. Didokumentasikan di halaman,
 * bukan disembunyikan.
 *
 * Hari libur toko (Store::isClosedOn()) DILEWATI dari perhitungan --
 * toko tutup tidak dihitung sebagai "kapasitas kosong terbuang".
 *
 * Kolom "Kosong" & "Dibatalkan" (2026-09-21, temuan Majoo vs Ginnva
 * "Laporan Utilisasi Ruangan") -- Majoo pecah utilisasi jadi 4 sisi
 * (jam operasional/terpakai/kosong/dibatalkan) PER BAY fisik, tapi
 * Ginnva TIDAK punya entitas bay/stall individual sama sekali di
 * skema (cuma Store.install_capacity_per_day sebagai 1 angka agregat
 * per toko) -- jadi laporan ini tetap per-TOKO, bukan per-bay
 * sungguhan. "Kosong" = totalCapacity - totalUsed. "Dibatalkan" =
 * hitungan booking status='cancelled' di periode ini (informasional,
 * TIDAK dikurangkan dari manapun -- booking cancelled sudah otomatis
 * tidak ikut confirmedOverlapCount()).
 */
class ReservationUtilizationReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Jasa';

    protected static ?string $navigationLabel = 'Laporan Reservasi & Utilisasi';

    protected static ?string $title = 'Laporan Reservasi & Utilisasi';

    // 103 -- band grup 'Laporan Jasa' (lihat catatan sistem band di
    // ProductSalesReport.php).
    protected static ?int $navigationSort = 103;

    protected static string $view = 'filament.pages.reservation-utilization-report';

    // Fallback kalau install_capacity_per_day toko kosong -- SAMA PERSIS
    // default yang dipakai Booking::fullDatesInRange() di alur approve
    // booking, supaya konsisten dengan validasi kapasitas yang
    // sebenarnya berlaku.
    private const DEFAULT_CAPACITY = 3;

    // Dibatasi 62 hari (~2 bulan) -- tiap hari kerja per toko butuh 1
    // query confirmedOverlapCount() terpisah (sama fungsi yang dipakai
    // BookingResource, sengaja dipakai ulang bukan dihitung sendiri
    // supaya angkanya konsisten dengan validasi kapasitas asli), rentang
    // lebih panjang berisiko lambat kalau tokonya banyak.
    private const MAX_RANGE_DAYS = 62;

    public ?array $data = [];

    // #[Url] (audit 2026-09-11, temuan D) — pola sama laporan Penjualan
    // lain.
    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->from = $this->queryDateOrDefault($this->from, now()->startOfMonth());
        $this->to = $this->queryDateOrDefault($this->to, now()->endOfMonth());

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
        ]);
    }

    private function queryDateOrDefault(mixed $value, Carbon $default): string
    {
        if (! is_string($value) || $value === '') {
            return $default->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return $default->toDateString();
        }
    }

    public function updatedData(mixed $value, string $key): void
    {
        match ($key) {
            'from' => $this->from = $value,
            'to' => $this->to = $value,
            default => null,
        };
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live()
                ->helperText('Rentang dibatasi maksimal ' . self::MAX_RANGE_DAYS . ' hari.'),
        ])->columns(2)->statePath('data');
    }

    /**
     * "Ekspor Laporan" (audit 2026-09-11, temuan B) — pola sama laporan
     * Penjualan lain.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new ReservationUtilizationReportExport($this->getResult()),
                    'utilisasi-reservasi-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.reservation_utilization_report', ['result' => $result])->setPaper('a4', 'portrait');
                    $filename = 'utilisasi-reservasi-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->startOfDay();

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $to = $from->copy()->addDays(self::MAX_RANGE_DAYS);
        }

        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;

        $stores = Store::query()
            ->when(! $isFullAccess, fn ($q) => $q->where('id', $user?->store_id))
            ->where('is_active', true)
            ->get();

        $rows = $stores->map(function (Store $store) use ($from, $to) {
            $capacity = max(1, (int) ($store->install_capacity_per_day ?? self::DEFAULT_CAPACITY));

            $workingDays = 0;
            $totalUsed = 0;
            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                if (! $store->isClosedOn($cursor)) {
                    $workingDays++;
                    $totalUsed += Booking::confirmedOverlapCount($store->id, $cursor->copy());
                }
                $cursor->addDay();
            }

            $totalCapacity = $workingDays * $capacity;

            // "Dibatalkan" (audit Majoo vs Ginnva, "Laporan Utilisasi
            // Ruangan") — booking yang di-cancel dengan preferred_date di
            // dalam periode ini. Ini INFORMASIONAL saja (berapa banyak
            // reservasi hangus), TIDAK dikurangkan dari totalUsed —
            // booking yang dibatalkan sudah otomatis tidak lagi dihitung
            // confirmedOverlapCount() (yang hanya menghitung status
            // 'confirmed'), jadi tidak ada risiko dobel hitung.
            $cancelledCount = Booking::query()
                ->where('store_id', $store->id)
                ->where('status', 'cancelled')
                ->whereDate('preferred_date', '>=', $from)
                ->whereDate('preferred_date', '<=', $to)
                ->count();

            // "Tingkat Pembatalan" (audit Majoo, f16: "KPI eksplisit, bukan
            // cuma daftar") — cancelledCount dibagi SELURUH booking yang
            // preferred_date-nya jatuh di periode ini (semua status,
            // BUKAN totalUsed yang satuannya slot kapasitas hari, bukan
            // jumlah booking) supaya rasionya benar "dari sekian reservasi
            // yang masuk, berapa % batal" — bukan tercampur skala kapasitas.
            $totalBookingsCount = Booking::query()
                ->where('store_id', $store->id)
                ->whereDate('preferred_date', '>=', $from)
                ->whereDate('preferred_date', '<=', $to)
                ->count();

            return [
                'store' => $store,
                'capacityPerDay' => $capacity,
                'workingDays' => $workingDays,
                'totalUsed' => $totalUsed,
                'totalCapacity' => $totalCapacity,
                // "Kosong" — sisa kapasitas yang tidak terpakai booking
                // confirmed di periode ini.
                'emptySlots' => max(0, $totalCapacity - $totalUsed),
                'cancelledCount' => $cancelledCount,
                'totalBookingsCount' => $totalBookingsCount,
                'cancellationRatePct' => $totalBookingsCount > 0 ? $cancelledCount / $totalBookingsCount * 100 : 0,
                'utilizationPct' => $totalCapacity > 0 ? min(100, $totalUsed / $totalCapacity * 100) : 0,
            ];
        })->sortByDesc('utilizationPct')->values();

        $totalCancelled = $rows->sum('cancelledCount');
        $totalBookings = $rows->sum('totalBookingsCount');

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            // KPI eksplisit seluruh cabang (audit Majoo f16).
            'cancellationRatePct' => $totalBookings > 0 ? $totalCancelled / $totalBookings * 100 : 0,
        ];
    }
}
