{{-- mount() pada halaman ini SELALU redirect sebelum view ini sempat dirender
     — file ini murni jaring pengaman supaya Filament tidak error kalau
     $view dipanggil sebelum redirect selesai diproses. --}}
<x-filament-panels::page></x-filament-panels::page>
