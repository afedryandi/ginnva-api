<?php

namespace App\Filament\Pages;

use App\Models\Attendance;
use App\Models\Store;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * "Laporan Absensi" — diminta 2026-09-09, analog "Absensi" Majoo. BEDA
 * dari EmployeeReport ("Laporan Karyawan") yang ringkasan BULANAN dari
 * Payroll -- halaman ini absensi HARIAN mentah, langsung dari
 * `Attendance` (clock_in_at/clock_out_at/late_minutes/early_leave_minutes
 * per baris, sudah lengkap tersimpan dari fitur Absensi mobile app).
 *
 * KETERBATASAN: Attendance TIDAK menyimpan "Jadwal Masuk/Pulang"
 * (jam target) per baris -- cuma late_minutes/early_leave_minutes yang
 * SUDAH dihitung sistem terhadap jadwal itu saat clock in/out. Jadi
 * kolom "Jadwal Masuk/Pulang" ala Majoo TIDAK ditampilkan (datanya
 * tidak tersimpan terpisah), tapi hasil perhitungannya (terlambat/
 * pulang cepat berapa menit) tetap akurat karena diambil dari kolom
 * yang sama yang dipakai AttendanceResource.
 *
 * "Masuk Lebih Cepat"/"Keluar Lebih Lama" (datang sebelum jadwal/pulang
 * setelah jadwal) Majoo TIDAK ditampilkan -- sistem Ginnva cuma
 * menyimpan sisi negatifnya (telat/pulang cepat), tidak ada kolom
 * simetris untuk "lebih awal/lebih lama" dari jadwal.
 */
class AttendanceReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Karyawan';

    protected static ?string $navigationLabel = 'Absensi';

    protected static ?string $title = 'Laporan Absensi';

    // 402 -- band grup 'Laporan Karyawan' (lihat catatan sistem band di
    // ProductSalesReport.php).
    protected static ?int $navigationSort = 402;

    protected static string $view = 'filament.pages.attendance-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'store_id' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
            Select::make('store_id')
                ->label('Toko')
                ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                ->searchable()
                ->placeholder('Semua Toko')
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                ->live(),
        ])->columns(3)->statePath('data');
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth());
        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;

        $rows = Attendance::query()
            ->with(['user:id,name', 'store:id,name'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when(! $isFullAccess, fn ($q) => $q->where('store_id', $user?->store_id))
            ->when($isFullAccess && ! empty($this->data['store_id']), fn ($q) => $q->where('store_id', $this->data['store_id']))
            ->orderByDesc('date')
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'onTimeCount' => $rows->filter(fn (Attendance $a) => in_array($a->entry_type, ['clock', 'manual', 'field_duty'], true) && $a->late_minutes <= 0 && $a->early_leave_minutes <= 0)->count(),
            'lateCount' => $rows->where('late_minutes', '>', 0)->count(),
            'earlyLeaveCount' => $rows->where('early_leave_minutes', '>', 0)->count(),
            'alphaCount' => $rows->where('entry_type', 'alpha')->count(),
            'leaveCount' => $rows->where('entry_type', 'leave')->count(),
        ];
    }
}
