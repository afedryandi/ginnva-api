<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AttendanceResource;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\PartnershipInquiryResource;
use App\Filament\Resources\ProductInquiryResource;
use App\Filament\Resources\WarrantyResource;
use App\Models\Attendance;
use App\Models\Booking;
use App\Models\PartnershipInquiry;
use App\Models\ProductInquiry;
use App\Models\User;
use App\Models\Warranty;
use Filament\Widgets\Widget;

/**
 * "Perlu Perhatian" — diminta 2026-09-25 (audit Dashboard Utama, gap
 * "standar enterprise dashboard"): sebelumnya info urgent lintas-modul
 * (garansi hampir kedaluwarsa, QA belum direview, piutang, karyawan
 * belum absen, inquiry belum ditindaklanjuti) tersebar di kartu-kartu
 * statistik terpisah yang tercampur dengan angka non-urgent (Total User
 * Admin, Toko Aktif, dst) — admin harus scan semua kartu satu-satu
 * untuk tahu apa yang butuh tindakan hari ini. Widget ini konsolidasi
 * SEMUA sinyal urgent yang sudah dihitung widget lain (BookingStatsWidget,
 * KaryawanStatsWidget, MarketingStatsWidget) jadi satu daftar ringkas di
 * ATAS dashboard (sort=-1, sebelum BookingRevenueStatsWidget=0) — pola
 * "action items panel" ala dashboard enterprise (Majoo, Xero, dll.).
 *
 * SENGAJA custom Widget + view sendiri (BUKAN Table::records() closure)
 * — data di sini heterogen (garansi, piutang, absensi, inquiry, masing-
 * masing dari model & query berbeda), tidak natural dipaksa jadi satu
 * Eloquent query tabel. Filament\Widgets\Widget dengan view sendiri
 * adalah API dasar yang paling stabil untuk kasus non-tabular begini.
 *
 * TIDAK menduplikasi logika hitung — angka di sini SENGAJA dihitung ulang
 * dengan query yang sama persis (scope toko, definisi "belum lunas",
 * dll.) dengan widget sumbernya, supaya kalau nanti definisi berubah di
 * satu tempat, gampang ketahuan kalau lupa disinkronkan ke sini juga.
 */
class PerluPerhatianWidget extends Widget
{
    protected static string $view = 'filament.widgets.perlu-perhatian';

    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    // Paling atas — sebelum BookingRevenueStatsWidget (sort=0). Lihat
    // urutan lengkap widget Dashboard Utama di
    // BookingRevenueByCategoryChart.php.
    protected static ?int $sort = -1;

    /**
     * Override filter cabang (audit Dashboard Utama 2026-09-25) — sama
     * pola dengan BookingStatsWidget/BookingRevenueStatsWidget.
     */
    public ?int $storeId = null;

    public function mount(?int $storeId = null): void
    {
        $this->storeId = $storeId;
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return ($user?->hasMenuAccess(WarrantyResource::class) ?? false)
            || ($user?->hasMenuAccess(BookingResource::class) ?? false)
            || ($user?->hasMenuAccess(AttendanceResource::class) ?? false)
            || ($user?->hasMenuAccess(ProductInquiryResource::class) ?? false)
            || ($user?->hasMenuAccess(PartnershipInquiryResource::class) ?? false);
    }

