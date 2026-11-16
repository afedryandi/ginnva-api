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
    ];

    public static function appliesTo(mixed $page): bool
    {
        return is_object($page)
            && method_exists($page, 'getResource')
            && in_array($page::getResource(), self::RESOURCES, true);
    }
}
