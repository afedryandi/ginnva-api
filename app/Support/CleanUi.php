<?php

namespace App\Support;

/**
 * Daftar menu (Resource) yang tampilannya sudah dirombak ke gaya bersih. Untuk halaman daftar/ubah/lihat/buat menu ini, hook di
 * AdminPanelProvider menyisipkan resources/views/filament/partials/admin-ui-styles.blade.php. Menu lain tidak terpengaruh.
 * Menu baru yang dirombak cukup ditambahkan ke daftar ini.
 */
class CleanUi
{
    /** @var list<class-string<\Filament\Resources\Resource>> */
    public const RESOURCES = [
        \App\Filament\Resources\QuotationResource::class,
        \App\Filament\Resources\BookingResource::class,
        \App\Filament\Resources\SpkResource::class,
        \App\Filament\Resources\BookingServiceTypeResource::class,
        \App\Filament\Resources\QcChecklistItemResource::class,
        \App\Filament\Resources\AppSettingResource::class,
        \App\Filament\Resources\InvoiceResource::class,
        \App\Filament\Resources\WarrantyResource::class,
        \App\Filament\Resources\StoreReviewResource::class,
        \App\Filament\Resources\BookingReworkResource::class,
        \App\Filament\Resources\TransactionApprovalRequestResource::class,
        \App\Filament\Resources\InventoryItemResource::class,
        \App\Filament\Resources\RawMaterialResource::class,
        \App\Filament\Resources\MaterialMemoResource::class,
        \App\Filament\Resources\PurchaseRequestResource::class,
        \App\Filament\Resources\SupplierResource::class,
        \App\Filament\Resources\InventoryMovementResource::class,
        \App\Filament\Resources\RawMaterialMovementResource::class,
        \App\Filament\Resources\StockWriteOffResource::class,
        \App\Filament\Resources\StockOpnameResource::class,
        \App\Filament\Resources\AttendanceResource::class,
        \App\Filament\Resources\LeaveRequestResource::class,
        \App\Filament\Resources\OvertimeRequestResource::class,
        \App\Filament\Resources\FieldDutyRequestResource::class,
        \App\Filament\Resources\AttendanceCorrectionRequestResource::class,
        \App\Filament\Resources\UserResource::class,
        \App\Filament\Resources\TechnicianResource::class,
    ];

    /**
     * Halaman kustom (bukan Resource) yang ikut gaya bersih: kartu statistik dan tabel widget dashboard memakai kelas Filament yang sama.
     *
     * @var list<class-string<\Filament\Pages\Page>>
     */
    public const PAGES = [
        \App\Filament\Pages\InventoryDashboard::class,
    ];

    public static function appliesTo(mixed $page): bool
    {
        if (! is_object($page)) {
            return false;
        }

        if (in_array($page::class, self::PAGES, true)) {
            return true;
        }

        if (method_exists($page, 'getResource') && in_array($page::getResource(), self::RESOURCES, true)) {
            return true;
        }

        return self::isReportPage($page);
    }

    /**
     * Halaman kluster Laporan (Penjualan): laporan berupa halaman kustom, dan Detail Penjualan berupa resource. Ikut gaya bersih tanpa didaftar satu per satu,
     * sehingga laporan baru di kluster ini otomatis ikut, dan mendapat gaya tabel laporan (resources/views/filament/partials/report-styles).
     */
    public static function isReportPage(mixed $page): bool
    {
        if (! is_object($page)) {
            return false;
        }

        $cluster = method_exists($page, 'getResource')
            ? $page::getResource()::getCluster()
            : (method_exists($page, 'getCluster') ? $page::getCluster() : null);

        return $cluster === \App\Filament\Clusters\PenjualanCluster::class;
    }

    /**
     * Bawaan tabel untuk halaman bergaya bersih: filter dilipat di atas daftar dalam tiga kolom (menggantikan panel filter samping/popup),
     * kecuali tabel itu menentukan sendiri. Dipanggil sekali dari AppServiceProvider::boot().
     */
    public static function registerTableDefaults(): void
    {
        \Filament\Tables\Table::configureUsing(function (\Filament\Tables\Table $table): void {
            if (self::appliesTo($table->getLivewire())) {
                $table->filtersLayout(\Filament\Tables\Enums\FiltersLayout::AboveContentCollapsible)->filtersFormColumns(3);
            }
        });
    }
}
