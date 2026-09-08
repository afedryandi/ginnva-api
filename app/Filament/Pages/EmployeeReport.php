<?php

namespace App\Filament\Pages;

use App\Models\Payroll;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

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

    // Dikelompokkan di bawah heading sidebar 'Laporan' (diminta
    // 2026-09-08) -- Dashboard Penjualan (SalesDashboard) SENGAJA tidak
    // ikut, tetap berdiri sendiri di atas grup ini.
    protected static ?string $navigationGroup = 'Laporan';

    protected static ?string $navigationLabel = 'Laporan Karyawan';

    protected static ?string $title = 'Laporan Karyawan';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.pages.employee-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'month' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
        ]);
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
                ->required(),
        ])->statePath('data');
    }

    public function getResult(): array
    {
        $month = Carbon::parse($this->data['month'] ?? now()->subMonthNoOverflow())->startOfMonth();

        $payrolls = Payroll::query()
            ->with(['user:id,name', 'store:id,name'])
            ->whereDate('period_month', $month->toDateString())
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