    /**
     * @return list<array{label: string, count: int, color: string, icon: string, url: string}>
     */
    public function getItems(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->isFullAccess() ?? false;
        $items = [];

        if ($user?->hasMenuAccess(WarrantyResource::class) ?? false) {
            $expiringQuery = Warranty::query()
                ->where('review_status', 'approved')
                ->whereDate('expiry_date', '>=', now())
                ->whereDate('expiry_date', '<=', now()->addDays(30));
            if (! $isSuperAdmin) {
                $expiringQuery->where(fn ($q) => $q->where('store_id', $user->store_id)->orWhereNull('store_id'));
            } elseif ($this->storeId) {
                $expiringQuery->where('store_id', $this->storeId);
            }
            $expiringCount = $expiringQuery->count();
            if ($expiringCount > 0) {
                $items[] = [
                    'label' => 'Garansi Hampir Kedaluwarsa',
                    'description' => 'Berakhir dalam 30 hari ke depan',
                    'count' => $expiringCount,
                    'color' => 'danger',
                    'icon' => 'heroicon-o-exclamation-triangle',
                    'url' => WarrantyResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'active']]]),
                ];
            }

            $pendingQuery = Warranty::query()->where('review_status', 'pending_review');
            if (! $isSuperAdmin) {
                $pendingQuery->where(fn ($q) => $q->where('store_id', $user->store_id)->orWhereNull('store_id'));
            } elseif ($this->storeId) {
                $pendingQuery->where('store_id', $this->storeId);
            }
            $pendingCount = $pendingQuery->count();
            if ($pendingCount > 0) {
                $items[] = [
                    'label' => 'Garansi Menunggu Review QA',
                    'description' => 'QA Certificate belum di-approve/reject',
                    'count' => $pendingCount,
                    'color' => 'warning',
                    'icon' => 'heroicon-o-clock',
                    'url' => WarrantyResource::getUrl('index', ['tableFilters' => ['review_status' => ['value' => 'pending_review']]]),
                ];
            }
        }

        if ($user?->hasMenuAccess(BookingResource::class) ?? false) {
            $outstandingCount = $this->countOutstandingBookingsThisMonth($user, $isSuperAdmin);
            if ($outstandingCount > 0) {
                $items[] = [
                    'label' => 'Booking Belum Lunas (Bulan Ini)',
                    'description' => 'Ada sisa piutang yang perlu ditagih',
                    'count' => $outstandingCount,
                    'color' => 'warning',
                    'icon' => 'heroicon-o-banknotes',
                    'url' => BookingResource::getUrl('index'),
                ];
            }
        }

        if ($user?->hasMenuAccess(AttendanceResource::class) ?? false) {
            $notCheckedIn = $this->countNotCheckedInToday($user, $isSuperAdmin);
            if ($notCheckedIn > 0) {
                $items[] = [
                    'label' => 'Karyawan Belum Absen Hari Ini',
                    'description' => 'Belum ada catatan Attendance untuk hari ini',
                    'count' => $notCheckedIn,
                    'color' => 'warning',
                    'icon' => 'heroicon-o-finger-print',
                    'url' => AttendanceResource::getUrl('index'),
                ];
            }
        }

        if ($user?->hasMenuAccess(ProductInquiryResource::class) ?? false) {
            $newInquiryCount = ProductInquiry::where('status', 'new')->count();
            if ($newInquiryCount > 0) {
                $items[] = [
                    'label' => 'Inquiry Produk Belum Ditindaklanjuti',
                    'description' => 'Pertanyaan ketersediaan produk masih baru',
                    'count' => $newInquiryCount,
                    'color' => 'info',
                    'icon' => 'heroicon-o-question-mark-circle',
                    'url' => ProductInquiryResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'new']]]),
                ];
            }
        }

        if ($user?->hasMenuAccess(PartnershipInquiryResource::class) ?? false) {
            $newPartnershipCount = PartnershipInquiry::where('status', 'new')->count();
            if ($newPartnershipCount > 0) {
                $items[] = [
                    'label' => 'Kemitraan Belum Ditindaklanjuti',
                    'description' => 'Pengajuan dealer/distributor masih baru',
                    'count' => $newPartnershipCount,
                    'color' => 'info',
                    'icon' => 'heroicon-o-briefcase',
                    'url' => PartnershipInquiryResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'new']]]),
                ];
            }
        }

        return $items;
    }

    /**
     * Sama definisi "belum lunas" dengan SalesDetailStatsWidget::aggregate()
     * (selisih transaction_amount - amount_received > 0.009, status bukan
     * cancelled) dan periode sama dengan BookingRevenueStatsWidget (bulan
     * berjalan, journalEntry.entry_date) — supaya angka di sini selalu
     * konsisten dengan Piutang (Bulan Ini) & Detail Penjualan.
     */
    private function countOutstandingBookingsThisMonth($user, bool $isSuperAdmin): int
    {
        $start = now()->startOfMonth()->toDateString();
        $end = now()->endOfMonth()->toDateString();

        $query = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$start, $end]))
            ->where('transaction_amount', '>', 0)
            ->where('status', '!=', 'cancelled')
            ->whereRaw('(transaction_amount - COALESCE(amount_received, transaction_amount)) > 0.009');

        if (! $isSuperAdmin) {
            $query->where('store_id', $user->store_id);
        } elseif ($this->storeId) {
            $query->where('store_id', $this->storeId);
        }

        return $query->count();
    }

    /**
     * Sama definisi dengan KaryawanStatsWidget::todayAttendanceRatio() —
     * karyawan aktif, bukan partner, dikurangi yang sudah punya baris
     * Attendance hari ini (clock/manual/field_duty).
     */
    private function countNotCheckedInToday($user, bool $isSuperAdmin): int
    {
        $employeeQuery = User::where('is_active', true)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'));
        if (! $isSuperAdmin) {
            $employeeQuery->where('store_id', $user->store_id);
        } elseif ($this->storeId) {
            $employeeQuery->where('store_id', $this->storeId);
        }
        $total = $employeeQuery->count();

        $presentQuery = Attendance::where('date', now()->toDateString())
            ->whereIn('entry_type', ['clock', 'manual', 'field_duty']);
        if (! $isSuperAdmin) {
            $presentQuery->where('store_id', $user->store_id);
        } elseif ($this->storeId) {
            $presentQuery->where('store_id', $this->storeId);
        }
        $present = $presentQuery->count();

        return max(0, $total - $present);
    }
}
