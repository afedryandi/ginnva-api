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
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
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
            // SEBELUMNYA Color::Red — itu merah generik Tailwind (#ef4444),
            // BUKAN merah brand Ginnva yang sebenarnya. Diganti ke hex asli
            // brand (#ED1651, persis sama dengan colors.accent di mobile
            // app — lihat constants/theme.ts) supaya warna admin panel
            // konsisten dengan identitas brand di mobile app. Diminta
            // 2026-09-08.
            ->colors([
                'primary' => Color::hex('#ED1651'),
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
            // sidebar. Diminta 2026-09-08.
            ->navigationGroups([
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
            // ->colors(['primary' => ...]) di atas CUMA mewarnai elemen
            // aktif (teks/ikon tab yang lagi dibuka, tombol, badge) — itu
            // memang cara kerja bawaan Filament, background topbar tetap
            // putih. Diminta 2026-09-08: background strip topbar (bukan
            // cuma teksnya) jadi merah brand solid, teks/ikon putih supaya
            // kontras. Filament tidak punya method resmi untuk ini (bukan
            // bagian dari ->colors()), jadi lewat CSS override manual via
            // render hook — TIDAK butuh build step Vite/npm, cukup inline
            // <style> di <head>. Kelas .fi-topbar & .fi-topbar-item ADALAH
            // nama kelas asli Filament v3 (dikonfirmasi dari dokumentasi/
            // komunitas Filament, BUKAN tebakan) — kalau versi Filament
            // berubah struktur kelasnya di masa depan, override ini perlu
            // disesuaikan ulang.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => Blade::render(<<<'BLADE'
                    <style>
                        .fi-topbar {
                            background-color: #ED1651;
                            border-bottom: none;
                        }
                        .fi-topbar-item-label,
                        .fi-topbar-item-icon,
                        .fi-topbar nav a,
                        .fi-topbar .fi-icon-btn svg,
                        .fi-topbar .fi-avatar {
                            color: #ffffff !important;
                        }
                        .fi-topbar-item.fi-active .fi-topbar-item-label,
                        .fi-topbar-item.fi-active .fi-topbar-item-icon {
                            color: #ffffff !important;
                            opacity: 1;
                        }
                        .fi-topbar-item:not(.fi-active) .fi-topbar-item-label,
                        .fi-topbar-item:not(.fi-active) .fi-topbar-item-icon {
                            color: rgba(255, 255, 255, 0.75) !important;
                        }
                    </style>
                    BLADE
                )
            )
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