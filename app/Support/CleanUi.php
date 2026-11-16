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
    ];

    public static function appliesTo(mixed $page): bool
    {
        return is_object($page)
            && method_exists($page, 'getResource')
            && in_array($page::getResource(), self::RESOURCES, true);
    }
}
