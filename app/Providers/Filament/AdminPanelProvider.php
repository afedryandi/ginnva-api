<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->profile()
            ->brandName('Ginnva Admin')
            ->colors([
                'primary' => Color::Red,
            ])
            // Dirombak jadi per-SISTEM/divisi bisnis (bukan per-fitur lepas
            // seperti sebelumnya: Penjualan/Konten/Partnership Referral yang
            // isinya tumpang tindih) — Booking dulu (siklus hidup lead sampai
            // instalasi selesai), baru Marketing/Konten, Karyawan (dulu
            // bernama 'Operasional' — DIGANTI karena begitu Absensi/Izin/
            // Penggajian/Surat Peringatan/Perpanjang Kontrak dibangun,
            // ISINYA 100% soal karyawan, jadi nama lama sudah tidak akurat),
            // Inventaris, lalu Master Data & Sistem (data acuan lintas-modul
            // & hal teknis, sengaja tetap terpisah — tidak cocok dipaksa
            // masuk ke salah satu sistem bisnis). Keuangan/Financial BELUM
            // ada modul sama sekali, jadi belum didaftarkan di sini.
            ->navigationGroups([
                'Booking',
                'Marketing/Konten',
                'Karyawan',
                'Inventaris',
                'Master Data',
                'Sistem',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            // Bell icon notifikasi di panel — dipakai alert kedaluwarsa
            // bahan baku (lihat App\Console\Commands\NotifyExpiringMaterials)
            // supaya admin tidak perlu buka Dashboard Inventaris manual
            // tiap hari untuk tahu ada yang perlu ditinjau.
            ->databaseNotifications()
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
