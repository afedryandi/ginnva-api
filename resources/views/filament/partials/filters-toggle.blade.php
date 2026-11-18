{{--
    Ikon filter di samping kolom cari (2026-12-16). Filament menaruh tombol filter tabel bergaya "AboveContentCollapsible" di baris sendiri di atas
    tabel; tombol ini muncul di bilah alat (antara kolom cari dan tombol kolom) dan membuka/menutup panel filter yang sama, yang meluas ke bawah
    di atas daftar. Kotak filter tetap milik Filament (status buka-tutupnya ada di x-data penampungnya), jadi tombol ini hanya membalik
    `areFiltersOpen`. Tombol asli di baris atas dan kotak filter yang tertutup disembunyikan lewat CSS `:has(.gx-filter-toggle)`
    (resources/css/filament/admin/theme.css) selama tombol ini ada. Sengaja TANPA kelas yang ditambahkan lewat JS: kelas seperti itu hilang saat
    Livewire memorf ulang tabel (mis. setelah tabel dimuat), sehingga tombol asli muncul lagi dan ikon filter jadi dua. Tabel tanpa panel filter
    tidak menampilkan apa pun.
--}}
<span
    class="gx-filter-toggle"
    x-data="{ host: null }"
    x-init="host = $el.closest('.fi-ta')?.querySelector('.fi-ta-filters-above-content-ctn')"
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
