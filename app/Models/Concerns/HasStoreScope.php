<?php

namespace App\Models\Concerns;

use App\Models\Scopes\StoreScope;

/**
 * Pasang di model yang punya kolom store_id dan scoping-nya SELALU
 * ketat tanpa pengecualian — lihat catatan lengkap alasan &
 * batasannya di App\Models\Scopes\StoreScope.
 *
 * Lewat trait (bukan override boot() langsung di model) supaya tidak
 * bentrok dengan method booted()/boot() lain yang sudah ada di
 * model — Eloquent otomatis memanggil bootHasStoreScope() ini
 * terpisah, independen dari boot()/booted() model itu sendiri.
 */
trait HasStoreScope
{
    public static function bootHasStoreScope(): void
    {
        static::addGlobalScope(new StoreScope);
    }
}
