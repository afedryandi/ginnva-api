<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    // TelescopeServiceProvider TIDAK didaftarkan di sini secara langsung —
    // laravel/telescope ada di composer "require-dev", jadi tidak ter-install
    // di production (composer install --no-dev). Mendaftarkannya di sini akan
    // fatal error "Class ... not found" saat boot. Didaftarkan secara
    // kondisional di AppServiceProvider::register() sebagai gantinya.
];
