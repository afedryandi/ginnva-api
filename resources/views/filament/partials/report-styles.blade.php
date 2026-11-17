{{--
    Gaya tambahan untuk halaman kluster Laporan (Ringkasan Penjualan, Penjualan Per Periode, Laporan Jasa, dst.), disisipkan lewat render hook PAGE_START
    bersama admin-ui-styles hanya di halaman itu. Laporan memakai <table class="w-full"> biasa (bukan tabel Filament) dengan gaya kelas masing-masing,
    jadi aturan di sini menyeragamkan tampilannya tanpa mengubah isi tiap laporan: kepala kecil huruf kapital redup, sel lega bersekat tipis, hover lembut,
    angka tabular. Hanya warna, jarak, dan garis; perataan teks tiap sel tetap dari kelas aslinya. Warna memakai variabel Filament agar ikut mode gelap.
--}}
@once
    <style>
        .fi-main table.w-full:not(.fi-ta-table) {
            border-collapse: collapse;
            font-variant-numeric: tabular-nums;
        }
        .fi-main table.w-full:not(.fi-ta-table) thead th {
            padding: .625rem .75rem;
            font-size: .6875rem;
            font-weight: 600;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: var(--cu-muted);
            background: var(--cu-soft);
            border-bottom: 1px solid var(--cu-line);
            white-space: nowrap;
        }
        .fi-main table.w-full:not(.fi-ta-table) tbody td {
            padding-top: .625rem;
            padding-bottom: .625rem;
            border-bottom: 1px solid var(--cu-line);
        }
        .fi-main table.w-full:not(.fi-ta-table) tbody tr { transition: background-color .12s ease; }
        .fi-main table.w-full:not(.fi-ta-table) tbody tr:hover { background: var(--cu-soft); }
        .fi-main table.w-full:not(.fi-ta-table) tbody tr:last-child td { border-bottom: 0; }
        .fi-main table.w-full:not(.fi-ta-table) tfoot td,
        .fi-main table.w-full:not(.fi-ta-table) tfoot th {
            padding-top: .625rem;
            padding-bottom: .625rem;
            font-weight: 600;
            background: var(--cu-soft);
            border-top: 1px solid var(--cu-line);
        }

        /* Judul kartu laporan: ringkas dan tegas, sama dengan kartu menu lain. */
        .fi-main .fi-section-header-heading { letter-spacing: -.005em; }
    </style>
@endonce
