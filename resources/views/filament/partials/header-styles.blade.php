{{--
    Gaya kepala halaman (breadcrumb, judul, subjudul, tombol aksi di kanan atas) untuk SEMUA halaman panel admin. Hanya mengubah tampilan,
    bukan isi, urutan, atau perilaku. Disisipkan global lewat render hook HEAD_END di AdminPanelProvider. Memakai kelas Filament (fi-header*,
    fi-breadcrumbs*, fi-btn) dan variabel warna Filament (--gray-*) agar ikut mode gelap (.dark).

    Ringkasnya: breadcrumb kecil dan redup (halaman aktif sedikit lebih gelap); judul 24px semi-bold dengan jarak huruf rapat; subjudul 14px;
    tombol aksi sejajar dengan baris judul, bersudut 0.625rem, tanpa bayangan; jarak vertikal kepala dan isi dirapatkan (32px menjadi 24px).
--}}
<style>
    .fi-page > section { padding-block: 1.5rem; gap: 1.5rem; }

    .fi-header { gap: 1rem; }
    @media (min-width: 640px) { .fi-header { align-items: flex-end; } }

    /* Breadcrumb: kecil dan redup; pemisah tipis; halaman aktif (terakhir) lebih gelap. */
    .fi-header .fi-breadcrumbs { margin-bottom: .375rem; }
    .fi-breadcrumbs-list { gap: .25rem; }
    .fi-breadcrumbs-item { gap: .25rem; }
    .fi-breadcrumbs-item-separator { width: .875rem; height: .875rem; color: rgb(var(--gray-300)); }
    .dark .fi-breadcrumbs-item-separator { color: rgb(var(--gray-600)); }
    .fi-breadcrumbs-item-label { font-size: .75rem; line-height: 1rem; font-weight: 500; color: rgb(var(--gray-500)); }
    .dark .fi-breadcrumbs-item-label { color: rgb(var(--gray-400)); }
    a.fi-breadcrumbs-item-label:hover { color: rgb(var(--gray-900)); }
    .dark a.fi-breadcrumbs-item-label:hover { color: #fff; }
    .fi-breadcrumbs-item:last-child .fi-breadcrumbs-item-label { color: rgb(var(--gray-700)); }
    .dark .fi-breadcrumbs-item:last-child .fi-breadcrumbs-item-label { color: rgb(var(--gray-200)); }

    /* Judul dan subjudul. */
    .fi-header-heading { font-size: 1.5rem; line-height: 2rem; font-weight: 600; letter-spacing: -.02em; }
    @media (min-width: 640px) { .fi-header-heading { font-size: 1.625rem; line-height: 2.125rem; } }
    .fi-header-subheading { margin-top: .25rem; font-size: .875rem; line-height: 1.375rem; color: rgb(var(--gray-500)); }
    .dark .fi-header-subheading { color: rgb(var(--gray-400)); }

    /* Tombol aksi: sejajar dengan baris judul (bukan turun 28px), kecil, bersudut halus. Tombol utama tanpa bayangan. */
    .fi-header > div:last-child { margin-top: 0 !important; gap: .5rem; }
    .fi-header .fi-btn { border-radius: .625rem; padding: .5rem .875rem; font-size: .8125rem; font-weight: 600; }
    .fi-header .fi-btn.fi-color-primary,
    .fi-header .fi-btn.fi-color-danger { box-shadow: none; --tw-ring-shadow: 0 0 #0000; }
</style>
