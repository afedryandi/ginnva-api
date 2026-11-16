{{--
    Gaya bersih untuk halaman daftar, form, dan detail yang sudah dirombak (daftar halamannya di App\Support\CleanUi). Disisipkan lewat render hook
    PAGE_START hanya di halaman itu, jadi menu lain tidak berubah. Panel memakai CSS Filament terkompilasi, jadi ini CSS polos berprefiks .cu-
    atau aturan ke kelas fi-* Filament. Warna memakai variabel Filament (--gray-*, --primary-*, --danger-*) agar ikut warna primer dan mode gelap.
    Angka tabular-nums supaya kolom sejajar.
--}}
@once
    <style>
        .fi-main {
            --cu-line: rgba(var(--gray-200), .7);
            --cu-surface: #fff;
            --cu-soft: rgba(var(--gray-100), .75);
            --cu-ink: rgb(var(--gray-950));
            --cu-muted: rgb(var(--gray-500));
            --cu-radius: 1rem;
            font-feature-settings: 'tnum' 1, 'cv11' 1;
        }
        .dark .fi-main {
            --cu-line: rgba(255, 255, 255, .08);
            --cu-surface: rgb(var(--gray-900));
            --cu-soft: rgba(255, 255, 255, .04);
            --cu-ink: #fff;
            --cu-muted: rgb(var(--gray-400));
        }

        /* Kartu (section form, detail, tabel): sudut 1rem, garis tipis, tanpa bayangan. */
        .fi-main .fi-section,
        .fi-main .fi-ta-ctn {
            background: var(--cu-surface); border: 1px solid var(--cu-line); border-radius: var(--cu-radius);
            box-shadow: none; --tw-ring-shadow: 0 0 #0000; --tw-ring-color: transparent;
        }
        .fi-main .fi-section { overflow: hidden; }
        .fi-main .fi-section-header { padding: 1rem 1.25rem .25rem; }
        .fi-main .fi-section-header-heading { font-size: .875rem; font-weight: 600; color: var(--cu-ink); }
        .fi-main .fi-section-header-description { font-size: .75rem; color: var(--cu-muted); }
        .fi-main .fi-section-content { padding: .875rem 1.25rem 1.25rem; }

        /* Tabel: kepala tipis dan redup, baris lega, hover lembut. */
        .fi-main .fi-ta-ctn { overflow: hidden; }
        .fi-main .fi-ta-header-ctn { border-color: var(--cu-line); }
        .fi-main .fi-ta-table { font-variant-numeric: tabular-nums; }
        .fi-main .fi-ta-table thead tr { background: var(--cu-soft); }
        .fi-main .fi-ta-header-cell { padding-top: .625rem; padding-bottom: .625rem; }
        .fi-main .fi-ta-header-cell-label {
            font-size: .6875rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--cu-muted);
        }
        .fi-main .fi-ta-cell { padding-top: .125rem; padding-bottom: .125rem; }
        .fi-main .fi-ta-row { transition: background-color .12s ease; }
        .fi-main .fi-ta-row:hover { background: var(--cu-soft); }
        .fi-main .fi-ta-text-item-description { font-size: .75rem; color: var(--cu-muted); }
        .fi-main .fi-ta-pagination { border-color: var(--cu-line); }

        /* Lead baru yang belum direspons lebih dari 24 jam: garis tepi merah lembut di kiri baris. */
        .fi-main .cu-row-late { box-shadow: inset 3px 0 0 rgb(var(--danger-500)); background: rgba(var(--danger-500), .04); }

        /* Lencana: pastel lembut, sudut seragam. */
        .fi-main .fi-badge { border-radius: .5rem; font-weight: 600; }

        /* Form: label tegas, bantuan redup dan kecil. */
        .fi-main .fi-fo-field-wrp-label span { font-size: .8125rem; font-weight: 600; color: var(--cu-ink); }
        .fi-main .fi-fo-field-wrp-hint,
        .fi-main .fi-fo-field-wrp-helper-text { font-size: .75rem; color: var(--cu-muted); }

        /* Detail (infolist): label redup kecil di atas, nilai tegas. */
        .fi-main .fi-in-entry-wrp-label span { font-size: .75rem; font-weight: 500; color: var(--cu-muted); }
        .fi-main .fi-in-text-item { color: var(--cu-ink); }

        /* Tab status di atas daftar. */
        .fi-main .fi-tabs { border-radius: var(--cu-radius); box-shadow: none; border: 1px solid var(--cu-line); background: var(--cu-surface); }
    </style>
@endonce
