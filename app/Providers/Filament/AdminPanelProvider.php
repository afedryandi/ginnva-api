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
            ->brandLogo(asset('images/ginnva-logo.png'))
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
            // Topbar & sidebar warna solid merah brand (diminta user
            // 2026-09-15, percobaan ke-3 -- 2 percobaan sebelumnya
            // 2026-09-08 GAGAL karena menebak nama class yang salah,
            // lihat catatan di atas). Kali ini pakai `.fi-topbar` &
            // `.fi-sidebar` -- class TOP-LEVEL resmi Filament v3 (bukan
            // class internal bersarang yang sebelumnya ditebak salah),
            // dan background+teks/ikon di-override BERSAMAAN (dugaan
            // penyebab "teks tidak kelihat" sebelumnya: cuma background
            // yang diganti, teks default gelap jadi tidak kebaca di atas
            // merah). BELUM diverifikasi visual langsung (tidak ada
            // kredensial admin untuk Inspect Element live) -- WAJIB cek
            // screenshot user setelah deploy, siap di-revert cepat kalau
            // meleset lagi (pola sama seperti perbaikan row-gap di bawah).
            ->renderHook(
                \Filament\View\PanelsRenderHook::HEAD_END,
                fn () => '<style>
                    .fi-topbar,
                    .fi-topbar nav {
                        background-color: #ED1651 !important;
                    }
                    .fi-topbar a,
                    .fi-topbar button,
                    .fi-topbar svg,
                    .fi-topbar span {
                        color: #ffffff !important;
                    }
                    .fi-sidebar,
                    .fi-sidebar-nav {
                        background-color: #ED1651 !important;
                    }
                    .fi-sidebar-nav a,
                    .fi-sidebar-nav button,
                    .fi-sidebar-nav svg,
                    .fi-sidebar-nav span,
                    .fi-sidebar-group-label {
                        color: #ffffff !important;
                    }
                    /* Ronde 8 (2026-09-22, user lapor label grup sub-nav
                       Cluster nyaris tak kelihat di LIGHT mode saja --
                       dark mode kebetulan masih kebaca karena background
                       gelap bawaan Filament). Root cause: sub-navigasi di
                       dalam Cluster (mis. "Laporan Penjualan" di
                       PenjualanCluster) dirender Filament dengan struktur
                       & class BEDA dari sidebar utama --
                       ".fi-page-sub-navigation-sidebar-ctn" /
                       ".fi-page-sub-navigation-sidebar" (dikonfirmasi
                       Inspect Element user), BUKAN ".fi-sidebar-nav".
                       Rule teks putih (".fi-sidebar-group-label") di atas
                       tetap match di struktur ini juga (Filament pakai
                       ulang nama class label yang sama) -- teks putih di
                       atas background terang bawaan = tidak kebaca.
                       PERCOBAAN PERTAMA (dihapus): ikut mewarnai
                       background sub-nav ini jadi merah brand juga --
                       user tolak, dianggap jelek/berlebihan untuk
                       sub-navigasi. FIX YANG DIPAKAI: batalkan saja paksaan
                       warna putih KHUSUS di struktur sub-nav Cluster ini
                       (selector lebih spesifik menang tanpa perlu urutan),
                       biarkan background TETAP bawaan Filament (terang)
                       dan teks balik pakai class asli elemen ini sendiri
                       (text-gray-500 dark:text-gray-400 -- sudah otomatis
                       benar di kedua tema, seperti item sub-nav lain yang
                       memang tidak pernah kena rule paksa ini). */
                    .fi-page-sub-navigation-sidebar-ctn .fi-sidebar-group-label,
                    .fi-page-sub-navigation-sidebar .fi-sidebar-group-label {
                        color: #6b7280 !important;
                    }
                    :root.dark .fi-page-sub-navigation-sidebar-ctn .fi-sidebar-group-label,
                    :root.dark .fi-page-sub-navigation-sidebar .fi-sidebar-group-label {
                        color: #9ca3af !important;
                    }
                    .fi-sidebar-item-active {
                        background-color: rgba(255, 255, 255, 0.15) !important;
                    }
                    /* RIWAYAT 2 percobaan gagal sebelumnya (`.fi-active`,
                       lalu `a[aria-current="page"]`) -- DIHAPUS, keduanya
                       terbukti salah/tidak match dari screenshot user.
                       FIX FINAL (ronde 4) -- user kirim HTML asli hasil
                       Inspect Element 2026-09-15, class SEBENARNYA:
                       <li class="fi-topbar-item fi-active
                       fi-topbar-item-active"><a class="fi-topbar-item-button
                       ... bg-gray-50 dark:bg-white/5"><svg
                       class="fi-topbar-item-icon ... text-primary-600
                       dark:text-primary-400">...<span
                       class="fi-topbar-item-label ... text-primary-600
                       dark:text-primary-400">. Warna asli Filament untuk
                       tab aktif SUDAH benar (text-primary-600 = merah tua,
                       kontras cukup di atas bg-gray-50) -- masalahnya
                       MURNI rule pertama saya (".fi-topbar span/svg
                       {color:white!important}") menimpanya paksa jadi
                       putih (putih di atas bg-gray-50 yang nyaris putih
                       = tidak kebaca). Fix: kembalikan warna gelap
                       eksplisit di class asli ini, bukan lagi menebak. */
                    /* Ronde 5: bukan cuma tab AKTIF -- tab MANA PUN yang
                       di-hover/focus juga dapat latar terang sementara
                       (".fi-topbar-item-button" punya class Tailwind
                       "hover:bg-gray-50 focus-visible:bg-gray-50", sama
                       pola dengan tab aktif), jadi masalah yang sama
                       muncul lagi saat hover. Dicakup sekalian di sini. */
                    :root:not(.dark) .fi-topbar-item-active .fi-topbar-item-icon,
                    :root:not(.dark) .fi-topbar-item-active .fi-topbar-item-label,
                    :root:not(.dark) .fi-topbar-item-button:hover .fi-topbar-item-icon,
                    :root:not(.dark) .fi-topbar-item-button:hover .fi-topbar-item-label,
                    :root:not(.dark) .fi-topbar-item-button:focus-visible .fi-topbar-item-icon,
                    :root:not(.dark) .fi-topbar-item-button:focus-visible .fi-topbar-item-label,
                    /* Ronde 6 (Inspect Element user): tab "Penjualan"/
                       "Lainnya" (pemicu dropdown) punya ikon panah bawah
                       TERPISAH, class-nya ".fi-topbar-group-toggle-icon"
                       -- BEDA dari ikon tab biasa ".fi-topbar-item-icon",
                       jadi tidak ikut ke-cover rule di atas. */
                    :root:not(.dark) .fi-topbar-item-active .fi-topbar-group-toggle-icon,
                    :root:not(.dark) .fi-topbar-item-button:hover .fi-topbar-group-toggle-icon,
                    :root:not(.dark) .fi-topbar-item-button:focus-visible .fi-topbar-group-toggle-icon {
                        color: #111827 !important;
                    }
                    /* Ronde 7 (user lapor 2026-09-15): DARK mode -- pas
                       bersih-bersih di ronde 4 (hapus rule ".fi-active"
                       yang salah), rule LATAR highlight-nya ikut kehapus
                       semuanya, padahal itu satu-satunya yang bikin tab
                       aktif KELIHATAN beda dari tab lain. Sisa bawaan
                       Filament di dark mode cuma "dark:bg-white/5" (tint
                       putih 5%, nyaris tidak kelihat di atas merah).
                       Dikembalikan lagi tapi kali ini KHUSUS dark mode &
                       pakai class asli yang sudah terverifikasi (bukan
                       ".fi-active" yang salah dulu). Teks TIDAK disentuh
                       di sini -- sudah putih dari rule paling atas & sudah
                       kebaca dengan baik menurut screenshot sebelumnya. */
                    .dark .fi-topbar-item-active .fi-topbar-item-button,
                    .dark .fi-topbar-item-button:hover,
                    .dark .fi-topbar-item-button:focus-visible {
                        background-color: rgba(255, 255, 255, 0.18) !important;
                    }
                    /* FIX (screenshot user 2026-09-15): dropdown notifikasi
                       & menu profil ikut ketiban aturan teks putih di atas
                       karena dia anak DOM dari .fi-topbar, padahal latar
                       panel dropdown-nya sendiri TETAP putih -- teks putih
                       di atas putih jadi tidak kebaca. Dikembalikan ke
                       warna teks gelap normal Filament KHUSUS di dalam
                       panel dropdown (.fi-dropdown-panel), spesifisitas
                       CSS-nya setara + urutan belakangan supaya menang
                       lawan aturan ".fi-topbar span" di atas. */
                    .fi-dropdown-panel,
                    .fi-dropdown-panel a,
                    .fi-dropdown-panel button,
                    .fi-dropdown-panel span,
                    .fi-dropdown-panel p,
                    .fi-dropdown-panel div,
                    .fi-dropdown-panel svg {
                        color: #111827 !important;
                    }
                    /* FIX kedua (screenshot user 2026-09-15, ronde 2):
                       svg ikon (toggle tema, dsb) di dalam dropdown
                       KELUPAAN di fix sebelumnya -- cuma a/button/span/p/div
                       yang di-cover, svg tidak, jadi masih ketiban warna
                       putih dari ".fi-topbar svg" di atas. TAPI kalau svg
                       dipaksa gelap TANPA syarat, mode GELAP (latar
                       dropdown-nya ikut gelap by default Filament) akan
                       rusak sebaliknya (ikon gelap di atas gelap). Jadi
                       khusus mode dark (".dark" class di <html>, dipasang
                       Filament sendiri) svg dikembalikan terang lagi --
                       spesifisitas ".dark .fi-dropdown-panel svg" lebih
                       tinggi dari ".fi-topbar svg" & rule di atas, jadi
                       otomatis menang di mode dark. */
                    .dark .fi-dropdown-panel,
                    .dark .fi-dropdown-panel a,
                    .dark .fi-dropdown-panel button,
                    .dark .fi-dropdown-panel span,
                    .dark .fi-dropdown-panel p,
                    .dark .fi-dropdown-panel div,
                    .dark .fi-dropdown-panel svg {
                        color: #f3f4f6 !important;
                    }
                    /* FIX (user lapor 2026-09-16): icon lonceng di HEADER
                       modal notifikasi ("Belum ada notifikasi") -- BUKAN
                       tombol pemicu di topbar (itu sudah putih dari rule
                       paling atas), ini elemen terpisah di dalam modal
                       (.fi-modal-header), dikonfirmasi lewat Inspect
                       Element user: <svg class="fi-modal-icon h-6 w-6
                       text-gray-500 dark:text-gray-400"> di dalam div
                       bulat "bg-gray-100", wire:key mengandung
                       "database-notifications.header". Warna asli
                       Filament (gray-500 di atas gray-100) kontrasnya
                       tipis di light theme. Di-scope KHUSUS ke modal
                       notifikasi (bukan `.fi-modal-icon` generik) supaya
                       tidak ikut mengubah warna icon semantik (merah/
                       kuning) di modal lain seperti konfirmasi hapus.
                       Dark mode tidak disentuh (dark:text-gray-400 sudah
                       cukup kontras di atas bg-gray-500/20 gelap). */
                    :root:not(.dark) [wire\:key*="database-notifications"] .fi-modal-icon {
                        color: #374151 !important;
                    }
                </style>',
            )
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