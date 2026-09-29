<?php

namespace App\Filament\Pages;

use App\Exports\EmployeeReportExport;
use App\Models\Payroll;
use App\Models\Store;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/**
 * "Laporan Karyawan" — diminta 2026-09-08, analog "Laporan Karyawan"
 * Majoo. Data mentahnya (Absensi, Penggajian) sudah lengkap tapi belum
 * ada 1 halaman ringkasan gabungan per karyawan per bulan. Payroll SUDAH
 * mengagregasi Attendance per bulan (working_days_in_month,
 * total_late_minutes, alpha_days, dst — lihat Payroll::generateForMonth())
 * jadi halaman ini TINGGAL menampilkan baris Payroll bulan terpilih,
 * TIDAK query ulang dari Attendance (satu sumber kebenaran, tidak ada
 * risiko angka di sini beda dari menu Penggajian).
 *
 * DIBATASI isFullAccess() SAJA — sama filosofi ketat dengan
 * PayrollResource (gaji_bersih ikut ditampilkan di sini, data paling
 * sensitif di seluruh sistem).
 *
 * SEBELUMNYA di cluster Karyawan — dipindah ke PenjualanCluster
 * (diminta 2026-09-08, permintaan susulan) supaya SEMUA laporan ngumpul
 * di 1 tab Penjualan, tidak tercecer ke cluster lain.
 */
class EmployeeReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    // Grup sendiri 'Laporan Karyawan' (diubah 2026-09-09 dari 'Laporan'
    // gabungan) -- sejajar dengan grup kategori laporan lain. Laporan
    // Komisi Teknisi (TechnicianCommissionReport) ikut grup yang sama.
    protected static ?string $navigationGroup = 'Laporan Karyawan';

    protected static ?string $navigationLabel = 'Laporan Karyawan';

    protected static ?string $title = 'Laporan Karyawan';

    // 400 -- band grup 'Laporan Karyawan' (lihat catatan sistem band di
    // ProductSalesReport.php, diperbaiki 2026-09-09).
    protected static ?int $navigationSort = 400;

    protected static string $view = 'filament.pages.employee-report';

    public ?array $data = [];

    // #[Url] (audit 2026-09-11, temuan D) — pola sama laporan Penjualan
    // lain.
    #[Url(as: 'bulan')]
    public ?string $month = null;

    #[Url(as: 'cabang')]
    public ?int $storeId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public function mount(): void
    {
        // Nilai dari URL divalidasi terhadap 12 opsi bulan yang tersedia
        // di form — kalau tidak valid (mis. lebih dari 12 bulan lalu),
        // fallback ke default (bulan lalu).
        $validMonths = collect(range(0, 11))
            ->map(fn ($i) => Carbon::now()->subMonths($i)->startOfMonth()->toDateString());

        if (! $this->month || ! $validMonths->contains($this->month)) {
            $this->month = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        }

        $this->form->fill(['month' => $this->month, 'store_id' => $this->storeId]);
    }

    public function updatedData(mixed $value, string $key): void
    {
        match ($key) {
            'month' => $this->month = $value,
            'store_id' => $this->storeId = $value ? (int) $value : null,
            default => null,
        };
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('month')
                ->label('Bulan')
                ->options(function () {
                    $options = [];
                    for ($i = 0; $i < 12; $i++) {
                        $date = Carbon::now()->subMonths($i)->startOfMonth();
                        $label = $date->translatedFormat('F Y');
                        if ($i === 0) $label .= ' (masih berjalan!)';
                        $options[$date->toDateString()] = $label;
                    }

                    return $options;
                })
                ->required()
                ->live(),

            // Filter cabang (audit 2026-09-29, sejajar laporan Penjualan lain) -- halaman ini
            // sudah dibatasi isFullAccess() saja, jadi filter ini murni untuk mempersempit.
            Select::make('store_id')
                ->label('Cabang')
                ->placeholder('Semua cabang')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->live(),
        ])->columns(2)->statePath('data');
    }

    /** Link drill-down ke halaman Penggajian, dicari via nama karyawan (PayrollResource tidak punya halaman detail per baris). */
    public function payrollUrl(string $employeeName): string
    {
        return \App\Filament\Resources\PayrollResource::getUrl('index', ['tableSearch' => $employeeName]);
    }

    /** Log ekspor (audit Laporan Karyawan 2026-09-29), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'employee', 'format' => $format, 'month' => $this->data['month'] ?? null, 'store_id' => $this->data['store_id'] ?? null])
                ->log('Ekspor Laporan Karyawan (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
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
                ->action(function () {
                    $this->logExport('xlsx');

                    return Excel::download(
                        new EmployeeReportExport($this->getResult()),
                        'laporan-karyawan-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.employee_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'laporan-karyawan-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        $month = Carbon::parse($this->data['month'] ?? now()->subMonthNoOverflow())->startOfMonth();

        $payrolls = Payroll::query()
            ->with(['user:id,name', 'store:id,name'])
            ->whereDate('period_month', $month->toDateString())
            ->when($this->data['store_id'] ?? null, fn ($q, $storeId) => $q->where('store_id', $storeId))
            ->orderByDesc('net_pay')
            ->get();

        return [
            'month' => $month,
            'payrolls' => $payrolls,
            'totalNetPay' => $payrolls->sum('net_pay'),
            'totalAlphaDays' => $payrolls->sum('alpha_days'),
            'totalLateMinutes' => $payrolls->sum('total_late_minutes'),
        ];
    }
}
