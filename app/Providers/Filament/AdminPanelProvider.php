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
            // sidebar. 'Penjualan' SEMPAT jadi tab top-nav sendiri
            // (PenjualanCluster, 2026-09-08), lalu 2026-09-10 turun jadi
            // navigationGroup biasa yang isinya SalesDashboard + cluster
            // 'Laporan' (lihat PenjualanCluster.php) — supaya hirarki
            // sama persis Majoo (Penjualan > Laporan > kategori > item).
            //
            // Struktur grup laporan di cluster Penjualan SEMPAT digabung
            // jadi 1 grup 'Laporan' (2026-09-08), TAPI diubah 2026-09-09
            // (permintaan susulan) jadi grup TERPISAH per kategori --
            // Filament v3 TIDAK mendukung dropdown bersarang (grup di
            // dalam grup), jadi tiap kategori laporan Majoo (Laporan
            // Penjualan, Jasa, Promo & Loyalti, Pelanggan, Karyawan,
            // Persediaan, Settlement) masing-masing jadi grup SEJAJAR
            // sendiri-sendiri di sidebar cluster Penjualan, bukan
            // ditumpuk di bawah 1 heading 'Laporan' lagi. Grup kategori
            // ini SEKARANG hidup di dalam sub-nav cluster 'Laporan'
            // (bekas PenjualanCluster). SalesDashboard TIDAK ikut cluster
            // ini — dia naik jadi item langsung di bawah grup 'Penjualan'
            // (2026-09-10), sibling dari cluster 'Laporan'.
            // 'Analisa Laporan' TIDAK di dalam cluster 'Laporan' — dia
            // cluster SENDIRI (AnalisaLaporanCluster, $navigationGroup
            // 'Penjualan'), sejajar dengan 'Laporan': struktur jadi
            // Penjualan > Analisa Laporan > Waktu Teramai Penjualan
            // (diminta 2026-09-10). Makanya tidak ada di array ini lagi.
            // Semua grup diset ->collapsed() (diminta 2026-09-09) supaya
            // default TERTUTUP saat halaman pertama dimuat, bukan
            // terbuka semua. String biasa jadi NavigationGroup::make()
            // eksplisit supaya collapsed() bisa dipasang -- Filament
            // simpan status buka/tutup per grup di localStorage browser
            // (persist per user/browser, bukan cuma sekali muat).
            //
            // CATATAN: "buka 1 otomatis tutup yang lain" (accordion
            // beneran, cuma 1 grup boleh terbuka) BUKAN perilaku bawaan
            // Filament -- defaultnya semua grup independen, boleh
            // dibuka bersamaan. Itu butuh override Alpine/JS internal,
            // sengaja TIDAK dikerjakan di sini (lihat diskusi 2026-09-09
            // soal risiko menebak struktur DOM tanpa akses visual).
            // Percobaan ->sort() eksplisit (2026-09-09) DIBATALKAN --
            // method itu TIDAK ADA di NavigationGroup versi Filament ini
            // (v3.3.54), bikin panel error fatal "Method ... sort does
            // not exist" begitu di-deploy. Balik ke urutan array polos
            // seperti semula. Urutan Laporan Produk vs Laporan Penjualan
            // yang masih terbalik di sidebar Cluster BELUM terselesaikan
            // -- perlu cara lain yang benar-benar terverifikasi dulu
            // sebelum dicoba lagi (tidak menebak nama method lagi).
            // 2026-09-10: 'Penjualan' ditambah sebagai navigationGroup
            // TOP-NAV (dulu PenjualanCluster, sekarang turun jadi grup).
            // Isinya: SalesDashboard (page) + cluster 'Laporan' (bekas
            // PenjualanCluster). Ini yang bikin struktur jadi sama Majoo:
            // Penjualan (grup) > Laporan (cluster) > Laporan Penjualan
            // (sub-nav grup cluster) > Ringkasan Penjualan (item).
            // Sisa entri di array ini adalah grup INTERNAL cluster
            // (sub-nav 'Laporan' + sub-nav 'Lainnya') — didaftarkan di
            // sini semata supaya ->collapsed() bisa dipasang.
            ->navigationGroups([
                \Filament\Navigation\NavigationGroup::make('Penjualan')->collapsed(),
                \Filament\Navigation\NavigationGroup::make('Laporan Penjualan')->collapsed(),
                \Filament\Navigation\NavigationGroup::make('Laporan Produk')->collapsed(),
                \Filament\Navigation\NavigationGroup::make('Laporan Jasa')->collapsed(),
                \Filament\Navigation\NavigationGroup::make('Laporan Promo & Loyalti')->collapsed(),
                \Filament\Navigation\NavigationGroup::make('Laporan Pelanggan')->collapsed(),
                \Filament\Navigation\NavigationGroup::make('Laporan Karyawan')->collapsed(),
                \Filament\Navigation\NavigationGroup::make('Laporan Persediaan')->collapsed(),
                // Grup di dalam cluster "Inventori" (Penjualan > Inventori).
                \Filament\Navigation\NavigationGroup::make('Riwayat')->collapsed(),
                \Filament\Navigation\NavigationGroup::make('Kelola Stok')->collapsed(),
                \Filament\Navigation\NavigationGroup::make('Master Data')->collapsed(),
                \Filament\Navigation\NavigationGroup::make('Sistem')->collapsed(),
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
            // Spacing antar-grup sidebar (diminta 2026-09-09) — jarak
            // vertikal bawaan Filament v3 antar heading grup ("Laporan
            // Penjualan", "Laporan Jasa", dst) dirasa terlalu jauh.
            //
            // 2 percobaan pertama SALAH, dikoreksi bertahap via Inspect
            // Element user (2026-09-09):
            // - Percobaan 1: target `.fi-sidebar-nav-groups` (nama class
            //   tebakan) -- TIDAK ngefek, class itu tidak ada.
            // - Percobaan 2 (belum sempat di-commit): mau pakai
            //   `margin-top` di selector sibling `.fi-sidebar-group +
            //   .fi-sidebar-group` -- SALAH ARAH, karena parent asli
            //   (`<ul class="fi-page-sub-navigation-sidebar flex
            //   flex-col gap-y-7">`, dikonfirmasi Inspect Element) pakai
            //   `gap` (flexbox row-gap), bukan margin -- kalau tetap
            //   dipasang, margin itu NUMPUK di atas gap yang sudah ada
            //   (nambah jarak, bukan ngurangin).
            // - FIX BENAR: override `row-gap` LANGSUNG di
            //   `.fi-page-sub-navigation-sidebar` (nama class asli, sudah
            //   dikonfirmasi ada di DOM, bukan tebakan lagi).
            ->renderHook(
                \Filament\View\PanelsRenderHook::HEAD_END,
                fn () => '<style>.fi-page-sub-navigation-sidebar { row-gap: 0.5rem !important; }</style>',
            )
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