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
            // Sempat diganti ke Color::hex('#ED1651') (hex asli brand) lalu
            // dicoba warnai background topbar merah solid via CSS override
            // — percobaannya gagal 2x dan sempat bikin topbar tidak
            // kepakai (teks tidak kelihat). Dibatalkan semuanya 2026-09-08,
            // balik ke Color::Red bawaan Filament seperti semula.
            ->colors([
                'primary' => Color::Red,
            ])
            // Navigasi horizontal di atas — diminta 2026-09-08, referensi
            // tata letak Majoo, TAPI bukan dropdown: tiap grup (dulu
            // navigationGroup per resource, sekarang Cluster, lihat
            // app/Filament/Clusters/) jadi 1 item klik di top bar. Klik
            // masuk ke cluster-nya menampilkan sub-navigasi di sidebar kiri
            // berisi resource/page di dalamnya (Quotation, Booking
            // Instalasi, dst) — persis pola "klik Karyawan di atas, submenu
            // muncul di kiri" yang diminta, bukan dropdown gantung.
            // navigationGroups() TIDAK dipakai lagi — Cluster menggantikan
            // perannya (grouping sekarang berbasis kelas $cluster per
            // resource/page, bukan string navigationGroup lagi).
            ->topNavigation()
            // Master Data & Sistem digabung jadi 1 tab top-nav "Lainnya"
            // (LainnyaCluster, supaya top-nav tidak terlalu lebar), TAPI
            // di sidebar cluster itu tetap dikategorikan terpisah lewat
            // $navigationGroup di masing-masing resource (VehicleResource
            // dkk = 'Master Data', ActivityResource dkk = 'Sistem') —
            // array ini yang menentukan urutan & label 2 kategori itu di
            // sidebar. 'Penjualan' SEMPAT dipakai sebagai sub-kategori di
            // dalam cluster Booking, tapi diminta susulan 2026-09-08 jadi
            // TAB TOP-NAV SENDIRI (PenjualanCluster).
            //
            // Struktur grup laporan di cluster Penjualan SEMPAT digabung
            // jadi 1 grup 'Laporan' (2026-09-08), TAPI diubah 2026-09-09
            // (permintaan susulan) jadi grup TERPISAH per kategori --
            // Filament v3 TIDAK mendukung dropdown bersarang (grup di
            // dalam grup), jadi tiap kategori laporan Majoo (Laporan
            // Penjualan, Jasa, Promo & Loyalti, Pelanggan, Karyawan,
            // Persediaan, Settlement) masing-masing jadi grup SEJAJAR
            // sendiri-sendiri di sidebar cluster Penjualan, bukan
            // ditumpuk di bawah 1 heading 'Laporan' lagi. Dashboard
            // Penjualan (SalesDashboard) tetap TIDAK ikut grup manapun,
            // berdiri sendiri di atas semua grup ini.
            ->navigationGroups([
                'Laporan Penjualan',
                'Laporan Jasa',
                'Laporan Promo & Loyalti',
                'Laporan Pelanggan',
                'Laporan Karyawan',
                'Laporan Persediaan',
                'Laporan Settlement',
                'Master Data',
                'Sistem',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\\Filament\\Clusters')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            // Percobaan background topbar merah solid via CSS override
            // manual (render hook) DIBATALKAN 2026-09-08 — 2x tebakan
            // selector/class Filament meleset (percobaan 1: teks jadi
            // tidak kelihat sama sekali di atas background merah;
            // percobaan 2: perbaikannya malah bikin background ikut balik
            // putih/pudar, teks putih di atas putih jadi tidak kelihat
            // sama sekali). Dikembalikan ke topbar default Filament
            // (putih, ->colors() cuma mewarnai elemen aktif) sampai ada
            // info pasti nama class/struktur DOM topbar yang sebenarnya
            // (lewat Inspect Element), supaya tidak terus menebak buta
            // dan mengganggu pemakaian admin panel.
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