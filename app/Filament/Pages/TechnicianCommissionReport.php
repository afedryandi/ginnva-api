<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\Technician;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * "Laporan Komisi Teknisi" — diminta 2026-09-08, analog "Komisi per
 * Kasir" Majoo (versi Ginnva: komisi per TEKNISI per pekerjaan/mobil,
 * bukan per transaksi kasir). Aturan yang DIKONFIRMASI user:
 * - Nominal TETAP per pekerjaan (Technician::commission_amount, lihat
 *   migrasi 2026_09_08_000002) -- bukan persentase dari nilai transaksi.
 * - Kalau 1 booking ditugaskan >1 teknisi sekaligus, MASING-MASING dapat
 *   komisi PENUH (tidak dibagi rata).
 *
 * Sumber booking yang dihitung SAMA PERSIS dengan laporan Penjualan lain
 * (whereHas('journalEntry') + transaction_amount > 0) supaya konsisten
 * dengan satu sumber kebenaran pendapatan, dan supaya komisi cuma
 * dihitung dari pekerjaan yang benar-benar terbayar/tercatat, bukan
 * booking yang masih pending/batal.
 *
 * Teknisi yang commission_amount-nya BELUM DIATUR (NULL) ditampilkan
 * TERPISAH dengan jumlah pekerjaan tetap dihitung tapi nominal komisi
 * ditandai "Belum diatur" -- BUKAN dihitung sebagai Rp 0, supaya tidak
 * menyesatkan (lihat catatan di migrasi).
 *
 * DIBATASI isFullAccess() SAJA -- sama filosofi EmployeeReport/
 * PayrollResource, ini data kompensasi karyawan.
 */
class TechnicianCommissionReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    // Grup sendiri 'Laporan Karyawan' (diubah 2026-09-09 dari 'Laporan'
    // gabungan) -- satu grup dengan EmployeeReport (absensi & gaji).
    protected static ?string $navigationGroup = 'Laporan Karyawan';

    protected static ?string $navigationLabel = 'Laporan Komisi Teknisi';

    protected static ?string $title = 'Laporan Komisi Teknisi';

    // 401 -- band grup 'Laporan Karyawan' (lihat catatan sistem band di
    // ProductSalesReport.php, diperbaiki 2026-09-09).
    protected static ?int $navigationSort = 401;

    protected static string $view = 'filament.pages.technician-commission-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
        ])->columns(2)->statePath('data');
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();

        $technicians = Technician::query()
            ->whereNotNull('user_id')
            ->with('store:id,name')
            ->orderBy('name')
            ->get()
            ->map(function (Technician $technician) use ($from, $to) {
                // "Penjualan" (diminta 2026-09-09, analog kolom Majoo) --
                // total NILAI TRANSAKSI booking yang ditugaskan ke teknisi
                // ini, BUKAN komisinya sendiri. Kalau 1 booking dikerjakan
                // >1 teknisi, nilainya ikut terhitung penuh di masing-
                // masing teknisi (konsisten dengan aturan komisi "full ke
                // masing-masing", BUKAN dibagi) -- jadi total kolom ini
                // lintas teknisi BISA melebihi total Penjualan sungguhan
                // kalau ada booking tim (disengaja, bukan bug).
                $jobsQuery = Booking::query()
                    ->whereHas('installers', fn ($q) => $q->where('users.id', $technician->user_id))
                    ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
                    ->where('transaction_amount', '>', 0);

                $jobCount = (clone $jobsQuery)->count();
                $salesTotal = (float) (clone $jobsQuery)->sum('transaction_amount');

                $rate = $technician->commission_amount !== null ? (float) $technician->commission_amount : null;

                return [
                    'technician' => $technician,
                    'jobCount' => $jobCount,
                    'salesTotal' => $salesTotal,
                    'rate' => $rate,
                    'totalCommission' => $rate !== null ? $rate * $jobCount : null,
                ];
            })
            ->sortByDesc(fn ($row) => $row['totalCommission'] ?? -1)
            ->values();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $technicians,
            'totalCommission' => $technicians->sum(fn ($row) => $row['totalCommission'] ?? 0),
            'unratedCount' => $technicians->where('rate', null)->where('jobCount', '>', 0)->count(),
        ];
    }
}
