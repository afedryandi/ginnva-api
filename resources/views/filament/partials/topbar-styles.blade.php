{{--
    Gaya navbar atas (topbar). Hanya mengubah tampilan, bukan isi atau urutan menu. Disisipkan global lewat render hook HEAD_END di
    AdminPanelProvider. Memakai kelas Filament (fi-topbar, fi-topbar-item-*, fi-icon-btn, fi-dropdown-*) dan variabel warna Filament
    (--gray-*, --primary-*) agar ikut warna primer dan mode gelap (.dark).

    Ringkasnya: bilah 56px dengan garis bawah tipis (tanpa bayangan); menu berupa pil lembut (aktif: latar primer pucat, teks primer);
    ikon outline 18px seragam; tombol ikon di kanan (notifikasi, persetujuan, tema) berukuran sama; panel dropdown bersudut 0.875rem
    dengan garis halus dan bayangan tipis.
--}}
<style>
    .fi-topbar {
        --tb-surface: #fff;
        --tb-line: rgba(var(--gray-200), .7);
        --tb-muted: rgb(var(--gray-500));
        --tb-text: rgb(var(--gray-700));
        --tb-hover: rgba(var(--gray-100), .9);
        --tb-active-bg: rgba(var(--primary-500), .08);
        --tb-active-text: rgb(var(--primary-700));
        --tb-active-icon: rgb(var(--primary-600));
    }
    .dark .fi-topbar {
        --tb-surface: rgb(var(--gray-900));
        --tb-line: rgba(255, 255, 255, .08);
        --tb-muted: rgb(var(--gray-400));
        --tb-text: rgb(var(--gray-200));
        --tb-hover: rgba(255, 255, 255, .06);
        --tb-active-bg: rgba(var(--primary-400), .12);
        --tb-active-text: rgb(var(--primary-300));
        --tb-active-icon: rgb(var(--primary-400));
    }

    /* Bilah: 56px, latar bersih, garis bawah tipis menggantikan bayangan dan ring. */
    .fi-topbar > nav {
        height: 3.5rem; gap: .5rem;
        background: var(--tb-surface); border-bottom: 1px solid var(--tb-line);
        box-shadow: none; --tw-ring-shadow: 0 0 #0000; --tw-ring-color: transparent;
    }
    .fi-topbar .fi-logo { height: 2rem !important; }

    /* Menu atas: pil lembut, jarak rapat, ikon outline 18px, label 13.5px. */
    .fi-topbar-with-navigation nav > ul { gap: .125rem; margin-inline-end: .5rem; }
    .fi-topbar-item-button {
        gap: .5rem; padding: .375rem .75rem; border-radius: .625rem;
        transition: background-color .12s ease, color .12s ease;
    }
    .fi-topbar-item-button:hover,
    .fi-topbar-item-button:focus-visible { background: var(--tb-hover); }
    .fi-topbar-item-icon { width: 1.125rem; height: 1.125rem; color: var(--tb-muted); }
    .fi-topbar-item-label { font-size: .8438rem; line-height: 1.25rem; font-weight: 500; color: var(--tb-text); }
    .fi-topbar-group-toggle-icon { width: 1rem; height: 1rem; color: var(--tb-muted); }

    /* Menu aktif: latar primer pucat, teks dan ikon primer, label sedikit lebih tebal. */
    .fi-topbar-item.fi-active > .fi-topbar-item-button { background: var(--tb-active-bg); }
    .fi-topbar-item.fi-active .fi-topbar-item-label { color: var(--tb-active-text); font-weight: 600; }
    .fi-topbar-item.fi-active .fi-topbar-item-icon,
    .fi-topbar-item.fi-active .fi-topbar-group-toggle-icon { color: var(--tb-active-icon); }

    /* Sisi kanan (pencarian, notifikasi, persetujuan, avatar): jarak rapat, tombol ikon 36px seragam. */
    .fi-topbar nav > div:last-of-type { gap: .25rem; }
    .fi-topbar .fi-icon-btn { width: 2.25rem; height: 2.25rem; border-radius: .625rem; color: var(--tb-muted); }
    .fi-topbar .fi-icon-btn:hover { background: var(--tb-hover); }
    .fi-topbar .fi-icon-btn-icon { width: 1.25rem; height: 1.25rem; }
    .fi-topbar .fi-user-menu .fi-avatar { width: 2rem; height: 2rem; }

    /* Panel dropdown (menu grup, notifikasi, akun): garis halus, sudut 0.875rem, bayangan tipis, item lebih lega. */
    .fi-dropdown-panel {
        border: 1px solid var(--tb-line, rgba(var(--gray-200), .7)); border-radius: .875rem;
        box-shadow: 0 10px 28px -10px rgba(var(--gray-950), .18); --tw-ring-shadow: 0 0 #0000; --tw-ring-color: transparent;
        padding: .25rem;
    }
    .dark .fi-dropdown-panel { border-color: rgba(255, 255, 255, .08); box-shadow: 0 12px 32px -10px rgba(0, 0, 0, .6); }
    .fi-dropdown-list-item { border-radius: .5rem; }
    .fi-dropdown-list-item-label { font-size: .8125rem; font-weight: 500; }

    /* Menu samping menempel lebih dekat karena bilah atas kini 56px (sebelumnya 64px). */
    @media (min-width: 768px) {
        .fi-page-sub-navigation-sidebar { top: 4.5rem; max-height: calc(100vh - 6rem); }
    }
</style>
