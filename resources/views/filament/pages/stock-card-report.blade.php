<x-filament-panels::page>
    {{-- Gaya khusus halaman ini: CSS polos berprefiks .sk- (panel memakai CSS Filament terkompilasi, class Tailwind proyek tidak tersedia, sehingga
         class utilitas seperti text-sm atau border-gray-200 sebelumnya tidak berefek). Warna memakai variabel Filament (--gray-*, --primary-*,
         --danger-*, --success-*) agar ikut warna primer dan mode gelap (.dark). Hanya tampilan: perhitungan dan data tidak berubah. --}}
    @once
        <style>
            .sk { --sk-ink: rgb(var(--gray-900)); --sk-text: rgb(var(--gray-700)); --sk-muted: rgb(var(--gray-500)); --sk-line: rgba(var(--gray-200), .7); --sk-surface: #fff; --sk-soft: rgba(var(--gray-100), .75); --sk-in: rgb(var(--success-600)); --sk-out: rgb(var(--danger-600)); --sk-accent: rgb(var(--primary-600)); --sk-warn-bg: rgba(245, 158, 11, .12); --sk-warn: #92400e; display: flex; flex-direction: column; gap: 1rem; font-variant-numeric: tabular-nums; }
            .dark .sk { --sk-ink: rgb(var(--gray-50)); --sk-text: rgb(var(--gray-200)); --sk-muted: rgb(var(--gray-400)); --sk-line: rgba(255, 255, 255, .08); --sk-surface: rgb(var(--gray-900)); --sk-soft: rgba(255, 255, 255, .04); --sk-in: rgb(var(--success-400)); --sk-out: rgb(var(--danger-400)); --sk-accent: rgb(var(--primary-300)); --sk-warn-bg: rgba(245, 158, 11, .16); --sk-warn: #fcd34d; }
            .sk-loading { opacity: .5; pointer-events: none; }

            .sk-note, .sk-alert { padding: .75rem 1rem; border: 1px solid var(--sk-line); border-radius: .75rem; font-size: .8125rem; line-height: 1.25rem; color: var(--sk-muted); background: var(--sk-soft); }
            .sk-alert { color: var(--sk-warn); background: var(--sk-warn-bg); border-color: rgba(245, 158, 11, .35); }

            .sk-card { background: var(--sk-surface); border: 1px solid var(--sk-line); border-radius: 1rem; }
            .sk-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--sk-line); }
            .sk-title { font-size: .9375rem; font-weight: 650; color: var(--sk-ink); }
            .sk-scroll { overflow-x: auto; }

            .sk-table { width: 100%; border-collapse: collapse; font-size: .8125rem; color: var(--sk-text); }
            .sk-table th { padding: .625rem .875rem; text-align: left; white-space: nowrap; font-size: .6875rem; font-weight: 650; letter-spacing: .05em; text-transform: uppercase; color: var(--sk-muted); background: var(--sk-soft); border-bottom: 1px solid var(--sk-line); }
            .sk-table td { padding: .625rem .875rem; white-space: nowrap; border-bottom: 1px solid var(--sk-line); }
            .sk-table tbody tr:hover { background: var(--sk-soft); }
            .sk-table tbody tr:last-child td { border-bottom: 0; }
            .sk-r { text-align: right !important; }
            .sk-sub { font-size: .6875rem; font-weight: 400; color: var(--sk-muted); white-space: normal; line-height: 1.3; }
            .sk-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .75rem; color: var(--sk-muted); }
            .sk-name { font-weight: 600; color: var(--sk-ink); }
            .sk-name a { color: inherit; text-decoration: none; }
            .sk-name a:hover { color: var(--sk-accent); text-decoration: underline; }
            .sk-dim td { color: var(--sk-muted); }
            .sk-in { color: var(--sk-in); }
            .sk-out { color: var(--sk-out); }
            .sk-neg { color: var(--sk-out); font-weight: 650; }
            .sk-strong { font-weight: 650; color: var(--sk-ink); }
            .sk-tag { display: inline-flex; align-items: center; height: 1.25rem; margin-left: .375rem; padding: 0 .4375rem; border-radius: .4375rem; font-size: .6875rem; font-weight: 650; background: var(--sk-soft); color: var(--sk-muted); }
            .sk-tag.is-low { background: rgba(var(--danger-500), .1); color: var(--sk-out); }
            .sk-link { appearance: none; border: 0; background: none; padding: 0; font: inherit; font-size: .75rem; font-weight: 600; color: var(--sk-accent); cursor: pointer; }
            .sk-link:hover { text-decoration: underline; }
            .sk-total td { font-weight: 650; color: var(--sk-ink); background: var(--sk-soft); border-top: 1px solid var(--sk-line); border-bottom: 0; }
            .sk-empty { padding: 2rem 1rem !important; text-align: center; color: var(--sk-muted); }
            .sk-more { padding: .75rem; text-align: center; border-top: 1px solid var(--sk-line); }
            .sk-form { padding: 1rem 1.25rem; }
        </style>
    @endonce

    @php
        $result = $this->getResult();
        $shown = $result['rows']->take($this->limit);
        $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',') ?: '0';
        $filterTargets = 'data.from, data.to, data.jenis, data.preset, data.search, data.category, data.sort, data.only_moved';
        $detail = $this->getDetail();
    @endphp

    <div class="sk">
        <div class="sk-card sk-form">
            {{ $this->form }}
        </div>

        <div wire:loading.class="sk-loading" wire:target="{{ $filterTargets }}" style="display: flex; flex-direction: column; gap: 1rem;">
            <div class="sk-note">
                Awal dan Akhir dihitung mundur dari stok sekarang dan riwayat pergerakan. Masuk = pembelian/pencatatan masuk + pengembalian dari memo + penyesuaian lebih (opname); Keluar = pemakaian (termasuk pengambilan memo) + stok terbuang + penyesuaian kurang. Rinciannya tertulis kecil di bawah angka. Awal + Masuk − Keluar = Akhir. Stok masih nasional (belum per toko).
            </div>

            @if ($result['totals']['anomalies'] > 0)
                <div class="sk-alert">
                    ⚠ {{ $result['totals']['anomalies'] }} barang punya Awal atau Akhir negatif: stok dan riwayatnya tidak sinkron. Angka ditampilkan apa adanya (merah); jalankan <strong>Cek Rekonsiliasi</strong> di menu Riwayat Bahan Baku / Barang Habis Pakai.
                </div>
            @endif

            <div class="sk-card">
                <div class="sk-head">
                    <div class="sk-title">Kartu Stok — {{ $result['from']->translatedFormat('d M Y') }} s/d {{ $result['to']->translatedFormat('d M Y') }}</div>
                </div>

                <div class="sk-scroll">
                    <table class="sk-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Nama</th>
                                <th>Jenis</th>
                                <th>Kategori</th>
                                <th class="sk-r">Awal</th>
                                <th class="sk-r">Masuk</th>
                                <th class="sk-r">Keluar</th>
                                <th class="sk-r">Akhir</th>
                                <th>Satuan</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($shown as $row)
                                @php $url = $this->itemUrl($row); @endphp
                                <tr class="{{ $row['hasMovement'] ? '' : 'sk-dim' }}">
                                    <td class="sk-code">{{ $row['code'] ?: '—' }}</td>
                                    <td class="sk-name">
                                        @if ($url)
                                            <a href="{{ $url }}" title="Lihat item ini">{{ $row['name'] }}</a>
                                        @else
                                            {{ $row['name'] }}
                                        @endif
                                        @if ($row['inactive'])
                                            <span class="sk-tag">Nonaktif</span>
                                        @endif
                                        @if ($row['low'])
                                            <span class="sk-tag is-low" title="Stok sekarang di bawah atau sama dengan batas minimum {{ $num($row['reorder_point']) }} {{ $row['unit'] }}">Menipis</span>
                                        @endif
                                    </td>
                                    <td>{{ $row['jenis'] }}</td>
                                    <td style="color: var(--sk-muted)">{{ $row['category'] ?: '—' }}</td>
                                    <td class="sk-r {{ $row['awal'] < 0 ? 'sk-neg' : '' }}">{{ $num($row['awal']) }}</td>
                                    <td class="sk-r sk-in">{{ $row['masuk'] > 0 ? '+'.$num($row['masuk']) : '0' }}@if ($row['masuk'] > 0)<div class="sk-sub">{{ \App\Services\StockCardService::breakdownText($row['masuk_detail'], \App\Services\StockCardService::MASUK_LABELS, $num) }}</div>@endif</td>
                                    <td class="sk-r sk-out">{{ $row['keluar'] > 0 ? '−'.$num($row['keluar']) : '0' }}@if ($row['keluar'] > 0)<div class="sk-sub">{{ \App\Services\StockCardService::breakdownText($row['keluar_detail'], \App\Services\StockCardService::KELUAR_LABELS, $num) }}</div>@endif</td>
                                    <td class="sk-r {{ $row['akhir'] < 0 ? 'sk-neg' : 'sk-strong' }}">{{ $num($row['akhir']) }}</td>
                                    <td style="color: var(--sk-muted)">{{ $row['unit'] }}</td>
                                    <td class="sk-r">
                                        <button type="button" wire:click="openDetail('{{ $row['source'] }}', {{ $row['id'] }})" class="sk-link">Rincian</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="sk-empty">Tidak ada bahan pada jenis ini.</td></tr>
                            @endforelse
                        </tbody>
                        @if ($result['rows']->isNotEmpty())
                            <tfoot>
                                <tr class="sk-total">
                                    <td colspan="5">Total {{ $result['totals']['count'] }} barang (jumlah lintas satuan, hanya sebagai penanda)</td>
                                    <td class="sk-r sk-in">+{{ $num($result['totals']['masuk']) }}</td>
                                    <td class="sk-r sk-out">−{{ $num($result['totals']['keluar']) }}</td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>

                @if ($result['rows']->count() > $shown->count())
                    <div class="sk-more">
                        <x-filament::button color="gray" size="sm" wire:click="showMore">
                            Tampilkan lebih banyak ({{ $shown->count() }} dari {{ $result['rows']->count() }})
                        </x-filament::button>
                    </div>
                @endif
            </div>

            @if ($detail)
                <div class="sk-card">
                    <div class="sk-head">
                        <div class="sk-title">Rincian — {{ $detail['name'] }}</div>
                        <x-filament::button color="gray" size="xs" wire:click="closeDetail">Tutup</x-filament::button>
                    </div>

                    <div class="sk-scroll">
                        <table class="sk-table">
                            <thead>
                                <tr>
                                    <th>Waktu</th>
                                    <th>Jenis</th>
                                    <th class="sk-r">Jumlah</th>
                                    <th class="sk-r">Saldo</th>
                                    <th>Dicatat oleh</th>
                                    <th>Sumber / Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="sk-dim">
                                    <td colspan="3">Saldo awal periode</td>
                                    <td class="sk-r">{{ $num($detail['awal']) }} {{ $detail['unit'] }}</td>
                                    <td colspan="2"></td>
                                </tr>
                                @forelse ($detail['lines'] as $line)
                                    <tr>
                                        <td>{{ $line['at']->format('d M Y, H:i') }}</td>
                                        <td>{{ $line['type'] }}</td>
                                        <td class="sk-r {{ $line['signed'] > 0 ? 'sk-in' : ($line['signed'] < 0 ? 'sk-out' : '') }}">{{ $line['signed'] > 0 ? '+' : ($line['signed'] < 0 ? '−' : '') }}{{ $num(abs($line['signed'])) }}</td>
                                        <td class="sk-r">{{ $num($line['balance']) }}</td>
                                        <td>{{ $line['by'] ?? '—' }}{{ $line['store'] ? ' · '.$line['store'] : '' }}</td>
                                        <td style="white-space: normal; color: var(--sk-muted)">{{ $line['source'] ? $line['source'].($line['note'] ? ' — ' : '') : '' }}{{ $line['note'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="sk-empty">Tidak ada pergerakan pada periode ini.</td></tr>
                                @endforelse
                                <tr class="sk-total">
                                    <td colspan="3">Saldo akhir periode</td>
                                    <td class="sk-r">{{ $num($detail['akhir']) }} {{ $detail['unit'] }}</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
