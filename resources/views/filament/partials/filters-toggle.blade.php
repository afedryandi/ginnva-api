{{--
    Ikon filter di samping kolom cari (2026-12-16). Filament menaruh tombol filter tabel bergaya "AboveContentCollapsible" di baris sendiri di atas
    tabel; tombol ini muncul di bilah alat (antara kolom cari dan tombol kolom) dan membuka/menutup panel filter yang sama, yang meluas ke bawah
    di atas daftar. Kotak filter tetap milik Filament (status buka-tutupnya ada di x-data penampungnya), jadi tombol ini hanya membalik
    `areFiltersOpen`. Tombol asli di baris atas disembunyikan lewat kelas .gx-filter-moved (resources/css/filament/admin/theme.css) hanya setelah
    tombol ini tampil. Tabel tanpa panel filter tidak menampilkan apa pun.
--}}
<span
    x-data="{ host: null }"
    x-init="host = $el.closest('.fi-ta')?.querySelector('.fi-ta-filters-above-content-ctn'); if (host) host.classList.add('gx-filter-moved')"
    x-show="host"
    x-cloak
>
    <x-filament::icon-button
        icon="heroicon-m-funnel"
        color="gray"
        label="Filter"
        x-on:click="const state = Alpine.$data(host); state.areFiltersOpen = ! state.areFiltersOpen"
    />
</span>
