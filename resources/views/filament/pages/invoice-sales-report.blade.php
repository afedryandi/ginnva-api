<x-filament-panels::page>
    {{-- Panel memakai CSS Filament terkompilasi: gaya di bawah polos (awalan .is-) dan tidak bergantung pada Tailwind. --}}
    @once
        <style>
            .is-filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
            .is-field { display: flex; flex-direction: column; gap: 4px; min-width: 150px; }
            .is-field label { font-size: 12px; font-weight: 600; color: rgba(107, 114, 128, 1); }
            .is-field input, .is-field select { border: 1px solid rgba(107, 114, 128, .4); border-radius: 8px; padding: 7px 10px; font-size: 14px; background: transparent; color: inherit; }
            .is-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
            .is-card { padding: 14px 16px; border: 1px solid rgba(107, 114, 128, .25); border-radius: 12px; }
            .is-card-label { font-size: 12px; font-weight: 600; color: rgba(107, 114, 128, 1); }
            .is-card-value { margin-top: 4px; font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
            .is-table { width: 100%; border-collapse: collapse; font-size: 14px; }
            .is-table th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: rgba(107, 114, 128, 1); padding: 8px 10px; border-bottom: 1px solid rgba(107, 114, 128, .3); white-space: nowrap; }
            .is-table td { padding: 8px 10px; border-bottom: 1px solid rgba(107, 114, 128, .15); white-space: nowrap; }
            .is-table .is-num { text-align: right; font-variant-numeric: tabular-nums; }
            .is-table tfoot td { font-weight: 700; border-top: 2px solid rgba(107, 114, 128, .5); border-bottom: 0; }
            .is-scroll { overflow-x: auto; }
            .is-note { font-size: 13px; color: rgba(107, 114, 128, 1); }
            .is-paid { color: #15803d; font-weight: 600; } .is-unpaid { color: #b45309; font-weight: 600; }
            .dark .is-paid { color: #4ade80; } .dark .is-unpaid { color: #fbbf24; }
        </style>
    @endonce

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <x-filament::section>
        <div class="is-filters">
            <div class="is-field">
                <label for="is-from">Dari</label>
                <input id="is-from" type="date" wire:model.live="from">
            </div>
            <div class="is-field">
                <label for="is-to">Sampai</label>
                <input id="is-to" type="date" wire:model.live="to">
            </div>
            <div class="is-field">
                <label for="is-gran">Kelompokkan per</label>
                <select id="is-gran" wire:model.live="granularity">
                    <option value="harian">Harian</option>
                    <option value="mingguan">Mingguan</option>
                    <option value="bulanan">Bulanan</option>
                </select>
            </div>
            @if ($this->isFullAccess())
                <div class="is-field">
                    <label for="is-store">Toko</label>
                    <select id="is-store" wire:model.live="storeId">
                        <option value="">Semua toko</option>
                        @foreach ($this->storeOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
        <p class="is-note" style="margin-top: 10px;">
            Penjualan dihitung dari invoice yang sudah terbit (Belum Lunas dan Lunas), menurut tanggal terbit. Draf dan Void tidak dihitung.
        </p>
    </x-filament::section>

    @if ($result['clamped'])
        <p class="is-note">Rentang dibatasi maksimal 2 tahun ({{ $result['from']->translatedFormat('d M Y') }} – {{ $result['to']->translatedFormat('d M Y') }}).</p>
    @endif

    <div class="is-cards">
        <div class="is-card"><div class="is-card-label">Penjualan (Total Tagihan)</div><div class="is-card-value">{{ $rupiah($result['total']) }}</div></div>
        <div class="is-card"><div class="is-card-label">Jumlah Invoice</div><div class="is-card-value">{{ number_format($result['count'], 0, ',', '.') }}</div></div>
        <div class="is-card"><div class="is-card-label">Terbayar</div><div class="is-card-value">{{ $rupiah($result['paid']) }}</div></div>
        <div class="is-card"><div class="is-card-label">Sisa Tagihan</div><div class="is-card-value">{{ $rupiah($result['remaining']) }}</div></div>
        <div class="is-card"><div class="is-card-label">Rata-rata per Invoice</div><div class="is-card-value">{{ $rupiah($result['average']) }}</div></div>
    </div>

    <x-filament::section heading="Per Periode">
        @if (count($result['periods']) === 0)
            <p class="is-note">Belum ada invoice terbit pada rentang ini.</p>
        @else
            <div class="is-scroll">
                <table class="is-table">
                    <thead><tr><th>Periode</th><th class="is-num">Invoice</th><th class="is-num">Penjualan</th><th class="is-num">Terbayar</th><th class="is-num">Sisa</th></tr></thead>
                    <tbody>
                        @foreach ($result['periods'] as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td class="is-num">{{ number_format($row['count'], 0, ',', '.') }}</td>
                                <td class="is-num">{{ $rupiah($row['total']) }}</td>
                                <td class="is-num">{{ $rupiah($row['paid']) }}</td>
                                <td class="is-num">{{ $rupiah($row['total'] - $row['paid']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="is-num">{{ number_format($result['count'], 0, ',', '.') }}</td>
                            <td class="is-num">{{ $rupiah($result['total']) }}</td>
                            <td class="is-num">{{ $rupiah($result['paid']) }}</td>
                            <td class="is-num">{{ $rupiah($result['remaining']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-filament::section>

    @if ($this->isFullAccess() && blank($this->storeId) && count($result['stores']) > 0)
        <x-filament::section heading="Per Toko">
            <div class="is-scroll">
                <table class="is-table">
                    <thead><tr><th>Toko</th><th class="is-num">Invoice</th><th class="is-num">Penjualan</th><th class="is-num">Terbayar</th></tr></thead>
                    <tbody>
                        @foreach ($result['stores'] as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td class="is-num">{{ number_format($row['count'], 0, ',', '.') }}</td>
                                <td class="is-num">{{ $rupiah($row['total']) }}</td>
                                <td class="is-num">{{ $rupiah($row['paid']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Daftar Invoice">
        @if ($result['invoices']->isEmpty())
            <p class="is-note">Tidak ada invoice pada rentang ini.</p>
        @else
            <div class="is-scroll">
                <table class="is-table">
                    <thead><tr><th>No. Invoice</th><th>Tanggal Terbit</th><th>Pelanggan</th><th>Status</th><th class="is-num">Total</th><th class="is-num">Terbayar</th><th class="is-num">Sisa</th></tr></thead>
                    <tbody>
                        @foreach ($result['invoices'] as $inv)
                            <tr>
                                <td><a href="{{ \App\Filament\Resources\InvoiceResource::getUrl('view', ['record' => $inv->id]) }}" style="text-decoration: underline;">{{ $inv->invoice_number }}</a></td>
                                <td>{{ $inv->issue_date->translatedFormat('d M Y') }}</td>
                                <td>{{ $inv->customer_name }}</td>
                                <td><span class="{{ $inv->status === 'paid' ? 'is-paid' : 'is-unpaid' }}">{{ \App\Models\Invoice::STATUS_LABELS[$inv->status] ?? $inv->status }}</span></td>
                                <td class="is-num">{{ $rupiah($inv->total) }}</td>
                                <td class="is-num">{{ $rupiah($inv->amount_paid) }}</td>
                                <td class="is-num">{{ $rupiah($inv->remainingAmount()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($result['listTruncated'])
                <p class="is-note" style="margin-top: 8px;">Menampilkan 200 invoice terbaru. Persempit rentang tanggal untuk melihat sisanya.</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
