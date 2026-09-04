<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AttendanceResource;
use App\Filament\Resources\UserResource;
use App\Models\Attendance;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Pecahan dari DashboardStatsWidget lama — lihat catatan lengkap di
 * BookingStatsWidget. Kartu di sini masuk navigationGroup 'Karyawan'.
 */
class KaryawanStatsWidget extends BaseWidget
{
    protected ?string $heading = 'Karyawan';

    protected static ?int $sort = 6;

    protected function getStats(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->isFullAccess() ?? false;
        $stats = [];

        if ($user?->hasMenuAccess(AttendanceResource::class) ?? false) {
            [$presentToday, $totalEmployees] = $this->todayAttendanceRatio($user, $isSuperAdmin);
            $stats[] = Stat::make('Absensi Hari Ini', "{$presentToday} / {$totalEmployees}")
                ->description($totalEmployees > 0 && $presentToday < $totalEmployees
                    ? ($totalEmployees - $presentToday) . ' karyawan belum absen'
                    : 'Semua karyawan sudah absen')
                ->descriptionIcon('heroicon-m-finger-print')
                ->color($totalEmployees > 0 && $presentToday < $totalEmployees ? 'warning' : 'success')
                // Link ke halaman List polos — di atasnya sudah ada widget
                // "Belum Absen Hari Ini" (lihat NotCheckedInTodayWidget),
                // jawaban sebenarnya dari pertanyaan kartu ini.
                ->url(AttendanceResource::getUrl('index'));
        }

        // Stat tambahan khusus super_admin (gambaran nasional, tidak
        // relevan untuk admin toko karena tidak ber-scope ke 1 toko).
        if ($isSuperAdmin) {
            // SEBELUMNYA User::count() polos — tabel users dipakai bareng
            // untuk staff/admin, installer, DAN partner (lihat
            // User::NO_PANEL_ROLES), jadi angka "Total User Admin" ikut
            // menghitung akun partner/installer yang sebenarnya bukan
            // karyawan Ginnva. Dikecualikan lewat NO_PANEL_ROLES yang
            // sama supaya konsisten dengan definisi "boleh masuk panel
            // Filament" (canAccessStaffArea()) di tempat lain.
            $stats[] = Stat::make('Total User Admin', User::whereDoesntHave('roles', fn ($q) => $q->whereIn('name', User::NO_PANEL_ROLES))->count())
                ->description('Semua akun staff/admin Ginnva (tidak termasuk installer/partner)')
                ->descriptionIcon('heroicon-m-users')
                ->color('gray')
                ->url(UserResource::getUrl('index'));
        }

        return $stats;
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return ($user?->hasMenuAccess(AttendanceResource::class) ?? false)
            || ($user?->isFullAccess() ?? false);
    }

    /**
     * [hadir_atau_tercatat_hari_ini, total_karyawan] — "tercatat" mencakup
     * clock/manual/field_duty (Alpha/Izin BELUM ada untuk hari yang masih
     * berjalan, itu baru dibuat MarkAbsences untuk hari yang sudah lewat).
     * Partner dikecualikan dari total, sama pola dengan seleksi karyawan
     * di form AttendanceResource/PurchaseRequestResource dst.
     */
    protected function todayAttendanceRatio($user, bool $isSuperAdmin): array
    {
        // is_active=false (resign/dinonaktifkan) DIKECUALIKAN dari total —
        // tanpa ini, karyawan yang sudah keluar tapi belum dihapus tetap
        // menggelembungkan angka "belum absen" padahal memang tidak akan
        // pernah absen lagi. Pola sama dengan MarkAbsences/PayrollResource/
        // NotifyExpiringContracts.
        $employeeQuery = User::where('is_active', true)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'));
        if (! $isSuperAdmin) {
            $employeeQuery->where('store_id', $user->store_id);
        }
        $total = $employeeQuery->count();

        $presentQuery = Attendance::where('date', now()->toDateString())
            ->whereIn('entry_type', ['clock', 'manual', 'field_duty']);
        if (! $isSuperAdmin) {
            $presentQuery->where('store_id', $user->store_id);
        }
        $present = $presentQuery->count();

        return [$present, $total];
    }
}
