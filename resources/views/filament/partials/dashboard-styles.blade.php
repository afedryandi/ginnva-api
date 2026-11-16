{{--
    Gaya Dashboard (visual redesign). Panel memakai CSS Filament yang sudah dikompilasi, jadi semua aturan di sini CSS polos berprefiks .gd-
    dan hanya berlaku di dalam <div class="gd"> (halaman Dashboard); halaman lain tidak berubah. Warna memakai variabel abu-abu Filament
    (--gray-*) dan --primary-* supaya ikut warna primer perusahaan dan mode gelap (kelas .dark di <html>).
    Angka memakai tabular-nums supaya kolom nominal sejajar.
--}}
@once
    <style>
        .gd {
            --gd-line: rgba(var(--gray-200), .7);
            --gd-surface: #fff;
            --gd-soft: rgba(var(--gray-100), .7);
            --gd-ink: rgb(var(--gray-950));
            --gd-muted: rgb(var(--gray-500));
            --gd-radius: 1rem;
            font-feature-settings: 'tnum' 1, 'cv11' 1;
            font-variant-numeric: tabular-nums;
        }
        .dark .gd {
            --gd-line: rgba(255, 255, 255, .08);
            --gd-surface: rgb(var(--gray-900));
            --gd-soft: rgba(255, 255, 255, .04);
            --gd-ink: #fff;
            --gd-muted: rgb(var(--gray-400));
        }

        /* Tata letak: jarak antar blok selalu 1.5rem, antar kartu 1rem. */
        .gd { display: flex; flex-direction: column; gap: 1.75rem; }
        .gd-block { display: flex; flex-direction: column; gap: .75rem; }
        .gd-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
        @media (min-width: 1024px) {
            .gd-grid--2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .gd-grid--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        .gd-grid > * { min-width: 0; }

        /* Judul blok: kecil, huruf kapital, redup; hanya penanda, bukan pusat perhatian. */
        .gd-label {
            display: flex; align-items: center; gap: .5rem;
            font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--gd-muted);
        }
        .gd-label svg { width: 1rem; height: 1rem; }
        .gd-label::after { content: ''; flex: 1; height: 1px; background: var(--gd-line); }

        /* Bilah atas: ruang lingkup data + filter toko. */
        .gd-bar {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem;
            padding: .75rem 1rem; background: var(--gd-surface); border: 1px solid var(--gd-line); border-radius: var(--gd-radius);
        }
        .gd-bar-info { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem 1rem; font-size: .8125rem; color: var(--gd-muted); }
        .gd-bar-info strong { color: var(--gd-ink); font-weight: 600; }
        .gd-dot { width: .375rem; height: .375rem; border-radius: 9999px; background: rgb(var(--primary-500)); display: inline-block; margin-right: .375rem; }
        .gd-filter {
            display: inline-flex; align-items: center; gap: .5rem; padding: .375rem .75rem;
            border: 1px solid var(--gd-line); border-radius: .75rem; background: var(--gd-soft); font-size: .8125rem;
        }
        .gd-filter svg { width: 1rem; height: 1rem; color: var(--gd-muted); }
        .gd-filter select {
            border: 0; background-color: transparent; box-shadow: none; outline: none; padding: 0 1.5rem 0 0;
            font: inherit; font-weight: 500; color: var(--gd-ink); cursor: pointer;
        }
        .gd-filter select:focus { box-shadow: none; outline: none; }
        .dark .gd-filter select { color-scheme: dark; }

        /* Kartu: sudut 1rem, garis tipis, tanpa bayangan; latar putih (gelap: abu-abu 900). */
        .gd .fi-section,
        .gd .fi-wi-stats-overview-stat {
            background: var(--gd-surface); border: 1px solid var(--gd-line); border-radius: var(--gd-radius);
            box-shadow: none; --tw-ring-shadow: 0 0 #0000; --tw-ring-color: transparent;
        }
        .gd .fi-section { overflow: hidden; }
        .gd .fi-section-header { padding: 1rem 1.25rem .25rem; }
        .gd .fi-section-header-heading { font-size: .875rem; font-weight: 600; letter-spacing: -.005em; color: var(--gd-ink); }
        .gd .fi-section-header-description { font-size: .75rem; color: var(--gd-muted); }
        .gd .fi-section-content { padding: .75rem 1.25rem 1.25rem; }

        /* Kartu angka: label redup di atas, nilai besar, keterangan kecil berwarna. */
        .gd .fi-wi-stats-overview-stat { padding: 1.125rem 1.25rem; transition: border-color .15s ease; }
        .gd a.fi-wi-stats-overview-stat:hover,
        .gd .fi-wi-stats-overview-stat:hover { border-color: rgba(var(--primary-500), .35); }
        .gd .fi-wi-stats-overview-stat-label { font-size: .8125rem; font-weight: 500; color: var(--gd-muted); }
        .gd .fi-wi-stats-overview-stat-value {
            font-size: 1.625rem; line-height: 2rem; font-weight: 600; letter-spacing: -.02em; color: var(--gd-ink);
            font-variant-numeric: tabular-nums;
        }
        .gd .fi-wi-stats-overview-stat-description { font-size: .75rem; font-weight: 500; }
        .gd .fi-wi-stats-overview { gap: 1rem; }

        /* Grafik: ruang napas dan tinggi seragam. */
        .gd .fi-wi-chart .fi-section-content { padding-top: .5rem; }

        /* Lencana status: pastel lembut, teks tajam. */
        .gd .fi-badge { border-radius: .5rem; font-weight: 600; font-variant-numeric: tabular-nums; }

        /* Perlu Perhatian: tiap item kartu kecil dengan ikon di lingkaran pastel. */
        .gd-attn { display: grid; grid-template-columns: minmax(0, 1fr); gap: .75rem; }
        @media (min-width: 640px) { .gd-attn { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1280px) { .gd-attn { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .gd-attn-item {
            display: flex; align-items: flex-start; gap: .75rem; padding: .875rem 1rem; text-decoration: none; color: inherit;
            border: 1px solid var(--gd-line); border-radius: .875rem; background: var(--gd-surface); transition: background .15s ease, border-color .15s ease;
        }
        .gd-attn-item:hover { background: var(--gd-soft); }
        .gd-attn-icon { width: 2.25rem; height: 2.25rem; border-radius: .75rem; display: flex; align-items: center; justify-content: center; flex: none; }
        .gd-attn-icon svg { width: 1.25rem; height: 1.25rem; }
        .gd-attn-item--danger .gd-attn-icon { background: rgba(var(--danger-500), .1); color: rgb(var(--danger-600)); }
        .gd-attn-item--warning .gd-attn-icon { background: rgba(var(--warning-500), .12); color: rgb(var(--warning-600)); }
        .gd-attn-item--info .gd-attn-icon { background: rgba(var(--info-500), .1); color: rgb(var(--info-600)); }
        .dark .gd-attn-item--danger .gd-attn-icon { color: rgb(var(--danger-400)); }
        .dark .gd-attn-item--warning .gd-attn-icon { color: rgb(var(--warning-400)); }
        .dark .gd-attn-item--info .gd-attn-icon { color: rgb(var(--info-400)); }
        .gd-attn-title { display: flex; align-items: center; gap: .5rem; font-size: .875rem; font-weight: 600; color: var(--gd-ink); }
        .gd-attn-text { margin-top: .125rem; font-size: .75rem; line-height: 1.15rem; color: var(--gd-muted); }
        .gd-ok { display: flex; align-items: center; gap: .625rem; padding: .25rem 0; font-size: .875rem; color: var(--gd-muted); }
        .gd-ok svg { width: 1.25rem; height: 1.25rem; color: rgb(var(--success-500)); flex: none; }
    </style>
@endonce
