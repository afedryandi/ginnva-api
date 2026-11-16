{{--
    Gaya menu samping kiri (sub-navigasi klaster: Booking, Karyawan, Keuangan, dst.). Hanya mengubah tampilan, bukan isi atau urutan menu.
    Disisipkan global lewat render hook HEAD_END di AdminPanelProvider. Memakai nama kelas Filament (fi-page-sub-navigation-sidebar, fi-sidebar-*)
    dan variabel warna Filament (--gray-*, --primary-*) agar ikut warna primer perusahaan dan mode gelap (.dark).

    Ringkasnya: kartu tipis (sudut 1rem, garis halus, tanpa bayangan); judul grup kecil huruf kapital redup; item 36px dengan ikon outline 18px;
    item aktif berlatar primer lembut, teks primer, dan penanda batang tipis di kiri; hover abu-abu lembut.
--}}
<style>
    .fi-page-sub-navigation-sidebar-ctn {
        --sb-surface: #fff;
        --sb-line: rgba(var(--gray-200), .7);
        --sb-muted: rgb(var(--gray-500));
        --sb-text: rgb(var(--gray-700));
        --sb-hover: rgba(var(--gray-100), .9);
        --sb-active-bg: rgba(var(--primary-500), .08);
        --sb-active-text: rgb(var(--primary-700));
        --sb-active-icon: rgb(var(--primary-600));
        width: 15.5rem;
    }
    .dark .fi-page-sub-navigation-sidebar-ctn {
        --sb-surface: rgb(var(--gray-900));
        --sb-line: rgba(255, 255, 255, .08);
        --sb-muted: rgb(var(--gray-400));
        --sb-text: rgb(var(--gray-200));
        --sb-hover: rgba(255, 255, 255, .05);
        --sb-active-bg: rgba(var(--primary-400), .12);
        --sb-active-text: rgb(var(--primary-300));
        --sb-active-icon: rgb(var(--primary-400));
    }

    /* Wadah: kartu tipis. Jarak antar grup lega, jarak antar item rapat. */
    .fi-page-sub-navigation-sidebar {
        row-gap: 1.125rem !important;
        padding: .75rem .5rem !important;
        background: var(--sb-surface);
        border: 1px solid var(--sb-line);
        border-radius: 1rem;
        scrollbar-color: rgba(var(--gray-400), .5) transparent;
    }

    /* Judul grup: kecil, huruf kapital, redup; hanya penanda, bukan pusat perhatian. */
    .fi-page-sub-navigation-sidebar .fi-sidebar-group { gap: .125rem; }
    .fi-page-sub-navigation-sidebar .fi-sidebar-group-button { padding: .25rem .75rem; gap: .5rem; }
    .fi-page-sub-navigation-sidebar .fi-sidebar-group-label {
        font-size: .6875rem; line-height: 1rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--sb-muted);
    }
    .fi-page-sub-navigation-sidebar .fi-sidebar-group-icon { width: 1rem; height: 1rem; color: var(--sb-muted); }
    .fi-page-sub-navigation-sidebar .fi-sidebar-group-collapse-button { width: 1.5rem; height: 1.5rem; }
    .fi-page-sub-navigation-sidebar .fi-sidebar-group-collapse-button svg { width: 1rem; height: 1rem; }

    /* Item: tinggi seragam, ikon outline 18px, label 13px. */
    .fi-page-sub-navigation-sidebar .fi-sidebar-item-button {
        position: relative; gap: .625rem; padding: .4375rem .75rem; border-radius: .625rem;
        transition: background-color .12s ease, color .12s ease;
    }
    .fi-page-sub-navigation-sidebar .fi-sidebar-item-icon { width: 1.125rem; height: 1.125rem; color: var(--sb-muted); }
    .fi-page-sub-navigation-sidebar .fi-sidebar-item-label { font-size: .8125rem; line-height: 1.25rem; font-weight: 500; color: var(--sb-text); }
    .fi-page-sub-navigation-sidebar .fi-sidebar-item-button:hover { background: var(--sb-hover); }

    /* Item aktif: latar primer lembut, teks dan ikon primer, penanda batang tipis di tepi kiri. */
    .fi-page-sub-navigation-sidebar .fi-sidebar-item.fi-active > .fi-sidebar-item-button { background: var(--sb-active-bg); }
    .fi-page-sub-navigation-sidebar .fi-sidebar-item.fi-active > .fi-sidebar-item-button .fi-sidebar-item-label { color: var(--sb-active-text); font-weight: 600; }
    .fi-page-sub-navigation-sidebar .fi-sidebar-item.fi-active > .fi-sidebar-item-button .fi-sidebar-item-icon { color: var(--sb-active-icon); }
    .fi-page-sub-navigation-sidebar .fi-sidebar-item.fi-active > .fi-sidebar-item-button::before {
        content: ''; position: absolute; left: 0; top: .5rem; bottom: .5rem; width: 3px; border-radius: 9999px; background: var(--sb-active-icon);
    }

    /* Lencana jumlah (mis. lead baru): kecil, pastel, sejajar kanan. */
    .fi-page-sub-navigation-sidebar .fi-badge { font-size: .6875rem; min-width: 1.25rem; justify-content: center; padding: .0625rem .375rem; border-radius: .5rem; font-weight: 600; }
</style>
