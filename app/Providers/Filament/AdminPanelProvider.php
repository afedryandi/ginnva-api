<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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
            // Logo diganti dari teks "Ginnva Admin" ke logo hexagon "G"
            // asli (diminta user 2026-09-15). Sumber:
            // C:\Users\Antony\Documents\Ginnva\Company Profile\ginnva-favicon-logo.png
            // -- latar aslinya PUTIH SOLID (bukan transparan), dibuat
            // transparan dulu pakai ffmpeg colorkey (hapus semua piksel
            // putih, termasuk celah negative-space "G" di tengah supaya
            // celahnya ikut tembus ke warna topbar merah) sebelum
            // disalin ke public/images/ginnva-logo.png. brandName() tetap
            // dipertahankan sebagai alt text aksesibilitas (Filament
            // pakai ini walau logo gambar sudah ada).
            ->brandName('Ginnva Admin')
            ->brandLogo(asset('images/ginnva-logo-merah.png'))
            ->darkModeBrandLogo(asset('images/ginnva-logo.png'))
            ->brandLogoHeight('2.5rem')
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
            // 2026-09-14: "Lainnya" DIUBAH dari 1 Cluster datar
            // (LainnyaCluster, isi Master Data + Sistem dibedakan cuma
            // lewat sidebar sub-heading) jadi navigationGroup TOP-NAV
            // bertingkat, PERSIS pola "Penjualan" (lihat catatan
            // MasterDataCluster.php) — "Lainnya" sekarang menaungi 3
            // Cluster sejajar: MasterDataCluster, NotifikasiCluster,
            // SistemCluster. Klik "Lainnya" di top-nav menampilkan
            // dropdown 3 kategori itu, sama seperti klik "Penjualan"
            // menampilkan dropdown Laporan/Analisa Laporan/Produk/dst.
            // 'Penjualan' SEMPAT jadi tab top-nav sendiri
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
                // 2026-09-15: SEMPAT dicoba tambah ->icon() eksplisit ke
                // grup 'Penjualan' & 'Lainnya' (diminta user) -- LANGSUNG
                // error fatal production ("Navigation group [Penjualan]
                // has an icon but one or more of its items also have
                // icons"). Filament TIDAK IZINKAN grup DAN item di
                // dalamnya (Cluster Booking/Inventori/MasterData/dst,
                // semua sudah punya $navigationIcon sendiri) sama-sama
                // punya ikon. DIBATALKAN -- kalau mau grup ini berikon,
                // SEMUA Cluster anggotanya harus dilepas ikonnya dulu
                // (trade-off yang belum tentu diinginkan, tanya user dulu
                // sebelum coba lagi).
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
                // 'Master Data'/'Sistem' TIDAK lagi didaftarkan di sini
                // sejak 2026-09-14 — dulu label sub-heading SIDEBAR di
                // dalam LainnyaCluster yang datar, sekarang sudah jadi
                // Cluster TOP-NAV sendiri (MasterDataCluster/SistemCluster,
                // ->collapsed() tidak relevan untuk level itu).
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            // Pages\Dashboard::class (bawaan Filament) TIDAK LAGI didaftarkan
            // eksplisit di sini sejak 2026-09-25 (audit "Dashboard Utama",
            // gap "standar enterprise dashboard" -- filter cabang terpusat)
            // -- diganti App\Filament\Pages\DashboardHome (extends
            // Filament\Pages\Dashboard, routePath '/' ikut ter-inherit),
            // yang otomatis ketemu lewat discoverPages() di bawah karena
            // memang hidup di folder ini sekarang. Lihat catatan lengkap
            // di DashboardHome.php & dashboard-home.blade.php.
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\\Filament\\Clusters')
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
            // Tur pengenalan (sekali per staf, atau lewat ?tur=1): lihat App\Support\AdminTour.
            ->renderHook(
                \Filament\View\PanelsRenderHook::BODY_END,
                fn (): string => \App\Support\AdminTour::shouldShow(auth()->user(), request()->boolean('tur'))
                    ? view('filament.admin-tour')->render()
                    : ''
            )
            // Status buku (bulan ini dan bulan lalu: Terbuka/Ditutup) di atas semua halaman Keuangan. Livewire::originalPath() (bukan request()->path())
            // supaya bilah tidak hilang saat halaman dirender ulang lewat permintaan Livewire.
            ->renderHook(
                \Filament\View\PanelsRenderHook::PAGE_START,
                fn (): string => auth()->check() && \App\Support\PeriodStatus::appliesToPath('admin', \App\Filament\Clusters\KeuanganCluster::getSlug(), \Livewire\Livewire::originalPath())
                    ? view('filament.period-status')->render()
                    : ''
            )
            // Pintasan "Panduan" di menu avatar (pojok kanan atas): tempat yang biasa dicari staf untuk bantuan.
            ->userMenuItems([
                \Filament\Navigation\MenuItem::make()
                    ->label('Panduan')
                    ->icon('heroicon-o-question-mark-circle')
                    ->url(fn (): string => \App\Filament\Pages\Panduan::getUrl()),
            ])
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
            // Topbar & sidebar memakai tampilan bawaan Filament (putih/netral dengan aksen merah) sejak 2026-10-12, permintaan
            // "lebih minimalis" (panduan UI: palet terbatas). Override merah solid #ED1651 beserta perbaikan kontrasnya
            // dihapus; ada di riwayat Git (sebelum commit perubahan ini) kalau ingin dikembalikan. Logo putih diganti versi
            // merah di mode terang (lihat brandLogo di atas).
            ->renderHook(
                \Filament\View\PanelsRenderHook::HEAD_END,
                // Menu samping kluster (mis. Karyawan, Keuangan) punya scroll sendiri (2026-10-27): menempel di bawah topbar saat halaman digulir
                // dan bisa digulir sendiri bila daftarnya lebih panjang dari layar. Hanya di layar md ke atas (di ponsel menunya berupa dropdown).
                fn () => '<style>'
                    . '.fi-page-sub-navigation-sidebar { row-gap: 0.5rem !important; }'
                    . '@media (min-width: 768px) {'
                    . '.fi-page-sub-navigation-sidebar { position: sticky; top: 5rem; align-self: flex-start; max-height: calc(100vh - 6.5rem); overflow-y: auto; overscroll-behavior: contain; padding-right: 0.25rem; scrollbar-width: thin; }'
                    . '}'
                    . '</style>',
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