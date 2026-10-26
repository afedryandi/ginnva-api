{{--
    Gaya polos untuk tampilan kustom di panel admin. Panel memakai CSS Filament yang sudah dikompilasi, bukan Tailwind proyek ini, jadi class
    Tailwind yang tidak dipakai bawaan Filament (ukuran kecil, rounded-xl, gap pecahan, warna tertentu) tidak ada efeknya. Gaya di sini CSS polos
    berprefiks .pu- dan mengikuti mode gelap lewat kelas .dark. Pakai: @include('filament.partials.plain-ui') lalu bungkus tampilan dengan <div class="pu">.
--}}
@once
    <style>
        .pu {
            --pu-ink: #111827; --pu-text: #1f2937; --pu-muted: #6b7280; --pu-line: #e5e7eb; --pu-soft: #f9fafb; --pu-hover: #f3f4f6;
            --pu-ok: #15803d; --pu-warn: #b45309; --pu-danger: #b91c1c; --pu-link: #b91c1c;
            font-size: 14px; color: var(--pu-text);
        }
        .dark .pu {
            --pu-ink: #f9fafb; --pu-text: #e5e7eb; --pu-muted: #9ca3af; --pu-line: rgba(255, 255, 255, .12); --pu-soft: rgba(255, 255, 255, .05);
            --pu-hover: rgba(255, 255, 255, .08); --pu-ok: #4ade80; --pu-warn: #fbbf24; --pu-danger: #f87171; --pu-link: #f87171;
        }
        .pu-stack { display: flex; flex-direction: column; gap: 12px; }
        .pu-row { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 16px; }
        .pu-muted { color: var(--pu-muted); }
        .pu-strong { font-weight: 600; color: var(--pu-ink); }
        .pu-ok { color: var(--pu-ok); }
        .pu-warn { color: var(--pu-warn); }
        .pu-danger { color: var(--pu-danger); }
        .pu-small { font-size: 12px; }
        .pu-link { color: var(--pu-link); font-weight: 600; text-decoration: none; }
        .pu-link:hover { text-decoration: underline; }
        .pu-bar { padding: 10px 14px; border: 1px solid var(--pu-line); border-radius: 10px; font-size: 13px; }
        .pu-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
        .pu-card { padding: 12px 14px; border: 1px solid var(--pu-line); border-radius: 10px; }
        .pu-card-label { font-size: 12px; color: var(--pu-muted); }
        .pu-card-value { margin-top: 4px; font-size: 18px; font-weight: 600; color: var(--pu-ink); font-variant-numeric: tabular-nums; }
        .pu-card-note { margin-top: 2px; font-size: 12px; color: var(--pu-muted); }
        .pu-scroll { overflow-x: auto; border: 1px solid var(--pu-line); border-radius: 10px; }
        .pu-table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
        .pu-table th, .pu-table td { padding: 8px 12px; border-bottom: 1px solid var(--pu-line); text-align: left; white-space: nowrap; }
        .pu-table thead th { background: var(--pu-soft); font-size: 12px; font-weight: 600; color: var(--pu-muted); }
        .pu-table tbody tr:last-child td { border-bottom: 0; }
        .pu-table .is-num { text-align: right; }
        .pu-table.is-dense th, .pu-table.is-dense td { padding: 6px 10px; font-size: 12px; }
        .pu-table td.is-first, .pu-table th.is-first { position: sticky; left: 0; background: var(--pu-soft); }
        .pu-table tr.is-section td { padding-top: 14px; font-size: 13px; font-weight: 600; color: var(--pu-ink); border-bottom: 0; background: transparent; }
        .pu-table tr.is-total td { font-weight: 600; color: var(--pu-ink); border-top: 1px solid var(--pu-muted); }
        .pu-table tr.is-profit td { font-weight: 700; color: var(--pu-ink); background: var(--pu-soft); }
        .pu-table tr.is-profit td.is-neg { color: var(--pu-danger); }
        @media (max-width: 640px) {
            .pu-card-value { font-size: 16px; }
            .pu-table th, .pu-table td { padding: 6px 8px; }
        }
    </style>
@endonce
